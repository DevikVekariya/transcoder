<?php /*

**************************************************************************

Retranscode media

The code and UI is borrowed from the following plugin (Author: Alex Mills).
https://wordpress.org/plugins/regenerate-thumbnails/
**************************************************************************/

class RetranscodeMedia {
	public $menu_id;

	public $api_key;

	public $stored_api_key;

	public $usage_info;

	// Functinallity initialization
	public function __construct() {

		$this->api_key			= get_site_option( 'rt-transcoding-api-key' );
		$this->stored_api_key	= get_site_option( 'rt-transcoding-api-key-stored' );

		$this->usage_info 		= get_site_option( 'rt-transcoding-usage' );

		// Do not activate re-transcoding without valid license key
		// Or usage are fully utilized
		if ( empty( $this->api_key ) ) {
			return;
		}
		if ( isset( $this->usage_info ) && is_array( $this->usage_info ) && array_key_exists( $this->api_key , $this->usage_info ) ) {
			if ( is_object( $this->usage_info[ $this->api_key ] ) && isset( $this->usage_info[ $this->api_key ]->status ) && $this->usage_info[ $this->api_key ]->status ) {
				if ( isset( $this->usage_info[ $this->api_key ]->remaining ) && $this->usage_info[ $this->api_key ]->remaining <= 0 ) {
					return;
				}
			}
		} else {
			return;
		}

		add_action( 'admin_menu',                       	array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts',               	array( $this, 'admin_enqueues' ) );
		add_action( 'wp_ajax_retranscodemedia',        		array( $this, 'ajax_process_retranscode_request' ) );
		add_filter( 'media_row_actions',                   	array( $this, 'add_media_row_action' ), 10, 2 );
		add_action( 'admin_head-upload.php',              	array( $this, 'add_bulk_actions_via_javascript' ) );
		add_action( 'admin_action_bulk_retranscode_media', 	array( $this, 'bulk_action_handler' ) ); // Top drowndown
		add_action( 'admin_action_-1',                     	array( $this, 'bulk_action_handler' ) ); // Bottom dropdown (assumes top dropdown = default value)
		add_action( 'rtt_before_thumbnail_store', 			array( $this, 'rtt_before_thumbnail_store' ), 10, 2 ); // Delete old thumbs
		add_action( 'rtt_before_transcoded_media_store', 	array( $this, 'rtt_before_transcoded_media_store' ), 10, 2 ); // Delete old transcoded files
		add_action( 'transcoded_thumbnails_added', 			array( $this, 'transcoded_thumbnails_added' ), 10, 1 ); // Add the current thumbnail to the newly added thumbnails
		add_action( 'rtt_handle_callback_finished', 		array( $this, 'rtt_handle_callback_finished' ), 10, 2 ); // Clean the extra meta that has been added while sending retranscoding request

		// Allow people to change what capability is required to use this feature
		$this->capability = apply_filters( 'retranscode_media_cap', 'manage_options' );
	}


	// Register the management page
	public function add_admin_menu() {
		add_submenu_page(
			'rt-transcoder',
			'Transcoder',
			'Settings',
		    'manage_options',
		    'rt-transcoder',
		    array( $this, '_transcoder_settings_page' )
		);
		$this->menu_id = add_submenu_page(
			'rt-transcoder',
			__( 'Retranscode Media' , 'transcoder'),
			__( 'Retranscode Media', 'transcoder' ),
		    $this->capability,
		    'rt-retranscoder',
			array($this, 'retranscode_interface')
		);
	}

	public function _transcoder_settings_page(){
		include_once( RT_TRANSCODER_PATH . 'admin/partials/rt-transcoder-admin-display.php' );
	}

	// Enqueue the needed Javascript and CSS
	public function admin_enqueues( $hook_suffix ) {
		if ( $hook_suffix != $this->menu_id )
			return;

		// WordPress 3.1 vs older version compatibility
		if ( wp_script_is( 'jquery-ui-widget', 'registered' ) )
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'js/jquery.ui.progressbar.min.js', __FILE__ ), array( 'jquery-ui-core', 'jquery-ui-widget' ), '1.8.6' );
		else
			wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'js/jquery.ui.progressbar.min.1.7.2.js', __FILE__ ), array( 'jquery-ui-core' ), '1.7.2' );

		wp_enqueue_style( 'jquery-ui-retranscodemedia', plugins_url( 'css/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}


	// Add a "Retranscode Media" link to the media row actions
	public function add_media_row_action( $actions, $post ) {
		if ( ( 'audio/' != substr( $post->post_mime_type, 0, 6 ) && 'video/' != substr( $post->post_mime_type, 0, 6 ) ) || 'audio/mpeg' === $post->post_mime_type || ! current_user_can( $this->capability ) )
			return $actions;

		$url = wp_nonce_url( admin_url( 'admin.php?page=rt-retranscoder&goback=1&ids=' . $post->ID ), 'rt-retranscoder' );
		$actions['retranscode_media'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( __( "Retranscode this single media", 'transcoder' ) ) . '">' . __( 'Retranscode Media', 'transcoder' ) . '</a>';

		return $actions;
	}


	// Add "Retranscode Media" to the Bulk Actions media dropdown
	public function add_bulk_actions( $actions ) {
		$delete = false;
		if ( ! empty( $actions['delete'] ) ) {
			$delete = $actions['delete'];
			unset( $actions['delete'] );
		}

		$actions['bulk_retranscode_media'] = __( 'Retranscode Media', 'transcoder' );

		if ( $delete )
			$actions['delete'] = $delete;

		return $actions;
	}


	// Add new items to the Bulk Actions using Javascript
	// A last minute change to the "bulk_actions-xxxxx" filter in 3.1 made it not possible to add items using that
	public function add_bulk_actions_via_javascript() {
		if ( ! current_user_can( $this->capability ) )
			return;
?>
		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="action"] option:last-child').before('<option value="bulk_retranscode_media"><?php echo esc_attr( __( 'Retranscode Media', 'transcoder' ) ); ?></option>');
			});
		</script>
<?php
	}


	// Handles the bulk actions POST
	public function bulk_action_handler() {
		if ( empty( $_REQUEST['action'] ) || ( 'bulk_retranscode_media' != $_REQUEST['action'] && 'bulk_retranscode_media' != $_REQUEST['action2'] ) )
			return;

		if ( empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] ) )
			return;

		check_admin_referer( 'bulk-media' );

		$ids = implode( ',', array_map( 'intval', $_REQUEST['media'] ) );

		// Can't use wp_nonce_url() as it escapes HTML entities
		wp_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'rt-retranscoder' ), admin_url( 'admin.php?page=rt-retranscoder&goback=1&ids=' . $ids ) ) );
		exit();
	}


	// The user interface
	public function retranscode_interface() {
		global $wpdb;

		?>

<div id="message" class="updated fade" style="display:none"></div>

<div class="wrap retranscodemedia">
	<h2><?php _e('Retranscode Media', 'transcoder'); ?></h2>

<?php

		// If the button was clicked
		if ( ! empty( $_POST['rt-retranscoder'] ) || ! empty( $_REQUEST['ids'] ) ) {
			// Capability check
			if ( ! current_user_can( $this->capability ) )
				wp_die( __( 'Cheatin&#8217; uh?', 'transcoder' ) );

			// Form nonce check
			check_admin_referer( 'rt-retranscoder' );

			$file_size = 0;
			$files = array();
			// Create the list of image IDs
			$usage_info = get_site_option( 'rt-transcoding-usage' );
			if ( ! empty( $_REQUEST['ids'] ) ) {
				if ( is_array( $_REQUEST['ids'] ) ){
					$_REQUEST['ids'] = implode( ',', $_REQUEST['ids'] );
				}
				$media = array_map( 'intval', explode( ',', trim( $_REQUEST['ids'], ',' ) ) );
				$ids = implode( ',', $media );
				foreach ( $media as $key => $each ) {
					$path = get_attached_file( $each );
					if ( file_exists( $path ) ) {
						$current_file_size = filesize( $path );
						$file_size = $file_size + $current_file_size ;
						$files[ $each ] = array(
							'name' => esc_html( get_the_title( $each ) ),
							'size' => $current_file_size
						);
					}
				}
			} else {
				// Directly querying the database is normally frowned upon, but all
				// of the API functions will return the full post objects which will
				// suck up lots of memory. This is best, just not as future proof.
				if ( ! $media = $wpdb->get_results( "SELECT ID, post_mime_type FROM $wpdb->posts WHERE post_type = 'attachment' AND ( post_mime_type LIKE 'audio/%' OR post_mime_type LIKE 'video/%' ) ORDER BY ID DESC" ) ) {
					echo '	<p>' . sprintf( __( "Unable to find any media. Are you sure <a href='%s'>some exist</a>?", 'transcoder' ), admin_url( 'upload.php' ) ) . "</p></div>";
					return;
				}

				// Generate the list of IDs
				$ids = array();
				foreach ( $media as $i => $each ) {
					if ( ! in_array( $each->post_mime_type, array( 'audio/mp3', 'audio/mpeg' ), true ) ) {
						$ids[] = $each->ID;
						$path = get_attached_file( $each->ID );
						if ( file_exists( $path ) ) {
							$current_file_size = filesize( $path );
							$file_size = $file_size + $current_file_size ;
							$files[ $each->ID ] = array(
								'name' => esc_html( get_the_title( $each->ID ) ),
								'size' => $current_file_size
							);
						}
					} else if ( in_array( $each->post_mime_type, array( 'audio/mp3', 'audio/mpeg' ), true ) ) {
						unset( $media[ $i ] );
					}
				}
				$ids = implode( ',', $ids );
			}

			if ( empty( $ids ) ) {
				echo '	<p>' . __( "There are no media available to send for transcoding.", 'transcoder' ) . '</p>';
				return;
			}

			if ( isset( $usage_info ) && is_array( $usage_info ) && array_key_exists( $this->api_key , $usage_info ) ) {
				if ( is_object( $usage_info[ $this->api_key ] ) && isset( $usage_info[ $this->api_key ]->status ) && $usage_info[ $this->api_key ]->status ) {
					if ( isset( $usage_info[ $this->api_key ]->remaining ) && $usage_info[ $this->api_key ]->remaining > 0 ) {
						if ( $usage_info[ $this->api_key ]->remaining < $file_size ) {
							$this->retranscode_admin_error_notice();
							// User doesn't have enough bandwidth remaining for re-transcoding
							echo '	<p>' . __( "You do not have sufficient bandwidth remaining to perform the transcoding.", 'transcoder' ) . '</p>';
							echo '	<p><b>' . __( "Your remaining bandwidth is : ", 'transcoder' ) . size_format( $usage_info[ $this->api_key ]->remaining, 2 ) . '</b></p>';
							echo '	<p><b>' . __( "Required bandwidth is: ", 'transcoder' ) . size_format( $file_size , 2 ) . '</b></p></div>';
							if ( $usage_info[ $this->api_key ]->remaining > 0 ) {
								if ( is_array( $files ) && count( $files ) > 0 ) {
									echo '<div><p>' . sprintf( __( "You can select the files manually and try again.", 'transcoder' ) ) . "</p>";
									echo '<form method="'. 'POST' . ' action="'. admin_url( 'admin.php' ) .'">';
									wp_nonce_field('rt-retranscoder');
									echo '<input type="hidden" name="page" value="rt-retranscoder">';
									echo '<table border=0>';
									?>
										<tr>
											<td><input type="submit" class="button button-primary button-small" value="<?php echo __( 'Proceed with retranscoding', 'transcoder'); ?>"></td>
											<td></td>
										</tr>
									<?php
									foreach ( $files as $key => $value ) {
									?>
										<tr>
											<td><label><input type="checkbox" name="ids[]" value="<?php echo $key; ?>" /> <?php echo $value['name']; ?> (ID <?php echo $key; ?>) </label></td>
											<td><?php echo size_format( $value['size'], 2); ?></td>
										</tr>
									<?php
									}
									?>
										<tr>
											<td><input type="submit" class="button button-primary button-small" value="<?php echo __( 'Proceed with retranscoding', 'transcoder'); ?>" ></td>
											<td></td>
										</tr>
									<?php
									echo '</table>';
									echo '</form></div>';
								}
							}
							return;
						}
					}
				}
			}

			echo '	<p>' . __( "Your files are being re-transcoded. Do not navigate away from this page until the process is completed, as doing so will prematurely abort the script. Retranscoding can take a while, especially for larger files. You can view the progress below.", 'transcoder' ) . '</p>';

			$count = count( $media );

			$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'transcoder' ), 'javascript:history.go(-1)' ) : '';
			$text_failures = sprintf( __( 'All done! %1$s media file(s) were successfully sent for transcoding in %2$s seconds and there were %3$s failure(s). To try transcoding the failed media again, <a href="%4$s">click here</a>. %5$s', 'transcoder' ), "' + rt_successes + '", "' + rt_totaltime + '", "' + rt_errors + '", esc_url( wp_nonce_url( admin_url( 'admin.php?page=rt-retranscoder&goback=1' ), 'rt-retranscoder' ) . '&ids=' ) . "' + rt_failedlist + '", $text_goback );
			$text_nofailures = sprintf( __( 'All done! %1$s media file(s) were successfully sent for transcoding in %2$s seconds and there were 0 failures. %3$s', 'transcoder' ), "' + rt_successes + '", "' + rt_totaltime + '", $text_goback );
