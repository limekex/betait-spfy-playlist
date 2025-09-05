<?php

/**
 * The API handler for the Spotify integration in the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * Handles API requests to Spotify.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_API_Handler {

    /**
     * The Spotify API base URL.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $api_base    The base URL for Spotify API.
     */
    private $api_base = 'https://api.spotify.com/v1/';

    /**
     * Spotify client ID.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $client_id    The Spotify client ID.
     */
    private $client_id;

    /**
     * Spotify client secret.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $client_secret    The Spotify client secret.
     */
    private $client_secret;

    /**
     * Initialize the class and set its properties from saved settings.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->client_id = get_option( 'bspfy_client_id', '' );
        $this->client_secret = get_option( 'bspfy_client_secret', '' );
    }

    /**
     * Log debug information if debugging is enabled.
     *
     * @since    1.0.0
     * @param    string $message The message to log.
     */
    private function log_debug( $message ) {
        if ( get_option( 'bspfy_debug', 0 ) ) {
            error_log( '[BeTA iT - Spfy Playlist API Debug] ' . $message );
        }
    }

    /**
     * Check if credentials are set.
     *
     * @since    1.0.0
     * @return   bool    True if credentials are set, false otherwise.
     */
    private function credentials_are_set() {
        $credentials_set = ! empty( $this->client_id ) && ! empty( $this->client_secret );
        $this->log_debug( 'Credentials are ' . ( $credentials_set ? 'set.' : 'not set.' ) );
        return $credentials_set;
    }

    /**
     * Fetch the Spotify API access token.
     *
     * @since    1.0.0
     * @return   string|array    The access token or an error message.
     */
    private function get_access_token() {
        if ( ! $this->credentials_are_set() ) {
            $this->log_debug( 'API credentials are missing.' );
            return array( 'error' => __( 'Spotify API credentials are not set. Please configure them in the plugin settings.', 'betait-spfy-playlist' ) );
        }

        $url = 'https://accounts.spotify.com/api/token';
        $args = array(
            'body'    => array(
                'grant_type' => 'client_credentials',
            ),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
            ),
        );

        $this->log_debug( 'Fetching access token from Spotify API.' );
        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'Error connecting to Spotify API: ' . $response->get_error_message() );
            return array( 'error' => __( 'Error connecting to Spotify API.', 'betait-spfy-playlist' ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['access_token'] ) ) {
            $this->log_debug( 'Access token retrieved successfully.' );
        } else {
            $this->log_debug( 'Failed to retrieve access token: ' . print_r( $data, true ) );
        }

        return $data['access_token'] ?? array( 'error' => __( 'Unable to fetch access token from Spotify API.', 'betait-spfy-playlist' ) );
    }

    /**
     * Search for tracks using the Spotify API.
     *
     * @since    1.0.0
     * @param    string $query The search query.
     * @return   array         The search results or an error message.
     */
	public function search_tracks( $query, $access_token ) {
        $url = $this->api_base . 'search?q=' . urlencode( $query ) . '&type=track';
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        );
    
        $response = wp_remote_get( $url, $args );
    
        if ( is_wp_error( $response ) ) {
            $this->log_debug( 'Error fetching data from Spotify API: ' . $response->get_error_message() );
            return array(
                'success' => false,
                'data'    => array(
                    'message' => __( 'Error fetching data from Spotify API.', 'betait-spfy-playlist' ),
                ),
            );
        }
    
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
    
        if ( isset( $data['tracks']['items'] ) && ! empty( $data['tracks']['items'] ) ) {
            $this->log_debug( 'Tracks retrieved successfully: ' . count( $data['tracks']['items'] ) );
            return array(
                'success' => true,
                'data'    => array(
                    'tracks' => $data['tracks']['items'],
                ),
            );
        }
    
        $this->log_debug( 'No tracks found in response: ' . print_r( $data, true ) );
        return array(
            'success' => false,
            'data'    => array(
                'message' => __( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
                'response' => $data,
            ),
        );
    }
    
	
	
	
	
}
