<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://betait.no
 * @since      1.0.0
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PKCE OAuth controller for Spotify
 */
class Betait_Spfy_Playlist_OAuth {

    const SID_COOKIE   = 'bspfy_sid';
    const SID_TTL      = 60 * 60 * 24 * 30; // 30 days
    const TRANSIENT_NS = 'bspfy_oauth_';
    const OPTION_CID   = 'bspfy_client_id';
    const OPTION_SEC   = 'bspfy_client_secret';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('bspfy/v1', '/oauth/start', array(
            'methods'  => 'POST',
            'callback' => array($this, 'start'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('bspfy/v1', '/oauth/callback', array(
            'methods'  => 'GET',
            'callback' => array($this, 'callback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('bspfy/v1', '/oauth/token', array(
            'methods'  => 'GET',
            'callback' => array($this, 'token'),
            'permission_callback' => '__return_true',
        ));
    }

    private function client_id()     { return get_option(self::OPTION_CID, ''); }
    private function client_secret() { return get_option(self::OPTION_SEC, ''); }

    private function redirect_uri() {
        // Bruk REST callback som redirect_uri
        return home_url('/wp-json/bspfy/v1/oauth/callback');
    }

    private function get_sid() {
        if (!empty($_COOKIE[self::SID_COOKIE])) return sanitize_text_field($_COOKIE[self::SID_COOKIE]);
        $sid = wp_generate_uuid4();
        setcookie(self::SID_COOKIE, $sid, array(
            'expires'  => time() + self::SID_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        return $sid;
    }

    private function store_for_sid($sid, $data) {
        set_transient(self::TRANSIENT_NS . $sid, $data, self::SID_TTL);
    }

    private function read_for_sid($sid) {
        return get_transient(self::TRANSIENT_NS . $sid) ?: array();
    }

    private function pkce_pair() {
        $verifier  = wp_generate_password(64, false, false);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return array($verifier, $challenge);
    }

    /** POST /oauth/start */
    public function start(WP_REST_Request $req) {
        $client_id = $this->client_id();
        if (!$client_id) return new WP_REST_Response(array('error'=>'Missing client_id'), 500);

        $sid = $this->get_sid();
        list($verifier, $challenge) = $this->pkce_pair();
        $state = wp_generate_uuid4();

        // Husk verdier midlertidig
        $store = $this->read_for_sid($sid);
        $store['pkce_verifier'] = $verifier;
        $store['state']         = $state;
        $store['redirectBack']  = sanitize_text_field($req->get_param('redirectBack') ?: home_url('/'));
        $this->store_for_sid($sid, $store);

        $params = array(
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $this->redirect_uri(),
            'scope'         => 'streaming user-read-email user-read-private user-read-playback-state user-modify-playback-state',
            'state'         => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        );
        $authorizeUrl = 'https://accounts.spotify.com/authorize?' . http_build_query($params);

        return array('authorizeUrl' => $authorizeUrl);
    }

    /** GET /oauth/callback?code=...&state=... */
    public function callback(WP_REST_Request $req) {
        $sid   = $this->get_sid();
        $state = sanitize_text_field($req->get_param('state'));
        $code  = sanitize_text_field($req->get_param('code'));

        $store = $this->read_for_sid($sid);
        if (empty($store['state']) || $store['state'] !== $state) {
            return $this->render_popup_result(false, 'State mismatch');
        }

        // PKCE token exchange
        $resp = wp_remote_post('https://accounts.spotify.com/api/token', array(
            'body' => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirect_uri(),
                'client_id'     => $this->client_id(),
                'code_verifier' => $store['pkce_verifier'],
            ),
        ));
        if (is_wp_error($resp)) {
            return $this->render_popup_result(false, 'Token request failed');
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['access_token'])) {
            return $this->render_popup_result(false, 'No access token');
        }

        // Lagre tokens
        $tokens = array(
            'access_token'  => $body['access_token'],
            'expires_in'    => time() + intval($body['expires_in'] ?? 3600) - 30,
            'refresh_token' => $body['refresh_token'] ?? '',
            'token_type'    => $body['token_type'] ?? 'Bearer',
        );

        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'bspfy_refresh_token', $tokens['refresh_token']);
            update_user_meta(get_current_user_id(), 'bspfy_access_cache',  $tokens);
        } else {
            $store['tokens'] = $tokens;
            $this->store_for_sid($sid, $store);
        }

        return $this->render_popup_result(true, 'OK', $store['redirectBack'] ?? home_url('/'));
    }

    /** GET /oauth/token */
    public function token(WP_REST_Request $req) {
        // 1) brukerlogikk først
        if (is_user_logged_in()) {
            $uid   = get_current_user_id();
            $cache = get_user_meta($uid, 'bspfy_access_cache', true);
            if (!is_array($cache)) $cache = array();

            if (!empty($cache['access_token']) && time() < intval($cache['expires_in'])) {
                return array('authenticated'=>true, 'access_token'=>$cache['access_token']);
            }
            $refresh = get_user_meta($uid, 'bspfy_refresh_token', true);
            if ($refresh) {
                $new = $this->refresh_with($refresh);
                if (!empty($new['access_token'])) {
                    update_user_meta($uid, 'bspfy_access_cache', $new);
                    if (!empty($new['refresh_token'])) {
                        update_user_meta($uid, 'bspfy_refresh_token', $new['refresh_token']);
                    }
                    return array('authenticated'=>true, 'access_token'=>$new['access_token']);
                }
            }
            return array('authenticated'=>false);
        }

        // 2) anonym sesjon
        $sid   = $this->get_sid();
        $store = $this->read_for_sid($sid);
        $tok   = $store['tokens'] ?? array();

        if (!empty($tok['access_token']) && time() < intval($tok['expires_in'])) {
            return array('authenticated'=>true, 'access_token'=>$tok['access_token']);
        }
        if (!empty($tok['refresh_token'])) {
            $new = $this->refresh_with($tok['refresh_token']);
            if (!empty($new['access_token'])) {
                $store['tokens'] = $new;
                $this->store_for_sid($sid, $store);
                return array('authenticated'=>true, 'access_token'=>$new['access_token']);
            }
        }
        return array('authenticated'=>false);
    }

    private function refresh_with($refresh_token) {
        $resp = wp_remote_post('https://accounts.spotify.com/api/token', array(
            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $this->client_id(),
            ),
        ));
        if (is_wp_error($resp)) return array();
        $b = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($b['access_token'])) return array();

        return array(
            'access_token'  => $b['access_token'],
            'expires_in'    => time() + intval($b['expires_in'] ?? 3600) - 30,
            'refresh_token' => $b['refresh_token'] ?? $refresh_token,
            'token_type'    => $b['token_type'] ?? 'Bearer',
        );
    }

private function render_popup_result($success, $msg, $redirectBack = '') {
    $origin   = esc_js(site_url());
    $payload  = wp_json_encode(array('type'=>'bspfy-auth','success'=>$success,'message'=>$msg));
    $redirect = esc_js($redirectBack ?: site_url('/'));

    $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Spotify Auth</title></head>
<body>
<script>
try {
  if (window.opener) {
    window.opener.postMessage($payload, '$origin');
  }
} catch (e) {}
window.close();
setTimeout(function(){ window.location = '$redirect'; }, 500);
</script>
</body></html>
HTML;

    // Sørg for at absolutt ingenting annet blir skrevet ut:
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }

    nocache_headers();
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');

    echo $html;
    exit; // VIKTIG!
}

}


