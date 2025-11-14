<?php
/**
 * Custom Post Type (Playlist) and Taxonomy (Genre) registration + meta boxes.
 *
 * This class:
 * - Registers CPT `playlist` (submenu under the plugin menu).
 * - Registers taxonomy `genre` for playlists.
 * - Renders three meta boxes (Description, Spotify export, Tracks).
 * - Saves playlist metadata safely (dedupes track IDs, preserves original track structure).
 * - Uses JSON_UNESCAPED_UNICODE for hidden JSON to avoid \uXXXX for Norwegian characters.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_CPT
 */
class Betait_Spfy_Playlist_CPT {

	/**
	 * Constructor. Wires up actions.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt_and_taxonomy' ) );

		// Only add meta boxes for our CPT.
		add_action( 'add_meta_boxes_playlist', array( $this, 'add_meta_boxes' ) );

		add_action( 'save_post', array( $this, 'save_playlist_meta' ) );
	}

	/**
	 * Conditional debug logger.
	 *
	 * Toggle via Settings → (bspfy_debug). We only log when enabled.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function log_debug( $message ) {
		if ( (int) get_option( 'bspfy_debug', 0 ) !== 1 ) {
			return;
		}
		$prefix = '[BSPFY CPT] ';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $prefix . (string) $message );
	}

	/**
	 * Register the Playlists custom post type and Genre taxonomy.
	 *
	 * @return void
	 */
	public function register_cpt_and_taxonomy() {
		$this->log_debug( 'Registering CPT and taxonomy.' );

		// ----- CPT: playlist -----
		$labels = array(
			'name'               => _x( 'Playlists', 'Post Type General Name', 'betait-spfy-playlist' ),
			'singular_name'      => _x( 'Playlist', 'Post Type Singular Name', 'betait-spfy-playlist' ),
			'menu_name'          => __( 'Playlists', 'betait-spfy-playlist' ),
			'all_items'          => __( 'All Playlists', 'betait-spfy-playlist' ),
			'add_new'            => __( 'Add New', 'betait-spfy-playlist' ),
			'add_new_item'       => __( 'Add New Playlist', 'betait-spfy-playlist' ),
			'edit_item'          => __( 'Edit Playlist', 'betait-spfy-playlist' ),
			'new_item'           => __( 'New Playlist', 'betait-spfy-playlist' ),
			'view_item'          => __( 'View Playlist', 'betait-spfy-playlist' ),
			'search_items'       => __( 'Search Playlists', 'betait-spfy-playlist' ),
			'not_found'          => __( 'No playlists found', 'betait-spfy-playlist' ),
			'not_found_in_trash' => __( 'No playlists found in Trash', 'betait-spfy-playlist' ),
		);

		$args = array(
			'label'               => __( 'Playlists', 'betait-spfy-playlist' ),
			'description'         => __( 'Custom Post Type for Spotify Playlists', 'betait-spfy-playlist' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'thumbnail' ),
			'taxonomies'          => array( 'genre' ),
			'public'              => true,
			'show_in_menu'        => 'betait-spfy-playlist', // attach as submenu under plugin menu slug.
			'has_archive'         => true,
			'show_in_rest'        => true, // modern WP compatibility.
			'menu_icon'           => 'dashicons-playlist-audio',
			'rewrite'             => array( 'slug' => 'playlists' ),
		);

		/**
		 * Filter CPT args if needed by sites.
		 *
		 * @param array $args
		 */
		$args = apply_filters( 'bspfy_cpt_args', $args );

		register_post_type( 'playlist', $args );
		$this->log_debug( 'CPT "playlist" registered.' );

		// ----- Taxonomy: genre -----
		$taxonomy_labels = array(
			'name'              => _x( 'Genres', 'taxonomy general name', 'betait-spfy-playlist' ),
			'singular_name'     => _x( 'Genre', 'taxonomy singular name', 'betait-spfy-playlist' ),
			'search_items'      => __( 'Search Genres', 'betait-spfy-playlist' ),
			'all_items'         => __( 'All Genres', 'betait-spfy-playlist' ),
			'edit_item'         => __( 'Edit Genre', 'betait-spfy-playlist' ),
			'add_new_item'      => __( 'Add New Genre', 'betait-spfy-playlist' ),
			'new_item_name'     => __( 'New Genre Name', 'betait-spfy-playlist' ),
			'menu_name'         => __( 'Genres', 'betait-spfy-playlist' ),
		);

		$taxonomy_args = array(
			'labels'            => $taxonomy_labels,
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_in_menu'      => false, // managed via submenu already.
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'genre' ),
		);

