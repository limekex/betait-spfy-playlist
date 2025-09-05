<?php
class Betait_Spfy_Playlist_API_Handler {

    private $api_base = 'https://api.spotify.com/v1/';
    private $client_id;
    private $client_secret;

    public function __construct() {
        $this->client_id     = get_option( 'bspfy_client_id', '' );
        $this->client_secret = get_option( 'bspfy_client_secret', '' );
    }

    private function log_debug( $message ) {
        if ( get_option( 'bspfy_debug', 0 ) ) {
            error_log( '[BeTA iT - Spfy Playlist API Debug] ' . $message );
        }
    }

    private function credentials_are_set() {
        return ! empty( $this->client_id ) && ! empty( $this->client_secret );
    }

    /** NEW: prøv å lese Bearer-token fra request headers */
        private function get_bearer_from_request() {
            $hdr = '';
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $hdr = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (function_exists('getallheaders')) {
                $all = getallheaders();
                if (!empty($all['Authorization'])) $hdr = $all['Authorization'];
            }
            if ($hdr && stripos($hdr, 'Bearer ') === 0) {
                return trim(substr($hdr, 7));
            }
            return '';
        }


    /** (valgfritt) fortsatt tilgjengelig for fallback */
    private function get_access_token_client_credentials() {
        if (!$this->credentials_are_set()) {
            return array( 'error' => __( 'Spotify API credentials are not set.', 'betait-spfy-playlist' ) );
        }
        $resp = wp_remote_post('https://accounts.spotify.com/api/token', array(
            'body' => array( 'grant_type' => 'client_credentials' ),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
            ),
        ));
        if (is_wp_error($resp)) return array( 'error' => 'Error connecting to Spotify API.' );
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return $data['access_token'] ?? array( 'error' => 'Unable to fetch access token.' );
    }

    /** Oppdatert – bruker Bearer hvis mulig, ellers fallback */
    public function search_tracks( $query, $access_token = null ) {
        $query = (string) $query;

        // 1) Bearer fra param eller request
        if (!$access_token) {
            $access_token = $this->get_bearer_from_request();
        }

        // 2) Fallback til client_credentials hvis ingen Bearer
        if (!$access_token) {
            $this->log_debug('No Bearer in request; falling back to client_credentials for search.');
            $tok = $this->get_access_token_client_credentials();
            if (is_array($tok) && isset($tok['error'])) {
                return array('success'=>false, 'data'=>array('message'=>$tok['error']));
            }
            $access_token = $tok;
        }

        $url = $this->api_base . 'search?q=' . urlencode($query) . '&type=track';
        $resp = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $access_token)));

        if (is_wp_error($resp)) {
            return array('success'=>false, 'data'=>array('message'=>'Error fetching data from Spotify API.'));
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($data['tracks']['items'])) {
            return array('success'=>true, 'data'=>array('tracks'=>$data['tracks']['items']));
        }

        return array(
            'success'=>false,
            'data'=>array(
                'message'=>__( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
                'response'=>$data,
            ),
        );
    }
}
