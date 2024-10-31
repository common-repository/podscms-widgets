<?php
/**
 * @package Pods Widgets
 */
/*
Plugin Name: Pods Widgets
Plugin URI: http://www.mikevanwinkle.com
Description: Output any pods template into a sidebar widget.
Version: 1.2
Author: Mike Van Winkle
Author URI: http://www.mikevanwinkle.com
License: GPLv2
*/

define('PODS_WIDGET_JS', plugins_url() . '/podscms-widgets/podswidgets.jquery.js');

function pods_validate() {
	
	$missing = array();
        if(!function_exists("pod_query"))
        {
            $missing[] = 'Pods';
        }
        if(!empty($missing))
        {
            die('<strong>Fatal Error:</strong> Missing required plugin(s): '.@implode(',',$missing));
        } else {
	        return true;
        }
 }


add_action('wp_ajax_ajax_pod_fields','ajax_pod_fields');
add_action('init','pods_widget_script');
register_activation_hook( __FILE__ ,'pods_validate');
add_action('widgets_init','load_pods_widgets');


function load_pods_widgets() {
	register_widget('PodsWidget');	
}


function pods_widget_script() {
	//wp_register_script( 'podswidgets' , plugins_url() . '/podscms-widgets/podswidgets.jquery.js' );
	//wp_enqueue_script('podswidgets');
	//wp_register_script( 'podstest' , plugins_url() . '/podscms-widgets/test.js' );
	//wp_enqueue_script('podstest');
}
function ajax_pod_fields() {
	global $wpdb;
	$datatype = $_POST['datatype'];
	$result = get_pickvals($datatype);
	$response = json_encode($result);
	header( "Content-Type: application/json" );
	echo $response;
	exit;
}