?>


	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'transcoder' ) ?></em></p></noscript>

	<div id="retranscodemedia-bar" style="position:relative;height:25px;">
		<div id="retranscodemedia-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
	</div>

	<p><input type="button" class="button hide-if-no-js" name="retranscodemedia-stop" id="retranscodemedia-stop" value="<?php _e( 'Abort the Operation', 'transcoder' ) ?>" /></p>

	<h3 class="title"><?php _e( 'Debugging Information', 'transcoder' ) ?></h3>

	<p>
		<?php printf( __( 'Total Media: %s', 'transcoder' ), $count ); ?><br />
		<?php printf( __( 'Media Sent for Retranscoding: %s', 'transcoder' ), '<span id="retranscodemedia-debug-successcount">0</span>' ); ?><br />
		<?php printf( __( 'Failed While Sending: %s', 'transcoder' ), '<span id="retranscodemedia-debug-failurecount">0</span>' ); ?>
	</p>

	<ol id="retranscodemedia-debuglist">
		<li style="display:none"></li>
	</ol>

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function($){
			var i;
			var rt_media = [<?php echo $ids; ?>];
			var rt_total = rt_media.length;
			var rt_count = 1;
			var rt_percent = 0;
			var rt_successes = 0;
			var rt_errors = 0;
			var rt_failedlist = '';
			var rt_resulttext = '';
			var rt_timestart = new Date().getTime();
			var rt_timeend = 0;
			var rt_totaltime = 0;
			var rt_continue = true;

			// Create the progress bar
			$("#retranscodemedia-bar").progressbar();
			$("#retranscodemedia-bar-percent").html( "0%" );

			// Stop button
			$("#retranscodemedia-stop").click(function() {
				rt_continue = false;
				$('#retranscodemedia-stop').val("<?php echo $this->esc_quotes( __( 'Stopping...', 'transcoder' ) ); ?>");
			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#retranscodemedia-debuglist li").remove();

			// Called after each resize. Updates debug information and the progress bar.
			function RetranscodeMediaUpdateStatus( id, success, response ) {
				$("#retranscodemedia-bar").progressbar( "value", ( rt_count / rt_total ) * 100 );
				$("#retranscodemedia-bar-percent").html( Math.round( ( rt_count / rt_total ) * 1000 ) / 10 + "%" );
				rt_count = rt_count + 1;

				if ( success ) {
					rt_successes = rt_successes + 1;
					$("#retranscodemedia-debug-successcount").html(rt_successes);
					$("#retranscodemedia-debuglist").append("<li>" + response.success + "</li>");
				}
				else {
					rt_errors = rt_errors + 1;
					rt_failedlist = rt_failedlist + ',' + id;
					$("#retranscodemedia-debug-failurecount").html(rt_errors);
					$("#retranscodemedia-debuglist").append("<li>" + response.error + "</li>");
				}
			}

			// Called when all images have been processed. Shows the results and cleans up.
			function RetranscodeMediaFinishUp() {
				rt_timeend = new Date().getTime();
				rt_totaltime = Math.round( ( rt_timeend - rt_timestart ) / 1000 );

				$('#retranscodemedia-stop').hide();

				if ( rt_errors > 0 ) {
					rt_resulttext = '<?php echo $text_failures; ?>';
				} else {
					rt_resulttext = '<?php echo $text_nofailures; ?>';
				}

				$("#message").html("<p><strong>" + rt_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Regenerate a specified image via AJAX
			function RetranscodeMedia( id ) {
				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "retranscodemedia", id: id },
					success: function( response ) {
						if ( response !== Object( response ) || ( typeof response.success === "undefined" && typeof response.error === "undefined" ) ) {
							response = new Object;
							response.success = false;
							response.error = "<?php printf( esc_js( __( 'The resize request was abnormally terminated (ID %s). This is likely due to the media exceeding available memory or some other type of fatal error.', 'transcoder' ) ), '" + id + "' ); ?>";
						}

						if ( response.success ) {
							RetranscodeMediaUpdateStatus( id, true, response );
						}
						else {
							RetranscodeMediaUpdateStatus( id, false, response );
						}

						if ( rt_media.length && rt_continue ) {
							RetranscodeMedia( rt_media.shift() );
						}
						else {
							RetranscodeMediaFinishUp();
						}
					},
					error: function( response ) {
						RetranscodeMediaUpdateStatus( id, false, response );

						if ( rt_media.length && rt_continue ) {
							RetranscodeMedia( rt_media.shift() );
						}
						else {
							RetranscodeMediaFinishUp();
						}
					}
				});
			}

			RetranscodeMedia( rt_media.shift() );
		});
	// ]]>
	</script>
