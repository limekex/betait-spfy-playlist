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
		// Verify user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'betait-spfy-playlist' ) ) );
			return;
		}
	
		// Validate and sanitize the query parameter.
		if ( ! isset( $_GET['query'] ) || empty( $_GET['query'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No search query provided.', 'betait-spfy-playlist' ) ) );
			return;
		}
	
		$query = sanitize_text_field( $_GET['query'] );
	
		// Log the query for debugging.
		if ( get_option( 'bspfy_debug', 0 ) ) {
			error_log( '[BeTA iT - Spfy Playlist Debug] Search query: ' . $query );
		}
	
		// Initialize the API handler and perform the search.
		$api_handler = new Betait_Spfy_Playlist_API_Handler();
		$response = $api_handler->search_tracks( $query );
	
		// Log the response for debugging.
		if ( get_option( 'bspfy_debug', 0 ) ) {
			error_log( '[BeTA iT - Spfy Playlist Debug] API response: ' . print_r( $response, true ) );
		}
	
		// Ensure the response contains tracks.
		if ( isset( $response['error'] ) ) {
			wp_send_json_error( array( 
				'message' => __( 'Spotify API Error:', 'betait-spfy-playlist' ) . ' ' . $response['error'], 
				'response' => $response 
			));
			return;
		}
	
		if ( ! isset( $response['tracks']['items'] ) ) {
			wp_send_json_error( array( 
				'message' => __( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
				'response' => $response
			));
			return;
		}
	
		wp_send_json_success( $response );
	}
	
}
