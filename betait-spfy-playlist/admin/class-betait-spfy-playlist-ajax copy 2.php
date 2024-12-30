<?php

/**
 * The AJAX handler for the Spotify integration in the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * Handles AJAX operations for the plugin.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_Ajax {

    /**
     * Initialize the class and set up the AJAX hooks.
     *
     * @since    1.0.0
     */
    public function __construct() {
        add_action( 'wp_ajax_search_spotify_tracks', array( $this, 'search_spotify_tracks' ) );
    }

    /**
     * Log debug information if debugging is enabled.
     *
     * @since    1.0.0
     * @param    string $message The message to log.
     */
    private function log_debug( $message ) {
        if ( get_option( 'bspfy_debug', 0 ) ) {
            error_log( '[BeTA iT - Spfy Playlist Debug] ' . $message );
        }
    }

    /**
     * Perform a Spotify track search.
     *
     * @since    1.0.0
     */
	public function search_spotify_tracks() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Unauthorized.', 'betait-spfy-playlist' )
			) );
			return;
		}
	
		if ( ! isset( $_GET['query'] ) || empty( $_GET['query'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'No search query provided.', 'betait-spfy-playlist' )
			) );
			return;
		}
	
		$query = sanitize_text_field( $_GET['query'] );
		$api_handler = new Betait_Spfy_Playlist_API_Handler();
		$response = $api_handler->search_tracks( $query );
	
		if ( isset( $response['error'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'Error fetching tracks from Spotify API.', 'betait-spfy-playlist' ),
				'error'   => $response['error'],
			) );
			return;
		}
	
		if ( isset( $response['success'] ) && $response['success'] && isset( $response['data']['tracks'] ) ) {
			wp_send_json_success( array(
				'message' => __( 'Tracks fetched successfully.', 'betait-spfy-playlist' ),
				'tracks'  => $response['data']['tracks'],
			) );
		} else {
			wp_send_json_error( array(
				'message'  => __( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
				'response' => $response,
			) );
		}
	}
	
	
	
	
}