		/**
		 * Filter taxonomy args if needed by sites.
		 *
		 * @param array $taxonomy_args
		 */
		$taxonomy_args = apply_filters( 'bspfy_taxonomy_args', $taxonomy_args );

		register_taxonomy( 'genre', array( 'playlist' ), $taxonomy_args );
		$this->log_debug( 'Taxonomy "genre" registered.' );
	}

	/**
	 * Register meta boxes for the Playlist CPT.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		// 1) Description (top).
		add_meta_box(
			'playlist_description_meta_box',
			__( 'Playlist Description', 'betait-spfy-playlist' ),
			array( $this, 'render_description_meta_box' ),
			'playlist',
			'normal',
			'high'
		);

		// 2) Spotify export (middle).
		add_meta_box(
			'playlist_spotify_export_meta_box',
			__( 'Spotify export', 'betait-spfy-playlist' ),
			array( $this, 'render_spotify_export_meta_box' ),
			'playlist',
			'normal',
			'default'
		);

		// 3) Tracks (bottom).
		add_meta_box(
			'playlist_tracks_meta_box',
			__( 'Tracks', 'betait-spfy-playlist' ),
			array( $this, 'render_meta_box_content' ),
			'playlist',
			'normal',
			'default'
		);

		$this->log_debug( 'Meta boxes added for playlist.' );
	}

	/**
	 * Render the Description meta box.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render_description_meta_box( $post ) {
		wp_nonce_field( 'save_playlist_description_meta', 'playlist_description_nonce' );

		$description = (string) get_post_meta( $post->ID, '_playlist_description', true );

		wp_editor(
			$description,
			'playlist_description',
			array(
				'textarea_name' => 'playlist_description',
				'media_buttons' => false,
				'textarea_rows' => 10,
			)
		);
	}

	/**
	 * Render the Tracks meta box (search + selected items).
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render_meta_box_content( $post ) {
		$this->log_debug( 'Rendering Tracks meta box for post ID: ' . $post->ID );

		wp_nonce_field( 'save_playlist_meta', 'playlist_nonce' );

		// Stored tracks: may be JSON string or array.
		$tracks = get_post_meta( $post->ID, '_playlist_tracks', true );
		if ( is_string( $tracks ) ) {
			$tracks = json_decode( $tracks, true );
		}
		if ( ! is_array( $tracks ) ) {
			$tracks = array();
		}

		// Search field.
		echo '<label for="playlist_tracks_search" class="bspfy-label">' . esc_html__( 'Search for Tracks', 'betait-spfy-playlist' ) . '</label>';
		echo '<div class="bsfy-srch" style="margin-bottom:10px;">';
		echo '  <input type="text" id="playlist_tracks_search" class="bspfy-input" placeholder="' . esc_attr__( 'Enter track name or artist...', 'betait-spfy-playlist' ) . '">';
		echo '  <button type="button" id="search_tracks_button" class="bspfy-button"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> <span class="screen-reader-text">' . esc_html__( 'Search', 'betait-spfy-playlist' ) . '</span></button>';
		echo '</div>';

		// Auth placeholder (JS will render connect/status).
		echo '<div class="oauth-container" aria-live="polite"></div>';

		// Filters.
		echo '<div class="bspfy-checkbox-group" style="margin:10px 0;">';
		echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_artist" value="artist" checked> <span>' . esc_html__( 'Artist', 'betait-spfy-playlist' ) . '</span></label>';
		echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_track" value="track" checked> <span>' . esc_html__( 'Track', 'betait-spfy-playlist' ) . '</span></label>';
		echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_album" value="album"> <span>' . esc_html__( 'Album', 'betait-spfy-playlist' ) . '</span></label>';
		echo '</div>';

		// Search results container.
		echo '<div id="track_search_results" class="bspfy-track-grid" aria-live="polite"></div>';

		// Saved tracks.
		echo '<h3 class="bspfy-heading" style="margin-top:16px;">' . esc_html__( 'Playlist Tracks', 'betait-spfy-playlist' ) . '</h3>';
		echo '<div id="playlist_tracks_list" class="bspfy-track-list">';

		if ( $tracks ) {
			foreach ( $tracks as $track ) {
				$artist_name = isset( $track['artists'][0]['name'] ) ? (string) $track['artists'][0]['name'] : esc_html__( 'Unknown Artist', 'betait-spfy-playlist' );
				$album_name  = isset( $track['album']['name'] ) ? (string) $track['album']['name'] : esc_html__( 'Unknown Album', 'betait-spfy-playlist' );
				$track_name  = isset( $track['name'] ) ? (string) $track['name'] : esc_html__( 'Unknown Track', 'betait-spfy-playlist' );
				$track_uri   = isset( $track['uri'] ) ? (string) $track['uri'] : '';
				$track_id    = isset( $track['id'] ) ? (string) $track['id'] : '';

				$img_url = '';
				if ( ! empty( $track['album']['images'][0]['url'] ) ) {
					$img_url = (string) $track['album']['images'][0]['url'];
				}

				echo '<div class="bspfy-track-item" data-track-id="' . esc_attr( $track_id ) . '">';
				echo '  <img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $album_name ) . '">';
				echo '  <div class="track-details">';
				echo '    <div class="track-details-artist track-details-space"><strong>Artist:</strong> ' . esc_html( $artist_name ) . '</div>';
				echo '    <div class="track-details-album track-details-space"><strong>Album:</strong> ' . esc_html( $album_name ) . '</div>';
				echo '    <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ' . esc_html( $track_name ) . '</div>';
				echo '  </div>';
				echo '  <div class="track-actions">';
				echo '    <button type="button" class="bspfy-icon-btn track-actions-preview-button" data-uri="' . esc_attr( $track_uri ) . '" aria-label="' . esc_attr__( 'Play/Pause preview', 'betait-spfy-playlist' ) . '"><i class="fa-solid fa-play" aria-hidden="true"></i></button>';
				echo '    <button type="button" class="bspfy-icon-btn bspfy-remove-button" data-track-id="' . esc_attr( $track_id ) . '" aria-label="' . esc_attr__( 'Remove track', 'betait-spfy-playlist' ) . '"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>';
				echo '  </div>';
				echo '</div>';
			}
		} else {
			echo '<div>' . esc_html__( 'No tracks added yet.', 'betait-spfy-playlist' ) . '</div>';
		}

		echo '</div>';

		// Attribution.
		$spotify_logo = plugin_dir_url( __FILE__ ) . '../assets/Spotify_Full_Logo_RGB_Green.png';
		echo '<div class="bspfy-spotify-attribution" style="margin-top:10px;">';
		echo '  <span>' . esc_html__( 'BeTA Spfy Playlist is powered by', 'betait-spfy-playlist' ) . '</span> ';
		echo '  <img src="' . esc_url( $spotify_logo ) . '" alt="Spotify" class="bspfy-spotify-logo">';
		echo '</div>';

		// Hidden JSON for selected tracks.
		// Use UNESCAPED_UNICODE to avoid \uXXXX for Norwegian letters (e.g., Ø).
		$hidden_json = wp_json_encode( $tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		echo '<input type="hidden" id="playlist_tracks" name="playlist_tracks" value="' . esc_attr( (string) $hidden_json ) . '">';
	}

	/**
	 * Render the Spotify export meta box (custom title template, description template, cover image).
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_spotify_export_meta_box( $post ) {
		// Ensure the Media Library is available.
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_nonce_field( 'save_playlist_spotify_export', 'playlist_spotify_export_nonce' );

		// Get global settings (fallback).
		$global_title_template = get_option( 'bspfy_save_playlist_title_template', '{{playlistTitle}} – {{siteName}}' );
		$global_desc_template  = get_option( 'bspfy_save_playlist_description_template', '' );

		// Get post-specific settings.
		$custom_title_template = get_post_meta( $post->ID, '_playlist_spotify_title_template', true );
		$custom_desc_template  = get_post_meta( $post->ID, '_playlist_spotify_description_template', true );
		$use_custom_cover      = (bool) get_post_meta( $post->ID, '_playlist_spotify_use_cover', true );

		$image_id = (int) get_post_meta( $post->ID, '_playlist_spotify_image_id', true );
		$img_src  = '';
		if ( $image_id ) {
			$img = wp_get_attachment_image_src( $image_id, 'medium' );
			if ( $img ) {
				$img_src = (string) $img[0];
			}
		}

		echo '<div class="bspfy-export-box">';

		echo '<p class="description" style="margin-bottom:16px;">' . esc_html__( 'Configure how this playlist will be saved to Spotify. Leave fields empty to use global settings.', 'betait-spfy-playlist' ) . '</p>';

		// Title template.
		echo '<p><label for="playlist_spotify_title_template"><strong>' . esc_html__( 'Playlist title template', 'betait-spfy-playlist' ) . '</strong></label></p>';
		echo '<p><input type="text" id="playlist_spotify_title_template" name="playlist_spotify_title_template" class="widefat" value="' . esc_attr( $custom_title_template ) . '" placeholder="' . esc_attr( $global_title_template ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Available placeholders: {{playlistTitle}}, {{siteName}}, {{playlistExcerpt}}', 'betait-spfy-playlist' ) . '</p>';

		// Description template.
		echo '<p style="margin-top:16px;"><label for="playlist_spotify_description_template"><strong>' . esc_html__( 'Playlist description template', 'betait-spfy-playlist' ) . '</strong></label></p>';
		echo '<p><textarea id="playlist_spotify_description_template" name="playlist_spotify_description_template" class="widefat" rows="3" placeholder="' . esc_attr( $global_desc_template ) . '">' . esc_textarea( $custom_desc_template ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Available placeholders: {{playlistTitle}}, {{siteName}}, {{playlistExcerpt}}', 'betait-spfy-playlist' ) . '</p>';

		// Image.
		echo '<p style="margin-top:16px;"><strong>' . esc_html__( 'Custom playlist cover image', 'betait-spfy-playlist' ) . '</strong></p>';
		echo '<p><label><input type="checkbox" id="playlist_spotify_use_cover" name="playlist_spotify_use_cover" value="1" ' . checked( $use_custom_cover, true, false ) . ' /> ' . esc_html__( 'Use custom cover image when saving to Spotify', 'betait-spfy-playlist' ) . '</label></p>';

		echo '<div id="bspfy-cover-preview" class="' . ( $img_src ? '' : 'is-empty' ) . '" style="border:1px solid #ccd0d4;border-radius:6px;padding:6px;text-align:center;">';
		if ( $img_src ) {
			echo '<img src="' . esc_url( $img_src ) . '" alt="" style="max-width:100%;height:auto;border-radius:4px;" />';
		} else {
			echo '<span class="description">' . esc_html__( 'No image selected', 'betait-spfy-playlist' ) . '</span>';
		}
		echo '</div>';

		echo '<p style="margin-top:8px;">';
		echo '  <button type="button" class="button" id="bspfy-choose-cover">' . esc_html__( 'Choose image', 'betait-spfy-playlist' ) . '</button> ';
		echo '  <button type="button" class="button link-delete ' . ( $img_src ? '' : 'hidden' ) . '" id="bspfy-remove-cover">' . esc_html__( 'Remove', 'betait-spfy-playlist' ) . '</button>';
		echo '</p>';

		echo '<input type="hidden" id="playlist_spotify_image_id" name="playlist_spotify_image_id" value="' . esc_attr( $image_id ) . '" />';

		echo '</div>'; // .bspfy-export-box
		?>

		<script>
		(function($){
		  let frame;

		  $('#bspfy-choose-cover').on('click', function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({
			  title: '<?php echo esc_js( __( 'Select playlist image', 'betait-spfy-playlist' ) ); ?>',
			  button: { text: '<?php echo esc_js( __( 'Use this image', 'betait-spfy-playlist' ) ); ?>' },
			  multiple: false
			});
			frame.on('select', function(){
			  const att = frame.state().get('selection').first().toJSON();
			  $('#playlist_spotify_image_id').val(att.id);
			  const url = (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url);
			  $('#bspfy-cover-preview')
				.removeClass('is-empty')
				.html('<img src="'+ url +'" alt="" style="max-width:100%;height:auto;border-radius:4px;">');
			  $('#bspfy-remove-cover').removeClass('hidden');
			});
			frame.open();
		  });

		  $('#bspfy-remove-cover').on('click', function(e){
			e.preventDefault();
			$('#playlist_spotify_image_id').val('');
			$('#bspfy-cover-preview').addClass('is-empty').html('<span class="description"><?php echo esc_js( __( 'No image selected', 'betait-spfy-playlist' ) ); ?></span>');
			$(this).addClass('hidden');
		  });
		})(jQuery);
		</script>
		<style>
		  .bspfy-export-box .hidden { display:none; }
		  .bspfy-export-box .link-delete { color:#b32d2e; }
		  .bspfy-export-box .link-delete:hover { color:#8a2425; }
		</style>
		<?php
	}

	/**
	 * Save the playlist metadata (tracks, description, export settings).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_playlist_meta( $post_id ) {
		$this->log_debug( 'Saving metadata for post ID: ' . $post_id );

		// Bail on autosave/revisions and wrong post type.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'playlist' ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$this->log_debug( 'edit_post capability failed for post ID: ' . $post_id );
			return;
		}

		// Tracks meta.
		if ( isset( $_POST['playlist_nonce'] ) && wp_verify_nonce( $_POST['playlist_nonce'], 'save_playlist_meta' ) ) {
			if ( isset( $_POST['playlist_tracks'] ) ) {
				$raw   = wp_unslash( $_POST['playlist_tracks'] );
				$array = json_decode( $raw, true );
				if ( ! is_array( $array ) ) {
					$array = array();
				}

				// Deduplicate by track ID, preserve original structure.
				$seen = array();
				$out  = array();
				foreach ( $array as $t ) {
					$id = isset( $t['id'] ) ? sanitize_text_field( $t['id'] ) : '';
					if ( '' === $id || isset( $seen[ $id ] ) ) {
						continue;
					}
					$seen[ $id ] = true;
					$out[]       = $t;
				}

				update_post_meta( $post_id, '_playlist_tracks', wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				$this->log_debug( 'Tracks saved (deduped) for post ID: ' . $post_id );
			}

			// Description meta.
			if ( isset( $_POST['playlist_description'] ) ) {
				$description = sanitize_textarea_field( wp_unslash( $_POST['playlist_description'] ) );
				update_post_meta( $post_id, '_playlist_description', $description );
				$this->log_debug( 'Description saved for post ID: ' . $post_id );
			}
		}

		// Spotify export settings.
		if ( isset( $_POST['playlist_spotify_export_nonce'] ) && wp_verify_nonce( $_POST['playlist_spotify_export_nonce'], 'save_playlist_spotify_export' ) ) {
			// Title template.
			if ( isset( $_POST['playlist_spotify_title_template'] ) ) {
				$title_template = sanitize_text_field( wp_unslash( $_POST['playlist_spotify_title_template'] ) );
				if ( '' !== $title_template ) {
					update_post_meta( $post_id, '_playlist_spotify_title_template', $title_template );
					$this->log_debug( 'Export title template saved for post ID: ' . $post_id );
				} else {
					delete_post_meta( $post_id, '_playlist_spotify_title_template' );
					$this->log_debug( 'Export title template cleared (using global) for post ID: ' . $post_id );
				}
			}

			// Description template.
			if ( isset( $_POST['playlist_spotify_description_template'] ) ) {
				$desc_template = sanitize_textarea_field( wp_unslash( $_POST['playlist_spotify_description_template'] ) );
				if ( '' !== $desc_template ) {
					update_post_meta( $post_id, '_playlist_spotify_description_template', $desc_template );
					$this->log_debug( 'Export description template saved for post ID: ' . $post_id );
				} else {
					delete_post_meta( $post_id, '_playlist_spotify_description_template' );
					$this->log_debug( 'Export description template cleared (using global) for post ID: ' . $post_id );
				}
			}

			// Use custom cover checkbox.
			$use_cover = isset( $_POST['playlist_spotify_use_cover'] ) && '1' === $_POST['playlist_spotify_use_cover'];
			update_post_meta( $post_id, '_playlist_spotify_use_cover', $use_cover ? 1 : 0 );
			$this->log_debug( 'Use custom cover: ' . ( $use_cover ? 'yes' : 'no' ) . ' for post ID: ' . $post_id );

			// Image (attachment ID).
			$img_id = isset( $_POST['playlist_spotify_image_id'] ) ? absint( $_POST['playlist_spotify_image_id'] ) : 0;
			if ( $img_id ) {
				update_post_meta( $post_id, '_playlist_spotify_image_id', $img_id );
				$this->log_debug( 'Export image ID saved for post ID: ' . $post_id );
			} else {
				delete_post_meta( $post_id, '_playlist_spotify_image_id' );
			}
		}
	}
}
