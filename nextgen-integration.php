<?php 
class ewwwngg {
	/* initializes the nextgen integration functions */
	function ewwwngg() {
		add_filter('ngg_manage_images_columns', array(&$this, 'ewww_manage_images_columns'));
		add_action('ngg_manage_image_custom_column', array(&$this, 'ewww_manage_image_custom_column'), 10, 2);
		add_action('ngg_added_new_image', array(&$this, 'ewww_added_new_image'));
		add_action('admin_action_ewww_ngg_manual', array(&$this, 'ewww_ngg_manual'));
		add_action('admin_menu', array(&$this, 'ewww_ngg_bulk_menu'));
		$i18ngg = strtolower  ( _n( 'Gallery', 'Galleries', 1, 'nggallery' ) );
		add_action('admin_head-' . $i18ngg . '_page_nggallery-manage-gallery', array(&$this, 'ewww_ngg_bulk_actions_script'));
		add_action('admin_enqueue_scripts', array(&$this, 'ewww_ngg_bulk_script'));
		add_action('wp_ajax_bulk_ngg_preview', array(&$this, 'ewww_ngg_bulk_preview'));
		add_action('wp_ajax_bulk_ngg_init', array(&$this, 'ewww_ngg_bulk_init'));
		add_action('wp_ajax_bulk_ngg_filename', array(&$this, 'ewww_ngg_bulk_filename'));
		add_action('wp_ajax_bulk_ngg_loop', array(&$this, 'ewww_ngg_bulk_loop'));
		add_action('wp_ajax_bulk_ngg_cleanup', array(&$this, 'ewww_ngg_bulk_cleanup'));
		add_action('wp_ajax_ewww_ngg_thumbs', array(&$this, 'ewww_ngg_thumbs_only'));
		add_action('ngg_after_new_images_added', array(&$this, 'ewww_ngg_new_thumbs'), 10, 2);
		register_setting('ewww_image_optimizer_options', 'ewww_image_optimizer_bulk_ngg_resume');
		register_setting('ewww_image_optimizer_options', 'ewww_image_optimizer_bulk_ngg_attachments');
	}

	/* adds the Bulk Optimize page to the tools menu, and a hidden page for optimizing thumbnails */
	function ewww_ngg_bulk_menu () {
			add_submenu_page(NGGFOLDER, 'NextGEN Bulk Optimize', 'Bulk Optimize', 'NextGEN Manage gallery', 'ewww-ngg-bulk', array (&$this, 'ewww_ngg_bulk_preview'));
			$hook = add_submenu_page(null, 'NextGEN Bulk Thumbnail Optimize', 'Bulk Thumbnail Optimize', 'NextGEN Manage gallery', 'ewww-ngg-thumb-bulk', array (&$this, 'ewww_ngg_thumb_bulk'));
	}

	/* ngg_added_new_image hook */
	function ewww_added_new_image ($image) {
		// query the filesystem path of the gallery from the database
		global $wpdb;
		$q = $wpdb->prepare( "SELECT path FROM {$wpdb->prefix}ngg_gallery WHERE gid = %d LIMIT 1", $image['galleryID'] );
		$gallery_path = $wpdb->get_var($q);
		// if we have a path to work with
		if ( $gallery_path ) {
			// TODO: optimize thumbs automatically 
			// construct the absolute path of the current image
			$file_path = trailingslashit($gallery_path) . $image['filename'];
			// run the optimizer on the current image
			$res = ewww_image_optimizer(ABSPATH . $file_path, 2, false, false);
			// update the metadata for the optimized image
			nggdb::update_image_meta($image['id'], array('ewww_image_optimizer' => $res[1]));
		}
	}

	/* output a small html form so that the user can optimize thumbs for the $images just added */
	function ewww_ngg_new_thumbs($gid, $images) {
		// store the gallery id, seems to help avoid errors
		$gallery = $gid;
		// prepare the $images array for POSTing
		$images = serialize($images); ?>
                <div id="bulk-forms"><p>The thumbnails for your new images have not been optimized. If you would like this step to be automatic in the future, bug the NextGEN developers to add in a hook.</p>
                <form id="thumb-optimize" method="post" action="admin.php?page=ewww-ngg-thumb-bulk">
			<?php wp_nonce_field( 'ewww-image-optimizer-bulk', '_wpnonce'); ?>
			<input type="hidden" name="attachments" value="<?php echo $images; ?>">
                        <input type="submit" class="button-secondary action" value="Optimize Thumbs" />
                </form> 
<?php	}