<?php
		}

		// No button click? Display the form.
		else {
?>
	<form method="post" action="">
<?php wp_nonce_field('rt-retranscoder') ?>

	<p><?php printf( __( "This tool will retranscode ALL audio/video media uploaded to your website. This can be handy if you need to transcode media files uploaded in the past.", 'transcoder' ) ); ?>

	<i><?php printf( __( "Sending your entire media library for retranscoding can consume a lot of your bandwidth allowance, so use this tool with care.", 'transcoder' ) ); ?></i></p>

	<p><?php printf( __( "You can retranscode specific media files (rather than ALL media) from the <a href='%s'>Media</a> page using Bulk Action via drop down or mouse hover a specific media (audio/video) file.", 'transcoder' ), admin_url( 'upload.php' ) ); ?></p>

	<p><?php _e( 'To begin, just press the button below.', 'transcoder' ); ?></p>

	<p><input type="submit" class="button hide-if-no-js button button-primary" name="rt-retranscoder" id="rt-retranscoder" value="<?php _e( 'Retranscode All Media', 'transcoder' ) ?>" /></p>

	<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'transcoder' ) ?></em></p></noscript>

	</form>
<?php
		} // End if button
?>
</div>

<?php
	}


	// Process a single image ID (this is an AJAX handler)
	public function ajax_process_retranscode_request() {
		@error_reporting( 0 ); // Don't break the JSON result

		header( 'Content-type: application/json' );

		$id = (int) $_REQUEST['id'];
		$media = get_post( $id );

		if ( ! $media || 'attachment' != $media->post_type || ( 'audio/' != substr( $media->post_mime_type, 0, 6 ) && 'video/' != substr( $media->post_mime_type, 0, 6 ) ) )
			die( json_encode( array( 'error' => sprintf( __( 'Sending Failed: %s is an invalid media ID/type.', 'transcoder' ), esc_html( $_REQUEST['id'] ) ) ) ) );

		if ( 'audio/mpeg' === $media->post_mime_type )
			die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) is MP3 file already. No need to send for transcoding', 'transcoder' ), esc_html( get_the_title( $media->ID ) ), $media->ID ) ) ) );

		if ( ! current_user_can( $this->capability ) )
			$this->die_json_error_msg( $media->ID, __( "Your user account doesn't have permission to transcode", 'transcoder' ) );

		// Check if media is already being transcoded

		if ( is_file_being_transcoded( $media->ID ) ) {
			$this->die_json_error_msg( $media->ID, sprintf( __( 'The media is already being transcoded', 'transcoder' ) ) );
		}

		/**
		 * Check if `_rt_transcoding_job_id` meta is present for the media
		 * if it's present then media won't get sent to the transcoder
		 * so we need to delete `_rt_transcoding_job_id` meta before we send
		 * media back for the retranscoding
		 */
		$already_sent = get_post_meta( $media->ID, '_rt_transcoding_job_id', true );

		if ( ! empty( $already_sent ) ) {
			$delete_meta = delete_post_meta( $media->ID, '_rt_transcoding_job_id' );
		}

		// Get the transcoder object
		$transcoder = new RT_Transcoder_Handler( $no_init = true );

		$attachment_meta['mime_type'] = $media->post_mime_type;

		$transcoded_files = get_post_meta( $media->ID, '_rt_media_transcoded_files', true );

		// No need to ask for the transcoded (mp4) file if we already have it
		// Only asks for the thumbnails

		if ( ! empty( $transcoded_files ) && is_array( $transcoded_files ) ) {
			if ( array_key_exists( 'mp4' , $transcoded_files ) && count( $transcoded_files[ 'mp4' ] ) > 0 ) {

				/**
				 * We can ask for the new fresh transcoded file even if it already present.
				 * Use: add_filter( 'rtt_force_trancode_media', '__return_true' );
				 *
				 * @param bool FALSE by default. Pass TRUE if you want to request for new transcoded file
				 */
				$force_transcode = apply_filters( 'rtt_force_trancode_media', false );
				if ( ! $force_transcode ) {
					$attachment_meta['mime_type'] = 'video/mp4';
				}
			}
		}

		// Send media for (Re)transcoding
		$send = $transcoder->wp_media_transcoding( $attachment_meta, $media->ID );

		$is_sent = get_post_meta( $media->ID, '_rt_transcoding_job_id', true );

		if ( ! $is_sent )
			$this->die_json_error_msg( $media->ID, __( 'Unknown failure reason.', 'transcoder' ) );

		$mark_media_as_sent = update_post_meta( $media->ID, '_rt_retranscoding_sent', $is_sent );

		die( json_encode( array( 'success' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) was successfully sent in %3$s seconds.', 'transcoder' ), esc_html( get_the_title( $media->ID ) ), $media->ID, timer_stop() ) ) ) );
	}


	// Helper to make a JSON error message
	public function die_json_error_msg( $id, $message ) {
		die( json_encode( array( 'error' => sprintf( __( '&quot;%1$s&quot; (ID %2$s) failed to sent. The error message was: %3$s', 'transcoder' ), esc_html( get_the_title( $id ) ), $id, $message ) ) ) );
	}


	// Helper function to escape quotes in strings for use in Javascript
	public function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}

	/**
	 * Display admin notice.
	 *
	 * @since	1.0.0
	 */
	function retranscode_admin_error_notice() {
	?>
		<div class="error error-info retranscode-notice is-dismissible">
			<p>
				<?php esc_html_e( 'Insufficient bandwidth!', 'transcoder' ); ?>
			</p>
		</div>
	<?php
	}

	/**
	 * Delete the previously added media thumbnail files
	 *
	 * @param  number 	$media_id     Post ID of the media
	 * @param  array 	$post_request Post request coming for the transcoder API
	 */
	public function rtt_before_thumbnail_store( $media_id = '', $post_request = '' ) {
		if ( empty( $media_id ) ) return;

		$previous_thumbs = get_post_meta( $media_id, '_rt_media_thumbnails', true );

		if ( ! empty( $previous_thumbs ) && is_array( $previous_thumbs ) ) {

			// Do not delete the current thumbnail of the video
			if ( ! rtt_is_override_thumbnail() ) {

				$current_thumb = get_post_meta( $media_id, '_rt_media_video_thumbnail', true );

				if ( ($key = array_search( $current_thumb, $previous_thumbs ) ) !== false ) {
					unset( $previous_thumbs[ $key ] );
				}
			}

			$delete = rtt_delete_transcoded_files( $previous_thumbs );
		}
		$delete_meta = delete_post_meta( $media_id, '_rt_media_thumbnails' );

	}

	/**
	 * Delete the previously transcoded media files
	 *
	 * @param  number 	$media_id     Post ID of the media
	 * @param  array 	$post_request Post request coming for the transcoder API
	 */
	public function rtt_before_transcoded_media_store( $media_id = '', $transcoded_files = '' ) {
		if ( empty( $media_id ) ) return;

		$current_files = get_post_meta( $media_id, '_rt_media_transcoded_files', true );

		if ( ! empty( $current_files ) && is_array( $current_files ) ) {
			foreach ( $current_files as $type => $files ) {
				if ( ! empty( $files ) && is_array( $files ) ) {
					$delete = rtt_delete_transcoded_files( $files );
				}
			}
		}
		$delete_meta = delete_post_meta( $media_id, '_rt_media_transcoded_files' );

	}

	/**
	 * Add the current thumbnail image in the newly added thumbnails if
	 * user wants to preserve the thumbnails set to the media
	 *
	 * @param  number 	$media_id     Post ID of the media
	 */
	public function transcoded_thumbnails_added( $media_id = '' ) {
		if ( empty( $media_id ) ) return;

		$is_retranscoding_job = get_post_meta( $media_id, '_rt_retranscoding_sent', true );

		if ( $is_retranscoding_job && ! rtt_is_override_thumbnail() ) {

			$new_thumbs = get_post_meta( $media_id, '_rt_media_thumbnails', true );

			if ( ! empty( $new_thumbs ) && is_array( $new_thumbs ) ) {

				$current_thumb = get_post_meta( $media_id, '_rt_media_video_thumbnail', true );
				if ( $current_thumb ) {
					$new_thumbs[] = $current_thumb;
					update_post_meta( $media_id, '_rt_media_thumbnails',$new_thumbs );
				}

			}

		}

	}

	/**
	 * Callback request from the transcoder has been processed, so delete the flags
	 * which are not necessary after processing the callback request
	 *
	 * @param  number 	$attachment_id     	Post ID of the media
	 * @param  string 	$job_id 			Unique job ID of the transcoding request
	 */
	public function rtt_handle_callback_finished( $attachment_id = '', $job_id = '' ) {
		if ( empty( $attachment_id ) ) return;

		$is_retranscoding_job = get_post_meta( $attachment_id, '_rt_retranscoding_sent', true );

		if ( $is_retranscoding_job ) {

			delete_post_meta( $attachment_id, '_rt_retranscoding_sent' );

		}

	}

}

// Start up this plugin
add_action( 'init', 'RetranscodeMedia' );
function RetranscodeMedia() {
	global $RetranscodeMedia;
	$RetranscodeMedia = new RetranscodeMedia();
}

?>