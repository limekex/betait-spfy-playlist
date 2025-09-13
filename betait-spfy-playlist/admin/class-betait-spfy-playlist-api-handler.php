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
    //public function search_tracks( $query, $access_token = null ) {
        public function search_tracks( $query, $access_token = null, $type_str = 'track', $limit = 20 ) {
            $query = trim((string)$query);
            $limit = max(1, min(50, (int)$limit)); // Spotify tillater opptil 50

            // Sjekk om kall kom med brukertoken (fra header) – det avgjør 'market'
            $have_user_bearer = !empty($this->get_bearer_from_request());

            // 1) Bearer fra param eller request
            if (!$access_token) {
                $access_token = $this->get_bearer_from_request();
            }

            // 2) Fallback til client_credentials om ingen Bearer
            if (!$access_token) {
                $have_user_bearer = false; // vi vet vi IKKE har brukertoken
                $this->log_debug('No Bearer in request; falling back to client_credentials for search.');
                $tok = $this->get_access_token_client_credentials();
                if (is_array($tok) && isset($tok['error'])) {
                    return array('success'=>false, 'data'=>array('message'=>$tok['error']));
                }
                $access_token = $tok;
            }

            $market = $have_user_bearer ? 'from_token' : 'NO'; // fallback-marked når vi ikke har bruker

            // Hvilke typer ble valgt?
            $types = array_filter(array_map('trim', explode(',', strtolower($type_str))));
            $types = array_values(array_intersect($types, array('track','artist','album')));
            if (!$types) $types = array('track');

            // Små hjelpere
            $seen = array();
            $tracks_out = array();

            $http_get = function($url) use ($access_token) {
                $resp = wp_remote_get($url, array(
                    'headers' => array('Authorization' => 'Bearer ' . $access_token),
                    'timeout' => 12,
                ));
                if (is_wp_error($resp)) return array(null, $resp->get_error_message());
                $code = wp_remote_retrieve_response_code($resp);
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                return array($code === 200 ? $body : null, $code);
            };

            $add_track = function($t) use (&$tracks_out, &$seen) {
                if (empty($t['id']) || isset($seen[$t['id']])) return;
                $seen[$t['id']] = true;
                $tracks_out[] = $t;
            };

            // 1) Direkte track-søk
            if (in_array('track', $types, true)) {
                $q = array(
                    'q'      => $query,
                    'type'   => 'track',
                    'limit'  => $limit,
                    'market' => $market, // 'from_token' gir bedre treffbarhet ift. tilgjengelighet
                );
                $url = $this->api_base . 'search?' . http_build_query($q);
                list($data,) = $http_get($url);
                if (!empty($data['tracks']['items'])) {
                    foreach ($data['tracks']['items'] as $t) $add_track($t);
                }
            }

            // 2) Artist → hent topp-spor for treffende artister
            if (in_array('artist', $types, true)) {
                $q = array('q' => $query, 'type' => 'artist', 'limit' => 3);
                $url = $this->api_base . 'search?' . http_build_query($q);
                list($data,) = $http_get($url);

                if (!empty($data['artists']['items'])) {
                    foreach ($data['artists']['items'] as $a) {
                        if (empty($a['id'])) continue;
                        $urlTop = $this->api_base . 'artists/' . rawurlencode($a['id']) . '/top-tracks?' . http_build_query(array('market'=>$market));
                        list($tops,) = $http_get($urlTop);
                        if (!empty($tops['tracks'])) {
                            foreach ($tops['tracks'] as $t) $add_track($t);
                        }
                    }
                }
            }

            // 3) Album → hent tracks for treffende album, og sett album-cover inn på hvert track
            if (in_array('album', $types, true)) {
                $q = array('q' => $query, 'type' => 'album', 'limit' => 3, 'market' => $market);
                $url = $this->api_base . 'search?' . http_build_query($q);
                list($data,) = $http_get($url);

                if (!empty($data['albums']['items'])) {
                    foreach ($data['albums']['items'] as $alb) {
                        if (empty($alb['id'])) continue;
                        $cover = $alb['images'] ?? array();
                        $urlAlb = $this->api_base . 'albums/' . rawurlencode($alb['id']) . '/tracks?' . http_build_query(array('limit'=> min(10,$limit), 'market'=>$market));
                        list($albtracks,) = $http_get($urlAlb);
                        if (!empty($albtracks['items'])) {
                            foreach ($albtracks['items'] as $t) {
                                if (empty($t['album'])) {
                                    // berik med albumets navn/cover så UI ditt kan vise bilde
                                    $t['album'] = array(
                                        'name'   => $alb['name'] ?? '',
                                        'images' => $cover,
                                    );
                                } elseif (empty($t['album']['images'])) {
                                    $t['album']['images'] = $cover;
                                }
                                $add_track($t);
                            }
                        }
                    }
                }
            }

            // Ferdig
            return array('success'=>true, 'data'=>array('tracks' => array_values($tracks_out)));
        }

}