	/* optimize the thumbs of the images POSTed from the previous page */
	function ewww_ngg_thumb_bulk() {
		if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Cheatin&#8217; eh?' ) );
		}?> 
		<div class="wrap">
                <div id="icon-upload" class="icon32"></div><h2>Bulk Thumbnail Optimize</h2>
<?php		$images = unserialize ($_POST['attachments']);
		// initialize $current, and $started time
		$started = time();
		$current = 0;
		// find out how many images we have
		$total = sizeof($images);
		// flush the output buffers
		ob_implicit_flush(true);
		ob_end_flush();
		// process each image
		foreach ($images as $id) {
			// give each image 50 seconds (php only, doesn't include any commands issued by exec()
			set_time_limit (50);
			$current++;
			echo "<p>Processing $current/$total: ";
			// get the metadata
			$meta = new nggMeta( $id );
			// output the current image name
			printf( "<strong>%s</strong>&hellip;<br>", esc_html($meta->image->filename) );
			// get the filepath of the thumbnail image
			$thumb_path = $meta->image->thumbPath;
			// run the optimization on the thumbnail
			$tres = ewww_image_optimizer($thumb_path, 2, false, true);
			// output the results of the thumb optimization
			printf( "Thumbnail – %s<br>", $tres[1] );
			// outupt how much time we've spent optimizing so far
			$elapsed = time() - $started;
			echo "Elapsed: $elapsed seconds</p>";
			// flush the HTML output buffers
			@ob_flush();
			flush();
		}
		// all done here
		echo '<p><b>Finished</b></p></div>';	
	}

	/* Manually process an image from the NextGEN Gallery */
	function ewww_ngg_manual() {
		// check permission of current user
		if ( FALSE === current_user_can('upload_files') ) {
			wp_die(__('You don\'t have permission to work with uploaded files.', EWWW_IMAGE_OPTIMIZER_DOMAIN));
		}
		// make sure function wasn't called without an attachment to work with
		if ( FALSE === isset($_GET['attachment_ID'])) {
			wp_die(__('No attachment ID was provided.', EWWW_IMAGE_OPTIMIZER_DOMAIN));
		}
		// store the attachment $id
		$id = intval($_GET['attachment_ID']);
		// retrieve the metadata for the image
		$meta = new nggMeta( $id );
		// retrieve the image path
		$file_path = $meta->image->imagePath;
		// run the optimizer on the current image
		$res = ewww_image_optimizer($file_path, 2, false, false);
		// update the metadata for the optimized image
		nggdb::update_image_meta($id, array('ewww_image_optimizer' => $res[1]));
		// get the filepath of the thumbnail image
		$thumb_path = $meta->image->thumbPath;
		// run the optimization on the thumbnail
		ewww_image_optimizer($thumb_path, 2, false, true);
		// get the referring page, and send the user back there
		$sendback = wp_get_referer();
		$sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
		wp_redirect($sendback);
		exit(0);
	}

	/* ngg_manage_images_columns hook */
	function ewww_manage_images_columns( $columns ) {
		$columns['ewww_image_optimizer'] = 'Image Optimizer';
		return $columns;
	}

	/* ngg_manage_image_custom_column hook */
	function ewww_manage_image_custom_column( $column_name, $id ) {
		// once we've found our custom column
		if( $column_name == 'ewww_image_optimizer' ) {    
			// get the metadata for the image
			$meta = new nggMeta( $id );
			// get the optimization status for the image
			$status = $meta->get_META( 'ewww_image_optimizer' );
			$msg = '';
			// get the file path of the image
			$file_path = $meta->image->imagePath;
			// get the mimetype of the image
			$type = ewww_image_optimizer_mimetype($file_path, 'i');
			// retrieve the human-readable filesize of the image
			$file_size = ewww_image_optimizer_format_bytes(filesize($file_path));
			$valid = true;
			// check to see if we have a tool to handle the mimetype detected
	                switch($type) {
        	                case 'image/jpeg':
					// if jpegtran is missing, tell the user
                	                if(EWWW_IMAGE_OPTIMIZER_JPEGTRAN == false) {
                        	                $valid = false;
	     	                                $msg = '<br>' . __('<em>jpegtran</em> is missing');
	                                }
					break;
				case 'image/png':
					// if the PNG tools are missing, tell the user
					if(EWWW_IMAGE_OPTIMIZER_PNGOUT == false && EWWW_IMAGE_OPTIMIZER_OPTIPNG == false) {
						$valid = false;
						$msg = '<br>' . __('<em>optipng/pngout</em> is missing');
					}
					break;
				case 'image/gif':
					// if gifsicle is missing, tell the user
					if(EWWW_IMAGE_OPTIMIZER_GIFSICLE == false) {
						$valid = false;
						$msg = '<br>' . __('<em>gifsicle</em> is missing');
					}
					break;
				default:
					$valid = false;
			}
			// file isn't in a format we can work with, we don't work with strangers
			if($valid == false) {
				print __('Unsupported file type', EWWW_IMAGE_OPTIMIZER_DOMAIN) . $msg;
				return;
			}
			// if we have a valid status, display it, the image size, and give a re-optimize link
			if ( $status && !empty( $status ) ) {
				echo $status;
				print "<br>Image Size: $file_size";
				printf("<br><a href=\"admin.php?action=ewww_ngg_manual&amp;attachment_ID=%d\">%s</a>",
				$id,
				__('Re-optimize', EWWW_IMAGE_OPTIMIZER_DOMAIN));
			// otherwise, give the image size, and a link to optimize right now
			} else {
				print __('Not processed', EWWW_IMAGE_OPTIMIZER_DOMAIN);
				print "<br>Image Size: $file_size";
				printf("<br><a href=\"admin.php?action=ewww_ngg_manual&amp;attachment_ID=%d\">%s</a>",
				$id,
				__('Optimize now!', EWWW_IMAGE_OPTIMIZER_DOMAIN));
			}
		}
	}

	/* output the html for the bulk optimize page */
	function ewww_ngg_bulk_preview() {
		if (!empty($_POST['doaction'])) {
                        // if there is no requested bulk action, do nothing
                        if (empty($_REQUEST['bulkaction'])) {
                                return;
                        }
                        // if there is no media to optimize, do nothing
                        if (empty($_REQUEST['doaction']) || !is_array($_REQUEST['doaction'])) {
                              return;
                        }
                }
		// retrieve the attachments array from the db
                $attachments = get_option('ewww_image_optimizer_bulk_ngg_attachments');
		// make sure there are some attachments to process
                if (count($attachments) < 1) {
                        echo '<p>You don’t appear to have uploaded any images yet.</p>';
                        return;
                }
                ?>
		<div class="wrap">
                <div id="icon-upload" class="icon32"></div><h2>NextGEN Gallery Bulk Optimize</h2>
                <?php
                // Retrieve the value of the 'bulk resume' option and set the button text for the form to use
                $resume = get_option('ewww_image_optimizer_bulk_ngg_resume');
                if (empty($resume)) {
                        $button_text = 'Start optimizing';
                } else {
                        $button_text = 'Resume previous bulk operation';
                }
                ?>
                <div id="bulk-loading"></div>
                <div id="bulk-progressbar"></div>
                <div id="bulk-counter"></div>
                <div id="bulk-status"></div>
                <div id="bulk-forms"><p>This tool can optimize large batches (or all) of images from your media library.</p>
                <p>We have <?php echo count($attachments); ?> images to optimize.</p>
                <form id="bulk-start" method="post" action="">
                        <input type="submit" class="button-secondary action" value="<?php echo $button_text; ?>" />
                </form>
                <?php
		// if there is a previous bulk operation to resume, give the user the option to reset the resume flag
                if (!empty($resume)) { ?>
                        <p>If you would like to start over again, press the <b>Reset Status</b> button to reset the bulk operation status.</p>
                        <form id="bulk-reset" method="post" action="">
                                <?php wp_nonce_field( 'ewww-image-optimizer-bulk-reset', '_wpnonce'); ?>
                                <input type="hidden" name="reset" value="1">
                                <input type="submit" class="button-secondary action" value="Reset Status" />
                        </form>
<?php           }
	        echo '</div></div>';
		return;
	}

	/* prepares the javascript for a bulk operation */
	function ewww_ngg_bulk_script($hook) {
		$i18ngg = strtolower  ( _n( 'Gallery', 'Galleries', 1, 'nggallery' ) );
		// make sure we are on a legitimate page and that we have the proper POST variables if necessary
		if ($hook != $i18ngg . '_page_ewww-ngg-bulk' && $hook != $i18ngg . '_page_nggallery-manage-gallery')
				return;
		if ($hook == $i18ngg . '_page_nggallery-manage-gallery' && empty($_REQUEST['bulkaction']))
				return;
		if ($hook == $i18ngg . '_page_nggallery-manage-gallery' && (empty($_REQUEST['doaction']) || !is_array($_REQUEST['doaction'])))
				return;
		$images = null;
		// see if the user wants to reset the previous bulk status
		if (!empty($_REQUEST['reset']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk-reset'))
			update_option('ewww_image_optimizer_bulk_ngg_resume', '');
		// see if there is a previous operation to resume
		$resume = get_option('ewww_image_optimizer_bulk_ngg_resume');
		// if we've been given a bulk action to perform
		if (!empty($_REQUEST['doaction'])) {
			// if we are optimizing a specific group of images
			if ($_REQUEST['page'] == 'manage-images' && $_REQUEST['bulkaction'] == 'bulk_optimize') {
				check_admin_referer('ngg_updategallery');
				// reset the resume status, not allowed here
				update_option('ewww_image_optimizer_bulk_ngg_resume', '');
				// retrieve the image IDs from POST
				$images = array_map( 'intval', $_REQUEST['doaction']);
			}
			// if we are optimizing a specific group of galleries
			if ($_REQUEST['page'] == 'manage-galleries' && $_REQUEST['bulkaction'] == 'bulk_optimize') {
				check_admin_referer('ngg_bulkgallery');
				global $nggdb;
				// reset the resume status, not allowed here
				update_option('ewww_image_optimizer_bulk_ngg_resume', '');
				$ids = array();
				// for each gallery we are given
				foreach ($_REQUEST['doaction'] as $gid) {
					// get a list of IDs
					$gallery_list = $nggdb->get_gallery($gid);
					// for each ID
					foreach ($gallery_list as $image) {
						// add it to the array
						$images[] = $image->pid;
					}
				}
			}
		// otherwise, if we have an operation to resume
		} elseif (!empty($resume)) {
			// get the list of attachment IDs from the db
			$images = get_option('ewww_image_optimizer_bulk_ngg_attachments');
		// otherwise, if we are on the standard bulk page, get all the images in the db
		} elseif ($hook == $i18ngg . '_page_ewww-ngg-bulk') {
			global $wpdb;
			$images = $wpdb->get_col("SELECT pid FROM $wpdb->nggpictures ORDER BY sortorder ASC");
		}
		// store the image IDs to process in the db
		update_option('ewww_image_optimizer_bulk_ngg_attachments', $images);
		global $wp_version;
		$my_version = $wp_version;
		$my_version = substr($my_version, 0, 3);
		if ($my_version < 3) {
			// replace the default jquery script with an updated one
			wp_deregister_script('jquery');
			wp_register_script('jquery', plugins_url('/jquery-1.9.1.min.js', __FILE__), false, '1.9.1');
		}
		// add a custom jquery-ui script with progressbar functions
		wp_enqueue_script('ewwwjuiscript', plugins_url('/jquery-ui-1.10.2.custom.min.js', __FILE__), false);
		// add the EWWW IO script
		wp_enqueue_script('ewwwbulkscript', plugins_url('/eio.js', __FILE__), array('jquery'));
		// replacing the built-in nextgen styling rules for progressbar
		wp_register_style( 'ngg-jqueryui', plugins_url('jquery-ui-1.10.1.custom.css', __FILE__));
		// enqueue the progressbar styling
		wp_enqueue_style('ngg-jqueryui'); //, plugins_url('jquery-ui-1.10.1.custom.css', __FILE__));
		// prep the $images for use by javascript
		$images = json_encode($images);
		// include all the vars we need for javascript
		wp_localize_script('ewwwbulkscript', 'ewww_vars', array(
				'_wpnonce' => wp_create_nonce('ewww-image-optimizer-bulk'),
				'gallery' => 'nextgen',
				'attachments' => $images
			)
		);
	}

	/* start the bulk operation */
	function ewww_ngg_bulk_init() {
                if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
                        wp_die( __( 'Cheatin&#8217; eh?' ) );
                }
		// toggle the resume flag to indicate an operation is in progress
                update_option('ewww_image_optimizer_bulk_ngg_resume', 'true');
		// let the user know we are starting
                $loading_image = plugins_url('/wpspin.gif', __FILE__);
                echo "<p>Optimizing&nbsp;<img src='$loading_image' alt='loading'/></p>";
                die();
        }

	/* output the filename of the image being optimized */
	function ewww_ngg_bulk_filename() {
                if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
                        wp_die( __( 'Cheatin&#8217; eh?' ) );
                }
		// need this file to work with metadata
		require_once(WP_CONTENT_DIR . '/plugins/nextgen-gallery/lib/meta.php');
		$id = $_POST['attachment'];
		// get the meta for the image
		$meta = new nggMeta($id);
		$loading_image = plugins_url('/wpspin.gif', __FILE__);
		// get the filename for the image, and output our current status
		$file_name = esc_html($meta->image->filename);
		echo "<p>Optimizing... <b>" . $file_name . "</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
		die();
	}

	/* process each image in the bulk loop */
	function ewww_ngg_bulk_loop() {
                if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
                        wp_die( __( 'Cheatin&#8217; eh?' ) );
                }
		// need this file to work with metadata
		require_once(WP_CONTENT_DIR . '/plugins/nextgen-gallery/lib/meta.php');
		// find out what time we started, in microseconds
		$started = microtime(true);
		$id = $_POST['attachment'];
		// get the metadata
		$meta = new nggMeta($id);
		// retrieve the filepath
		$file_path = $meta->image->imagePath;
		// run the optimizer on the current image
		$fres = ewww_image_optimizer($file_path, 2, false, false);
		// update the metadata of the optimized image
		nggdb::update_image_meta($id, array('ewww_image_optimizer' => $fres[1]));
		// output the results of the optimization
		printf("<p>Optimized image: <strong>%s</strong><br>", $meta->image->filename);
		printf("Full size - %s<br>", $fres[1] );
		// get the filepath of the thumbnail image
		$thumb_path = $meta->image->thumbPath;
		// run the optimization on the thumbnail
		$tres = ewww_image_optimizer($thumb_path, 2, false, true);
		// output the results of the thumb optimization
		printf( "Thumbnail - %s<br>", $tres[1] );
		// outupt how much time we spent
		$elapsed = microtime(true) - $started;
		echo "Elapsed: " . round($elapsed, 3) . " seconds</p>";
		// get the list of attachments remaining from the db
		$attachments = get_option('ewww_image_optimizer_bulk_ngg_attachments');
		// remove the first item
		array_shift($attachments);
		// and store the list back in the db
		update_option('ewww_image_optimizer_bulk_ngg_attachments', $attachments);
		die();
	}

	/* finish the bulk operation */
	function ewww_ngg_bulk_cleanup() {
                if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
                        wp_die( __( 'Cheatin&#8217; eh?' ) );
                }
		// reset all the bulk options in the db
		update_option('ewww_image_optimizer_bulk_ngg_resume', '');
		update_option('ewww_image_optimizer_bulk_ngg_attachments', '');
		// and let the user know we are done
		echo '<p><b>Finished Optimization!</b></p>';
		die();
	}

	// insert a bulk optimize option in the actions list for the gallery and image management pages (via javascript, since we have no hooks)
	function ewww_ngg_bulk_actions_script() {?>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="bulkaction"] option:last-child').after('<option value="bulk_optimize">Bulk Optimize</option>');
			});
		</script>
<?php	}
}
// initialize the plugin and the class
add_action('init', 'ewwwngg');
//add_action('admin_print_scripts-tools_page_ewww-ngg-bulk', 'ewww_image_optimizer_scripts');

function ewwwngg() {
	global $ewwwngg;
	$ewwwngg = new ewwwngg();
}