function get_pickvals($field) {
	global $wpdb;
	if($field == 'wp_post' || $field == 'wp_page') {
		$query = $wpdb->prepare("
		SELECT DISTINCT column_name FROM information_schema.columns WHERE table_name = '$wpdb->posts'
		");
		$columns = $wpdb->get_col($query);
		$result = array();
		foreach($columns as $col) {
			$result[] = (object) array('name' => $col);
		}
	} elseif($field == 'wp_taxonomy') {
		$query = $wpdb->prepare("
			SELECT DISTINCT column_name FROM information_schema.columns WHERE table_name = '$wpdb->terms'
			");
		$columns = $wpdb->get_col($query);
		$result = array();
		foreach($columns as $col) {
			$result[] = (object) array('name' => $col);
		}
	} else {
		$query = $wpdb->prepare("
			SELECT * FROM {$wpdb->prefix}pod_fields WHERE datatype = 
			(SELECT id FROM {$wpdb->prefix}pod_types WHERE name = '%s')
			", $field);
		$result = $wpdb->get_results($query);
	}
	return $result;
}

function build_pickvals($field, $name, $old) {
	$result = get_pickvals($field);
	$out = '';
	foreach($result as $val):
		$var = $name .'.'.$val->name;
		$sel = ($old == $var) ? 'selected' : '';
		$out .= '<option value="'.$name .'.' .$val->name .'" '.$sel.'>'.$name .'.' .$val->name .'</option>';
	endforeach;
	return $out;
}

/*
**
** The Widget Code
**
*/

class PodsWidget extends WP_Widget {
    function PodsWidget() {
        $widget_ops = array( 'classname' => 'pods', 'description' => __( 'Display pods data in any sidebar.', 'pods-widget' ) );
		$control_ops = array( 'width' => 610, 'height' => 350, 'id_base' => 'pods-widget' );
		$this->WP_Widget( 'pods-widget', __( 'Pods Widget', 'pods-widget' ), $widget_ops, $control_ops );	
    }

    function widget($args, $instance) {		
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
          $template = $instance['template'];
          $pod_id = $instance['pod_id'];
          $pod_slug = $instance['pod_slug'];
          $podtype = $instance['podtype'];
          $where = $instance['where']; 
          $order = $instance['order'];
          $orderby = $instance['orderby'];
          	      if(!$order) { $order = 'id DESC'; }
          $show = $instance['show'];
        ?>
              <?php echo $before_widget; ?>
                  <?php if ( $title )
                        echo $before_title . $title . $after_title; ?>
					<?php 
					if(!$template || !$podtype) {
						echo '<p>In order for this widget to work a Pod type and template must be selected.</p>';
					} else {
						if($pod_slug || $pod_id) {
							$pod = (!empty($pod_id)) ? $pod_id : $pod_slug;
							$new = new Pod($podtype, $pod);
						} else {
						
							$new = new Pod($podtype);
							// set defaults
							$orderby = (empty($orderby)) ? 'id' : $orderby;
							$order = (empty($order)) ? 'DESC' : $order;
							$show = (empty($show)) ? '5' : $show;
							$where = (empty($where)) ? "t.name IS NOT NULL" : $where;
							$params = array(
								'orderby'=>$orderby,
								'limit'=>$show,
								'where'=>$where,
								'search'=>false,
								'page'=>1
								); 
							$params = pods_sanitize($params);
							$new->findRecords($params);
						}
						echo $new->showTemplate($template); 
					}
					?>
				<?php echo $after_widget; ?>
        <?php
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {			
		$instance = $old_instance;
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['podtype'] = $new_instance['podtype'];
		$instance['template'] = $new_instance['template'];
		$instance['where'] = $new_instance['where'];
		$instance['order'] = $new_instance['order'];
		$instance['orderby'] = $new_instance['orderby'];
		$instance['pod_id'] = $new_instance['pod_id'];
		$instance['pod_slug'] = $new_instance['pod_slug'];
		$instance['show'] = $new_instance['show'];
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {				
        ?>
        <style>
        	.form-item {margin:5px 0;}
        	.message {background: #f7f7f7; padding: 5px; }
        	label {text-transform: uppercase; margin: 0 0 5px 0; font-size:10px; font-weight: bold; color: #606060; width: 100%;}
        </style>
        <script>
			jQuery.noConflict();
			
			jQuery(document).ready(function() {
			var wrap = jQuery('#form-wrap-<?php echo $this->number; ?>');
			var output = wrap.find('div#messages');
			var col2 = wrap.find('#col-2');
			var col3 = wrap.find('#col-3');
			var orderBy = wrap.find('.orderby');
			var pod_id = wrap.find('#pod_id input');
			var pod_slug = wrap.find('#pod_slug input');
			var selectID = wrap.find('select.orderby').attr('id');
			var selectOrderby = wrap.find('select.orderby');
			var selectName = wrap.find('select.orderby').attr('name'); 
			var num = wrap.find('#instance_num').attr('value');
			
			function disableCol2() {
				col2.css({opacity:0.5})
				col2.find('input,select').attr('disabled',true);
			}
			
			function disableID() {
				wrap.find('#pod_id').css({opacity:0.5});
				wrap.find('#pod_id input').attr('disabled',true);
			}
			
			function disableSlug() {
				wrap.find('#pod_slug').css({opacity:0.5});
				wrap.find('#pod_slug input').attr('disabled',true);
			}
			
			function enableAll() {
				col2.css({opacity:1})
				col2.find('input,select').attr('disabled',false);
				wrap.find('#pod_id input,#pod_slug input').attr('disabled',false);
				wrap.find('#pod_id,#pod_slug').css({opacity:1});
			}
			
			if(pod_id.val().length > 0) {
				disableSlug(wrap);
				disableCol2(col2);
			} else if(pod_slug.val().length > 0) {
				disableID(wrap);
				disableCol2(col2); 
			} else {
				enableAll(wrap,col2);
			}

				 	
			wrap.find('#pod_id input,#pod_slug input').keyup(function() { 
				var num = jQuery(this).val().length;
				var divId = jQuery(this).parent().attr('id');
					if(num > 0) {
						if(divId == 'pod_slug') {
							disableID();
							disableCol2();
						} else {
							disableSlug();
							disableCol2();
						}
						
					} else {
						enableAll();
					}
				});
				
			wrap.find('select.pod-select').change(function() {
			var dataType = wrap.find('option:selected').val();
			jQuery.post(ajaxurl,{
				action:'ajax_pod_fields',
				datatype:dataType
				}, function(data) {
					var content = '';
					var picks = '';
					content += '<label for="orderby">Order by</label><br/><select name="'+selectName+'" id="'+selectID+'">';
						for(i = 0; i < data.length; i++) {
							if(data[i]['coltype'] != 'pick') {
							content += '<option value="'+data[i]['name']+'">'+data[i]['name']+'</option>';
							} else {
								output.html('<div class="message">Once you save the widget you will be able to select pick field options in the orderby menu</dvi>');
							}
						}
					content += '</select>';
					orderBy.html(content);
				});
			});
			
			wrap.next('.savewidget').attr('disabled','disabled');
			
			
			//set up error counters
			t_err = 0;
			p_err = 0;
			
			wrap.mouseout(function() {
				var template = wrap.find('#pod_template option:selected');
				var pod_type = wrap.find('#pod_type option:selected');
			
				
				// display template warning
				if(template.val().length < 1) {
					if(t_err < 1) {
						output.append('<div id="template_error" class="error">Please select a template for this widget.</dvi>');	
						t_err = 1;
					}
				} else {
					output.find('#template_error').remove();
					t_err = 0;
				}
				
				//display pod_id warning.
				if(pod_type.val().length < 1) {
					if(p_err < 1) {
						output.append('<div id="pod_type_error" class="error">Please select a pod type for this widget.</div>');	
					p_err = 1;
					}
				} else {
					output.find('#pod_type_error').remove();
					p_err = 0;
				}
				
			});
			
			});

		</script>
		 <div id="form-wrap-<?php echo $this->number; ?>" style="width:600px;" class="">
        <?php
        $title = esc_attr($instance['title']);
        $podtype = esc_attr($instance['podtype']);
       	$template = esc_attr($instance['template']);	
       	$where = esc_attr($instance['where']);
       	$order = esc_attr($instance['order']);
       	$orderby = esc_attr($instance['orderby']);
       	$pod_id = esc_attr($instance['pod_id']);
       	$pod_slug = esc_attr($instance['pod_slug']);
       	$show = $instance['show'];
       	?>

		<div id="col-1" style="width:30%;float:left;margin-right:15px;">
		<div class="form-item">
		<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label><br/>
		<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
		</div>
		
		<div id="pod_type" class="form-item">
		<label for="<?php echo $this->get_field_id('podtype'); ?>" style="width:50px;"><?php _e('Pod:'); ?></label><br/>     	
		<select id="<?php echo $this->get_field_id('podtype'); ?>" class="select pod-select" name="<?php echo $this->get_field_name('podtype'); ?>">
		<option value="">Select Pod ... </option>
		<?php
		global $wpdb;
		$table = $wpdb->prefix . 'pod_types';
		$temp = pod_query("SELECT name FROM $table");
		while($name = mysql_fetch_assoc($temp)) { ?>	
		<?php foreach($name as $k => $v) { 
				$sel = ($podtype == $v) ? 'selected': '' ;
				?>
			<option value="<?php echo $v; ?>" <?php echo $sel; ?>><?php echo $v; ?></option>	
			<?php } ?>
		<?php } ?>
		</select>
		</div>
		
		<div id="pod_id" class="form-item">
		<div id="output"></div>
		<label for="pod_id">Pod ID</label><br/>
		<input class="widefat" id="<?php echo $this->get_field_id('pod_id'); ?>" name="<?php echo $this->get_field_name('pod_id'); ?>" type="text" value="<?php echo $pod_id; ?>" style="width:40px;" />
		</div>
		
		<div id="pod_slug" class="form-item">
		<label for="pod_id">Slug</label><br/>
		<input class="widefat" id="<?php echo $this->get_field_id('pod_slug'); ?>" name="<?php echo $this->get_field_name('pod_slug'); ?>" type="text" value="<?php echo $pod_slug; ?>" style="width:80px;" />
		</div>
		
		
		<div id="pod_template" class="form-item">
		<label for="<?php echo $this->get_field_id('template'); ?>" style="width:50px;"><?php _e('Template:'); ?><br/>
		<select id="<?php echo $this->get_field_id('template'); ?>" class="select" name="<?php echo $this->get_field_name('template'); ?>">
		<option value="">Select Template ... </option>
		<?php
		global $wpdb;
		$table = $wpdb->prefix . 'pod_templates';
		$temp = pod_query("SELECT name FROM $table");
		while($name = mysql_fetch_assoc($temp)) { ?>	
			<?php foreach($name as $k => $v) { 
			$sel = ($template == $v) ? 'selected': '' ;
			?>
			<option value="<?php echo $v; ?>" <?php echo $sel; ?>><?php echo $v; ?></option>	
			<?php } ?>
		<?php } ?>
		</select>
		</label>
		</div>
		
		</div>
		
		<div id="col-2" style="width:30%;float:left;margin-right:15px;">                     
		<!-- WHERE -->
		
		<div id="where-raw" class="form-item">
		<label for="<?php echo $this->get_field_id('where'); ?>"><?php _e('Find Records: '); ?></label><br>
		<input class="widefat" id="<?php echo $this->get_field_id('where'); ?>" name="<?php echo $this->get_field_name('where'); ?>" type="text" value="<?php echo $where; ?>" />
		</div>
		
		<!-- LIMIT -->
		<div class="form-item">
		<label for="<?php echo $this->get_field_id('show'); ?>"><?php _e('How many listings to show'); ?></label><br>
		<input class="widefat" id="<?php echo $this->get_field_id('show'); ?>" name="<?php echo $this->get_field_name('show'); ?>" type="text" value="<?php echo $show; ?>" style="width:40px;"/>
		</div>
		
		<!-- Order By -->
		<div class="form-item orderby">
		<label for="orderby">Order by</label><br/>
		<?php if(isset($podtype) && $podtype != '') { ?>
		<select id="<?php echo $this->get_field_id('orderby'); ?>" class="select orderby" name="<?php echo $this->get_field_name('orderby'); ?>">
		
		<?php 
		global $wpdb;
		$query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}pod_fields WHERE datatype = (SELECT id FROM {$wpdb->prefix}pod_types WHERE name = '%s')", $podtype);
		$result = $wpdb->get_results($query);
		foreach($result as $pod) { ?>
		<?php if($pod->coltype != 'pick') { ?>
			<?php $sel = ($pod->name == $orderby) ? 'selected' : '' ; ?>
			<option value="<?= $pod->name; ?>" <?= $sel; ?>><?= $pod->name; ?></option>
		<?php } else { ?>
			<?php echo build_pickvals($pod->pickval,$pod->name, $orderby); ?>
		<?php } ?> 
		<?php } //endforeach ?>
		</select>
		<?php } else { ?>			
		<p>Please select a pod type first. </p>
		<?php } ?>
		
		</div>
		
		<!-- Order -->
		<div class="form-item">
		<label for="<?php echo $this->get_field_id('order'); ?>"><?php _e('Order: '); ?></label><br>
		<select name="<?php echo $this->get_field_name('order'); ?>" class="widefat" >
		<option value="ASC" <?php if($order == 'ASC') { echo 'selected'; } ?>>ASC</option>
		<option value="DESC" <?php if($order == 'DESC') { echo 'selected'; } ?>>DESC</option>
		</select>
		</div>
		
		</div>
		
		<div id="col-3" style="width:30%;float:left;margin-right:15px;">  
		<label>Other options</label>
		<p>Additional options will show up here when available.</p>
		<div id="messages"></div>
		<input type="hidden" name="instance_num" id="instance_num" value="<?php echo $this->number; ?>" />
		</div>
		
		<div style="clear:both;"></div>
		</div>
        <?php 
    }
} // class PodsRelatedWidget


