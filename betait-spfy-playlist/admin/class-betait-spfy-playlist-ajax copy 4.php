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
        add_action( 'wp_ajax_save_spotify_access_token', array( $this, 'save_spotify_access_token' ) );
    }

    /**
     * Log debug information if debugging is enabled.
     *
     * @since    1.0.0
     * @param    string $message The message to log.
     */
    private function log_debug( $message ) {
        if ( get_option( 'bspfy_debug', 0 ) ) {
            error_log( '[BeTA iT - Spfy Playlist AJAX Debug] ' . $message );
        }
    }

    /**
     * Perform a Spotify track search.
     *
     * @since    1.0.0
     */
    public function search_spotify_tracks() {
        $this->log_debug('Incoming AJAX request: ' . print_r($_REQUEST, true));
    
        // Sjekk for tilgangstoken i headeren
        $headers = getallheaders();
        if ( isset( $headers['Authorization'] ) && preg_match( '/Bearer\s(\S+)/', $headers['Authorization'], $matches ) ) {
            $access_token = sanitize_text_field( $matches[1] );
        } else {
            $this->log_debug( 'No access token provided in Authorization header.' );
            wp_send_json_error( array(
                'message' => __( 'Access token is required for this request.', 'betait-spfy-playlist' )
            ) );
            return;
        }
    
        if ( ! isset( $_POST['query'] ) || empty( $_POST['query'] ) ) {
            $this->log_debug( 'No search query provided in Spotify track search.' );
            wp_send_json_error( array(
                'message' => __( 'No search query provided.', 'betait-spfy-playlist' )
            ) );
            return;
        }
    
        $query = sanitize_text_field( $_POST['query'] );
        $api_handler = new Betait_Spfy_Playlist_API_Handler();
        $response = $api_handler->search_tracks( $query, $access_token );
    
        if ( isset( $response['error'] ) ) {
            $this->log_debug( 'Error fetching tracks from Spotify API: ' . $response['error'] );
            wp_send_json_error( array(
                'message' => __( 'Error fetching tracks from Spotify API.', 'betait-spfy-playlist' ),
                'error'   => $response['error'],
            ) );
            return;
        }
    
        if ( isset( $response['success'] ) && $response['success'] && isset( $response['data']['tracks'] ) ) {
            $this->log_debug( 'Tracks fetched successfully from Spotify API.' );
            wp_send_json_success( array(
                'message' => __( 'Tracks fetched successfully.', 'betait-spfy-playlist' ),
                'tracks'  => $response['data']['tracks'],
            ) );
        } else {
            $this->log_debug( 'No tracks found in the Spotify response: ' . print_r( $response, true ) );
            wp_send_json_error( array(
                'message'  => __( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
                'response' => $response,
            ) );
        }
    }
    
    

    /**
     * Save Spotify access token.
     *
     * @since    1.0.0
     */
	public function save_spotify_access_token() {
		if ( ! isset( $_POST['access_token'] ) ) {
			$this->log_debug( 'No access token provided in save_spotify_access_token AJAX call.' );
			wp_send_json_error( array( 'message' => __( 'No access token provided.', 'betait-spfy-playlist' ) ) );
			return;
		}
	
		$access_token = sanitize_text_field( $_POST['access_token'] );
		$current_user_id = get_current_user_id();
	
		if ( ! $current_user_id ) {
			$this->log_debug( 'User not logged in during save_spotify_access_token AJAX call.' );
			wp_send_json_error( array( 'message' => __( 'User not logged in.', 'betait-spfy-playlist' ) ) );
			return;
		}
	
		// Save the access token to user meta
		update_user_meta( $current_user_id, 'spotify_access_token', $access_token );
		$this->log_debug( 'Access token saved successfully for user ID: ' . $current_user_id );
	
		// Fetch the Spotify user profile
		$response = wp_remote_get( 'https://api.spotify.com/v1/me', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		) );
	
		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'Error fetching Spotify user profile: ' . $response->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Error fetching Spotify user profile.', 'betait-spfy-playlist' ) ) );
			return;
		}
	
		$body = wp_remote_retrieve_body( $response );
		$user_data = json_decode( $body, true );
	
		if ( isset( $user_data['error'] ) ) {
			$this->log_debug( 'Spotify API returned an error: ' . $user_data['error']['message'] );
			wp_send_json_error( array( 'message' => __( 'Spotify API error: ', 'betait-spfy-playlist' ) . $user_data['error']['message'] ) );
			return;
		}
	
		if ( isset( $user_data['display_name'] ) ) {
			$spotify_user_name = sanitize_text_field( $user_data['display_name'] );
			update_user_meta( $current_user_id, 'spotify_user_name', $spotify_user_name );
			$this->log_debug( 'Spotify username saved successfully for user ID: ' . $current_user_id );
		} else {
			$this->log_debug( 'Spotify username not available in user profile.' );
		}
	
		wp_send_json_success( array(
			'message' => __( 'Access token and Spotify username saved successfully.', 'betait-spfy-playlist' ),
			'spotify_user_name' => $spotify_user_name ?? __( 'Unknown User', 'betait-spfy-playlist' ),
		) );
	}
	
	
}

