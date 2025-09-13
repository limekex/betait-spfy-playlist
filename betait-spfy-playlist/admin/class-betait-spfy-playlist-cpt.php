<?php

/**
 * The custom post type and taxonomy functionality of the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * Handles the registration of the Playlists custom post type and Genre taxonomy.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_CPT {

    /**
     * Initialize the class and set up the actions.
     *
     * @since    1.0.0
     */
public function __construct() {
    add_action( 'init', array( $this, 'register_cpt_and_taxonomy' ) );

    // Viktig: hook spesifikt for post type 'playlist'
    add_action( 'add_meta_boxes_playlist', array( $this, 'add_meta_boxes' ) );

    add_action( 'save_post', array( $this, 'save_playlist_meta' ) );
}


    /**
     * Log debug information if debugging is enabled.
     *
     * @since    1.0.0
     * @param    string $message The message to log.
     */
    private function log_debug( $message ) {
        if ( get_option( 'bspfy_debug', 0 ) ) {
            error_log( '[BeTA iT - Spfy Playlist CPT Debug] ' . $message );
        }
    }

    /**
     * Register the Playlists custom post type and Genre taxonomy.
     *
     * @since    1.0.0
     */
    public function register_cpt_and_taxonomy() {
        $this->log_debug( 'Registering Custom Post Type and Taxonomy.' );

        // Register the custom post type.
        $labels = array(
            'name'               => _x( 'Playlists', 'Post Type General Name', 'betait-spfy-playlist' ),
            'singular_name'      => _x( 'Playlist', 'Post Type Singular Name', 'betait-spfy-playlist' ),
            'menu_name'          => __( 'Playlists', 'betait-spfy-playlist' ),
            'all_items'          => __( 'All Playlists', 'betait-spfy-playlist' ),
            'add_new_item'       => __( 'Add New Playlist', 'betait-spfy-playlist' ),
            'edit_item'          => __( 'Edit Playlist', 'betait-spfy-playlist' ),
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
            'show_in_menu'        => 'betait-spfy-playlist',
            'menu_icon'           => 'dashicons-playlist-audio',
            'has_archive'         => true,
        );

        register_post_type( 'playlist', $args );
        $this->log_debug( 'Custom Post Type "Playlists" registered successfully.' );

        // Register the taxonomy.
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
            'show_in_menu'      => false,
            'show_admin_column' => true,
        );

        register_taxonomy( 'genre', array( 'playlist' ), $taxonomy_args );
        $this->log_debug( 'Taxonomy "Genres" registered successfully.' );
    }

    /**
     * Add a meta box for searching and adding tracks to the playlist.
     *
     * @since    1.0.0
     */
        public function add_meta_boxes() {
            // 1) Description (øverst)
            add_meta_box(
                'playlist_description_meta_box',
                __( 'Playlist Description', 'betait-spfy-playlist' ),
                array( $this, 'render_description_meta_box' ),
                'playlist',
                'normal',
                'high'
            );

            // 2) Custom playlist data (Spotify export) – midten
            add_meta_box(
                'playlist_spotify_export_meta_box',
                __( 'Spotify export', 'betait-spfy-playlist' ),
                array( $this, 'render_spotify_export_meta_box' ),
                'playlist',
                'normal',
                'default'
            );

            // 3) Tracks (nederst)
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
     * Render the content of the description meta box.
     *
     * @since    1.0.0
     * @param    WP_Post $post The post object.
     */
    public function render_description_meta_box( $post ) {
        wp_nonce_field( 'save_playlist_description_meta', 'playlist_description_nonce' );
        $description = get_post_meta( $post->ID, '_playlist_description', true );

        wp_editor( $description, 'playlist_description', array(
            'textarea_name' => 'playlist_description',
            'media_buttons' => false,
            'textarea_rows' => 10,
        ));
    }

    /**
 * Render the content of the Tracks meta box.
 *
 * @since    1.0.0
 * @param    WP_Post $post The post object.
 */
    public function render_meta_box_content( $post ) {
        $this->log_debug( 'Rendering Tracks meta box for post ID: ' . $post->ID );
        wp_nonce_field( 'save_playlist_meta', 'playlist_nonce' );

        // Hent lagrede spor (JSON eller array)
        $tracks = get_post_meta( $post->ID, '_playlist_tracks', true );
        if ( is_string( $tracks ) ) $tracks = json_decode( $tracks, true );
        if ( ! is_array( $tracks ) ) $tracks = [];

        // Søkefelt
        echo '<label for="playlist_tracks_search" class="bspfy-label">'. esc_html__( 'Search for Tracks', 'betait-spfy-playlist' ) .'</label>';
        echo '<div class="bsfy-srch" style="margin-bottom:10px;">';
        echo '  <input type="text" id="playlist_tracks_search" class="bspfy-input" placeholder="'. esc_attr__( 'Enter track name or artist...', 'betait-spfy-playlist' ) .'">';
        echo '  <button type="button" id="search_tracks_button" class="bspfy-button"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> <span class="screen-reader-text">'. esc_html__( 'Search', 'betait-spfy-playlist' ) .'</span></button>';
        echo '</div>';

        // Auth-placeholder – JS viser "Koble til" el. status
        echo '<div class="oauth-container" aria-live="polite"></div>';

        // Filtre
        echo '<div class="bspfy-checkbox-group" style="margin:10px 0;">';
        echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_artist" value="artist" checked> <span>'. esc_html__( 'Artist', 'betait-spfy-playlist' ) .'</span></label>';
        echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_track" value="track" checked> <span>'. esc_html__( 'Track', 'betait-spfy-playlist' ) .'</span></label>';
        echo '  <label class="bspfy-checkbox-label"><input type="checkbox" id="search_filter_album" value="album"> <span>'. esc_html__( 'Album', 'betait-spfy-playlist' ) .'</span></label>';
        echo '</div>';

        // Resultater (søk)
        echo '<div id="track_search_results" class="bspfy-track-grid" aria-live="polite"></div>';

        // Lagrede spor
        echo '<h3 class="bspfy-heading" style="margin-top:16px;">'. esc_html__( 'Playlist Tracks', 'betait-spfy-playlist' ) .'</h3>';
        echo '<div id="playlist_tracks_list" class="bspfy-track-list">';
        if ( $tracks ) {
            foreach ( $tracks as $track ) {
                $artist_name = $track['artists'][0]['name'] ?? esc_html__( 'Unknown Artist', 'betait-spfy-playlist' );
                $album_name  = $track['album']['name']       ?? esc_html__( 'Unknown Album', 'betait-spfy-playlist' );
                $track_name  = $track['name']                ?? esc_html__( 'Unknown Track', 'betait-spfy-playlist' );
                $track_uri   = $track['uri']                 ?? '';
                $track_id    = $track['id']                  ?? '';

                echo '<div class="bspfy-track-item" data-track-id="'. esc_attr($track_id) .'">';
                echo '  <img src="'. esc_url($track['album']['images'][0]['url'] ?? '' ) .'" alt="'. esc_attr($album_name) .'">';
                echo '  <div class="track-details">';
                echo '    <div class="track-details-artist track-details-space"><strong>Artist:</strong> '. esc_html($artist_name) .'</div>';
                echo '    <div class="track-details-album track-details-space"><strong>Album:</strong> '. esc_html($album_name) .'</div>';
                echo '    <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> '. esc_html($track_name) .'</div>';
                echo '  </div>';
                echo '  <div class="track-actions">';
                echo '    <button type="button" class="bspfy-icon-btn track-actions-preview-button" data-uri="'. esc_attr($track_uri) .'" aria-label="'. esc_attr__('Play/Pause preview', 'betait-spfy-playlist') .'"><i class="fa-solid fa-play" aria-hidden="true"></i></button>';
                echo '    <button type="button" class="bspfy-icon-btn bspfy-remove-button" data-track-id="'. esc_attr($track_id) .'" aria-label="'. esc_attr__('Remove track', 'betait-spfy-playlist') .'"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>';
                echo '  </div>';
                echo '</div>';
            }
        } else {
            echo '<div>'. esc_html__( 'No tracks added yet.', 'betait-spfy-playlist' ) .'</div>';
        }
        echo '</div>';

        // Attribution
        echo '<div class="bspfy-spotify-attribution" style="margin-top:10px;">';
        echo '  <span>'. esc_html__( 'BeTA Spfy Playlist is powered by', 'betait-spfy-playlist' ) .'</span> ';
        echo '  <img src="'. esc_url( plugin_dir_url( __FILE__ ) . '../assets/Spotify_Full_Logo_RGB_Green.png' ) .'" alt="Spotify" class="bspfy-spotify-logo">';
        echo '</div>';

        // Hidden JSON
        echo '<input type="hidden" id="playlist_tracks" name="playlist_tracks" value="'. esc_attr( wp_json_encode( $tracks ) ) .'">';
    }


	public function render_spotify_export_meta_box( $post ) {
    // Sørg for at media-biblioteket er tilgjengelig
    if ( function_exists('wp_enqueue_media') ) {
        wp_enqueue_media();
    }

    wp_nonce_field( 'save_playlist_spotify_export', 'playlist_spotify_export_nonce' );

    $default_name = get_the_title( $post );
    $custom_name  = get_post_meta( $post->ID, '_playlist_spotify_name', true );
    if ( $custom_name === '' ) {
        $custom_name = $default_name;
    }

    $image_id   = (int) get_post_meta( $post->ID, '_playlist_spotify_image_id', true );
    $img_src    = '';
    if ( $image_id ) {
        $img = wp_get_attachment_image_src( $image_id, 'medium' );
        if ( $img ) $img_src = $img[0];
    }

    echo '<div class="bspfy-export-box">';

    // Navn
    echo '<p><label for="playlist_spotify_name"><strong>'. esc_html__( 'Custom playlist name', 'betait-spfy-playlist' ) .'</strong></label></p>';
    echo '<p><input type="text" id="playlist_spotify_name" name="playlist_spotify_name" class="widefat" value="'. esc_attr( $custom_name ) .'" placeholder="'. esc_attr__( 'Playlist name on Spotify', 'betait-spfy-playlist' ) .'" /></p>';

    // Bilde
    echo '<p><strong>'. esc_html__( 'Custom playlist image', 'betait-spfy-playlist' ) .'</strong></p>';

    echo '<div id="bspfy-cover-preview" class="'. ( $img_src ? '' : 'is-empty' ) .'" style="border:1px solid #ccd0d4;border-radius:6px;padding:6px;text-align:center;">';
    if ( $img_src ) {
        echo '<img src="'. esc_url( $img_src ) .'" alt="" style="max-width:100%;height:auto;border-radius:4px;" />';
    } else {
        echo '<span class="description">'. esc_html__( 'No image selected', 'betait-spfy-playlist' ) .'</span>';
    }
    echo '</div>';

    echo '<p style="margin-top:8px;">';
    echo '  <button type="button" class="button" id="bspfy-choose-cover">'. esc_html__( 'Choose image', 'betait-spfy-playlist' ) .'</button> ';
    echo '  <button type="button" class="button link-delete '. ( $img_src ? '' : 'hidden' ) .'" id="bspfy-remove-cover">'. esc_html__( 'Remove', 'betait-spfy-playlist' ) .'</button>';
    echo '</p>';

    echo '<input type="hidden" id="playlist_spotify_image_id" name="playlist_spotify_image_id" value="'. esc_attr( $image_id ) .'" />';

    echo '</div>'; // .bspfy-export-box

    // Liten inline-JS for å håndtere Media Library
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
     * Save the playlist tracks meta data.
     *
     * @since    1.0.0
     * @param    int $post_id The ID of the post being saved.
     */
    public function save_playlist_meta( $post_id ) {
        $this->log_debug( 'Saving playlist metadata for post ID: ' . $post_id );

        if ( ! isset( $_POST['playlist_nonce'] ) || ! wp_verify_nonce( $_POST['playlist_nonce'], 'save_playlist_meta' ) ) {
            $this->log_debug( 'Nonce verification failed for post ID: ' . $post_id );
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            $this->log_debug( 'User lacks permission to edit post ID: ' . $post_id );
            return;
        }

        if ( isset( $_POST['playlist_tracks'] ) ) {
            $raw    = wp_unslash( $_POST['playlist_tracks'] );
            $array  = json_decode( $raw, true );
            if ( ! is_array( $array ) ) $array = [];

            $seen = [];
            $out  = [];
            foreach ( $array as $t ) {
                $id = isset($t['id']) ? sanitize_text_field($t['id']) : '';
                if ( ! $id || isset($seen[$id]) ) continue; // dropp duplikater
                $seen[$id] = true;
                $out[] = $t; // bevar original feltstruktur
            }
            update_post_meta( $post_id, '_playlist_tracks', wp_json_encode( $out ) );
            $this->log_debug( 'Tracks (deduped) saved for post ID: ' . $post_id );
        }


        if ( isset( $_POST['playlist_description'] ) ) {
            $description = sanitize_textarea_field( $_POST['playlist_description'] );
            update_post_meta( $post_id, '_playlist_description', $description );
            $this->log_debug( 'Description saved successfully for post ID: ' . $post_id );
        }

        // --- Save Spotify export settings ---
if ( isset($_POST['playlist_spotify_export_nonce']) 
     && wp_verify_nonce( $_POST['playlist_spotify_export_nonce'], 'save_playlist_spotify_export' ) ) {

    // Navn
    if ( isset($_POST['playlist_spotify_name']) ) {
        $name = sanitize_text_field( wp_unslash( $_POST['playlist_spotify_name'] ) );
        update_post_meta( $post_id, '_playlist_spotify_name', $name );
        $this->log_debug( 'Spotify export name saved for post ID: ' . $post_id );
    }

    // Bilde (attachment ID)
    $img_id = isset($_POST['playlist_spotify_image_id']) ? absint($_POST['playlist_spotify_image_id']) : 0;
    if ( $img_id ) {
        update_post_meta( $post_id, '_playlist_spotify_image_id', $img_id );
        $this->log_debug( 'Spotify export image ID saved for post ID: ' . $post_id );
    } else {
        delete_post_meta( $post_id, '_playlist_spotify_image_id' );
    }
}

    }
}
