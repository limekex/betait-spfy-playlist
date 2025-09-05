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
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
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
            'supports'            => array( 'title', 'thumbnail', 'custom-fields' ),
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
        
        add_meta_box(
            'playlist_description',
            __( 'Playlist Description', 'betait-spfy-playlist' ),
            array( $this, 'render_playlist_description_metabox' ),
            'playlist',
            'normal',
            'high'
        );
        $this->log_debug( 'Meta box for despription added successfully.' );
        
        add_meta_box(
            'playlist_tracks_meta_box',
            __( 'Tracks', 'betait-spfy-playlist' ),
            array( $this, 'render_meta_box_content' ),
            'playlist',
            'normal',
            'core'
        );
        $this->log_debug( 'Meta box for Tracks added successfully.' );

    }

    public function render_playlist_description_metabox( $post ) {
        wp_nonce_field( 'save_playlist_description_meta', 'playlist_description_nonce' );
        $description = get_post_meta( $post->ID, '_playlist_description', true );

        wp_editor( $description, 'playlist_description', array(
            'textarea_name' => 'playlist_description',
            'media_buttons' => false,
            'textarea_rows' => 10,
        ) );
    }

    /**
     * Save the playlist description meta.
     */
    public function save_playlist_description_meta( $post_id ) {
        if ( ! isset( $_POST['playlist_description_nonce'] ) ||
             ! wp_verify_nonce( $_POST['playlist_description_nonce'], 'save_playlist_description_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['playlist_description'] ) ) {
            update_post_meta( $post_id, '_playlist_description', sanitize_textarea_field( $_POST['playlist_description'] ) );
        }
    }

/**
 * Render the content of the Tracks meta box.
 *
 * @since    1.0.0
 * @param    WP_Post $post The post object.
 */
public function render_meta_box_content( $post ) {
    $this->log_debug( 'Rendering Tracks meta box for post ID: ' . $post->ID );

    // Add nonce for security and authentication.
    wp_nonce_field( 'save_playlist_meta', 'playlist_nonce' );
    $this->log_debug( 'Nonce added for post ID: ' . $post->ID );

    // Retrieve the saved tracks and decode them if necessary.
    $tracks = get_post_meta( $post->ID, '_playlist_tracks', true );

    if ( is_string( $tracks ) ) {
        $this->log_debug( 'Tracks data is a string. Attempting to decode JSON.' );
        $tracks = json_decode( $tracks, true ); // Decode JSON to an array.
    }

    if ( ! is_array( $tracks ) ) {
        $this->log_debug( 'Tracks data is not an array. Setting to empty array.' );
        $tracks = [];
    }

    $this->log_debug( 'Tracks data decoded successfully. Number of tracks: ' . count( $tracks ) );

    // Search input and button.
    echo '<label for="playlist_tracks_search" class="bspfy-label">';
    _e( 'Search for Tracks', 'betait-spfy-playlist' );
    echo '</label>';
    echo '<div class="bsfy-srch"><div style="margin-bottom: 10px;">';
    echo '<input type="text" id="playlist_tracks_search" class="bspfy-input" placeholder="' . __( 'Enter track name or artist...', 'betait-spfy-playlist' ) . '">';
    echo '<button type="button" id="search_tracks_button" class="bspfy-button">' . __( 'Search', 'betait-spfy-playlist' ) . '</button>';
    echo '</div>';
    $current_user_id = get_current_user_id();
    $spotify_access_token = $current_user_id ? get_user_meta($current_user_id, 'spotify_access_token', true) : '';
    $spotify_user_name = '';
    
    if ($spotify_access_token) {
        $spotify_user_name = get_user_meta($current_user_id, 'spotify_user_name', true);
    }
    
    echo '<div class="oauth-container">';
    if ($spotify_access_token) {
        $auth_message = sprintf(
            __( 'Authenticated as %s', 'betait-spfy-playlist' ),
            esc_html($spotify_user_name ?: __( 'Unknown User', 'betait-spfy-playlist' ))
        );
        echo '<div id="spotify-auth-status" class="bspfy-authenticated">' . $auth_message . '</div>';
    } else {
        echo '<div id="spotify-auth-button" class="bspfy-button">';
        echo '<span class="btspfy-button-text">' . __( 'Authenticate with Spotify', 'betait-spfy-playlist' ) . '</span>';
        echo '<span class="btspfy-button-icon-divider btspfy-button-icon-divider-right"><i class="fa-spotify fab" aria-hidden="true"></i></span>';
        echo '</div></div>';
    }
    echo '</div>';
    

    // Checkboxes for search filters.
    echo '<div class="bspfy-checkbox-group" style="margin-bottom: 10px;">';

    // Artist Checkbox
    echo '<label class="bspfy-checkbox-label">';
    echo '<input type="checkbox" id="search_filter_artist" name="search_filter_artist" value="artist" checked>';
    echo '<span>' . __( 'Artist', 'betait-spfy-playlist' ) . '</span>';
    echo '</label>';

    // Track Checkbox
    echo '<label class="bspfy-checkbox-label">';
    echo '<input type="checkbox" id="search_filter_track" name="search_filter_track" value="track" checked>';
    echo '<span>' . __( 'Track', 'betait-spfy-playlist' ) . '</span>';
    echo '</label>';

    // Album Checkbox
    echo '<label class="bspfy-checkbox-label">';
    echo '<input type="checkbox" id="search_filter_album" name="search_filter_album" value="album">';
    echo '<span>' . __( 'Album', 'betait-spfy-playlist' ) . '</span>';
    echo '</label>';

    echo '</div>';


    $this->log_debug( 'Search input, button, and checkboxes rendered for post ID: ' . $post->ID );

    // Feedback container for search results.
    echo '<div id="track_search_results" class="bspfy-track-grid"></div>';

// Display current playlist tracks.
echo '<h3 class="bspfy-heading">' . __( 'Playlist Tracks', 'betait-spfy-playlist' ) . '</h3>';
echo '<div id="playlist_tracks_list" class="bspfy-track-list">';

if ( $tracks ) {
    foreach ( $tracks as $track ) {
        // Extract relevant details.
        $artist_name = isset( $track['artists'][0]['name'] ) ? $track['artists'][0]['name'] : __( 'Unknown Artist', 'betait-spfy-playlist' );
        $album_name = isset( $track['album']['name'] ) ? $track['album']['name'] : __( 'Unknown Album', 'betait-spfy-playlist' );
        $track_name = isset( $track['name'] ) ? $track['name'] : __( 'Unknown Track', 'betait-spfy-playlist' );
        $track_uri = isset( $track['uri'] ) ? $track['uri'] : '';

        // Render the track item.
        echo '<div class="bspfy-track-item">';
        echo '<img src="' . esc_url( $track['album']['images'][0]['url'] ?? '' ) . '" alt="' . esc_attr( $album_name ) . '">';
        echo '<div class="track-details">';
        echo '<div class="track-details-artist track-details-space"><strong>Artist:</strong> ' . esc_html( $artist_name ) . '</div>';
        echo '<div class="track-details-album track-details-space"><strong>Album:</strong> ' . esc_html( $album_name ) . '</div>';
        echo '<div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ' . esc_html( $track_name ) . '</div>';
        echo '</div>';
        echo '<div class="track-actions">';
        echo '<div class="track-actions-preview-button" data-uri="' . esc_attr( $track_uri ) . '">Play</div>';
        echo '<button type="button" class="bspfy-remove-button" data-track-id="' . esc_attr( $track['id'] ) . '">' . __( 'Remove', 'betait-spfy-playlist' ) . '</button>';
        echo '</div>';
        echo '</div>';
    }

    $this->log_debug( 'Rendered ' . count( $tracks ) . ' tracks for post ID: ' . $post->ID );
} else {
    echo '<div>' . __( 'No tracks added yet.', 'betait-spfy-playlist' ) . '</div>';
    $this->log_debug( 'No tracks to render for post ID: ' . $post->ID );
}

echo '</div>';
// Spotify Attribution Footer
echo '<div class="bspfy-spotify-attribution">';
echo '<span>' . __( 'BeTA Spfy Playlist is powered by', 'betait-spfy-playlist' ) . '</span>';
echo '<img src="' . plugin_dir_url( __FILE__ ) . '../assets/Spotify_Full_Logo_RGB_Green.png" alt="Spotify Logo" class="bspfy-spotify-logo">';
echo '</div>';





    // Hidden field to store selected tracks.
    echo '<input type="hidden" id="playlist_tracks" name="playlist_tracks" value="' . esc_attr( json_encode( $tracks ) ) . '">';
    $this->log_debug( 'Hidden field for playlist tracks rendered for post ID: ' . $post->ID );
}


	

	 /**
     * Save the playlist tracks meta data.
     *
     * @since    1.0.0
     * @param    int $post_id The ID of the post being saved.
     */
    public function save_playlist_meta( $post_id ) {
        $this->log_debug( 'Saving playlist metadata for post ID: ' . $post_id );

        // Check if nonce is set and valid.
        if ( ! isset( $_POST['playlist_nonce'] ) || ! wp_verify_nonce( $_POST['playlist_nonce'], 'save_playlist_meta' ) ) {
            $this->log_debug( 'Nonce verification failed for post ID: ' . $post_id );
            return;
        }

        // Check if the user has permission to save.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            $this->log_debug( 'User lacks permission to edit post ID: ' . $post_id );
            return;
        }

        // Save the tracks.
        if ( isset( $_POST['playlist_tracks'] ) ) {
            $tracks = sanitize_textarea_field( $_POST['playlist_tracks'] );
            update_post_meta( $post_id, '_playlist_tracks', $tracks );
            $this->log_debug( 'Tracks saved successfully for post ID: ' . $post_id );
        } else {
            $this->log_debug( 'No tracks data to save for post ID: ' . $post_id );
        }
    }

}
