<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PKCE OAuth controller for Spotify (v2 hardened)
 */
class Betait_Spfy_Playlist_OAuth {

    const SID_COOKIE    = 'bspfy_sid';
    const SID_TTL       = 60 * 60 * 24 * 30; // 30 days
    const TRANSIENT_NS  = 'bspfy_oauth_';
    const OPTION_CID    = 'bspfy_client_id';
    const OPTION_SEC    = 'bspfy_client_secret';

    // NEW: httpOnly refresh cookie (encrypted)
    const RT_COOKIE     = 'bspfy_rt';
    const RT_TTL        = 60 * 60 * 24 * 30; // 30 days

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_pre_serve_request', array($this, 'disable_rest_caching'), 10, 4);
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

        // NEW: Optional explicit refresh endpoint (single-flight)
        register_rest_route('bspfy/v1', '/oauth/refresh', array(
            'methods'  => 'POST',
            'callback' => array($this, 'force_refresh'),
            'permission_callback' => '__return_true',
        ));

        // NEW: Logout/cleanup (clear cookies + caches)
        register_rest_route('bspfy/v1', '/oauth/logout', array(
            'methods'  => 'POST',
            'callback' => array($this, 'logout'),
            'permission_callback' => '__return_true',
        ));
    }

    private function client_id()     { return get_option(self::OPTION_CID, ''); }
    private function client_secret() { return get_option(self::OPTION_SEC, ''); }

    private function redirect_uri() {
        return home_url('/wp-json/bspfy/v1/oauth/callback');
    }

    // ===== Cookie helpers =====

    private function cookie_samesite() {
        // Default: 0 = Lax, 1 = Strict
        $strict   = (int) get_option('bspfy_strict_samesite', 0) === 1;
        $samesite = $strict ? 'Strict' : 'Lax';
        $samesite = apply_filters('bspfy_cookie_samesite', $samesite);
        if (strcasecmp($samesite, 'None') === 0 && !is_ssl()) {
            // None krever Secure
            $samesite = 'Lax';
        }
        return $samesite;
    }
    private function delete_cookie($name) {
        $samesite = $this->cookie_samesite();
        $secure   = $this->cookie_secure($samesite);
        $domain   = $this->cookie_domain();

        // 1) Slett med eksplisitt domain (superdomene-cookie)
        if ($domain) {
            setcookie($name, '', array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => $samesite,
            ));
        }

        // 2) Slett host-only cookie (ingen domain)
        setcookie($name, '', array(
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ));
    }

    public function disable_rest_caching( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( strpos($route, '/bspfy/v1/oauth/') === 0 ) {
            // Kjerne no-cache (WP)
            nocache_headers();

            // Ekstra – tydelig for proxy/CDN/LiteSpeed
            if ( ! headers_sent() ) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
                header('X-LiteSpeed-Cache-Control: no-cache');
                header('Vary: Cookie, Origin, User-Agent');
            }
        }
        return $served;
    }


    private function cookie_secure($samesite) {
        return is_ssl() || strcasecmp($samesite, 'None') === 0;
    }

        private function set_sid_cookie($sid) {
            $samesite = $this->cookie_samesite();
            $secure   = $this->cookie_secure($samesite);
            $domain   = $this->cookie_domain();

            $opts = array(
                'expires'  => time() + self::SID_TTL,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => $samesite,
            );
            if ($domain) { $opts['domain'] = $domain; }

            return setcookie(self::SID_COOKIE, $sid, $opts);
        }

    private function get_sid() {
        $sid = !empty($_COOKIE[self::SID_COOKIE]) ? sanitize_text_field($_COOKIE[self::SID_COOKIE]) : wp_generate_uuid4();
        // Forleng alltid
        $this->set_sid_cookie($sid);
        return $sid;
    }

    // ===== Minimal crypto for refresh cookie (AES-256-GCM) =====
    // NEW
    private function crypto_key() {
        // Avled en 32B nøkkel fra WP salts/keys
        $material = AUTH_KEY . SECURE_AUTH_KEY . AUTH_SALT . SECURE_AUTH_SALT;
        return hash('sha256', $material, true);
    }

    // NEW
    private function enc_refresh($plain) {
        if (!$plain) return '';
        $key = $this->crypto_key();
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return '';
        return base64_encode($iv . $tag . $ct);
    }
    private function dec_refresh($b64) {
        if (!$b64) return '';
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 12 + 16) return '';
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $key = $this->crypto_key();
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt ?: '';
    }

    private function set_rt_cookie($refresh_token) {
        if (!$refresh_token) return false;
        $enc      = $this->enc_refresh($refresh_token);
        $samesite = $this->cookie_samesite();
        $secure   = $this->cookie_secure($samesite);
        $domain   = $this->cookie_domain();

        $opts = array(
            'expires'  => time() + self::RT_TTL,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        );
        if ($domain) { $opts['domain'] = $domain; }

        return setcookie(self::RT_COOKIE, $enc, $opts);
    }

    private function get_rt_cookie() {
        if (empty($_COOKIE[self::RT_COOKIE])) return '';
        $val = sanitize_text_field($_COOKIE[self::RT_COOKIE]);
        return $this->dec_refresh($val);
    }

    private function clear_rt_cookie() { $this->delete_cookie(self::RT_COOKIE); }

    private function store_for_sid($sid, $data) {
        set_transient(self::TRANSIENT_NS . $sid, $data, self::SID_TTL);
    }

    private function read_for_sid($sid) {
        return get_transient(self::TRANSIENT_NS . $sid) ?: array();
    }

    private function delete_for_sid($sid) {
        delete_transient(self::TRANSIENT_NS . $sid);
    }

    private function pkce_pair() {
        $verifier  = wp_generate_password(64, false, false);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return array($verifier, $challenge);
    }
    private function rest_resp( $data, $status = 200 ) {
        $r = new WP_REST_Response( $data, $status );
        $r->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $r->header('Pragma', 'no-cache');
        $r->header('Expires', '0');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        $r->header('Vary', 'Cookie, Origin, User-Agent');
        return $r;
    }

    /** POST /oauth/start */
    public function start(WP_REST_Request $req) {
        $client_id = $this->client_id();
        // if (!$client_id) return new WP_REST_Response(array('error'=>'Missing client_id'), 500);
        if (!$client_id) return $this->rest_resp(array('error'=>'Missing client_id'), 500);

        $sid = $this->get_sid();
        list($verifier, $challenge) = $this->pkce_pair();
        $state = wp_generate_uuid4();

        $store = $this->read_for_sid($sid);
        $store['pkce_verifier'] = $verifier;
        $store['state']         = $state;
        // Sikrere redirectBack (same-origin, renset)
            $raw_redirect = $req->get_param('redirectBack');
            $redirect     = esc_url_raw( $raw_redirect ?: home_url('/') );

            // Tillat bare relative eller samme host
            $site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
            $redir_host = wp_parse_url( $redirect,   PHP_URL_HOST );

            if ( $redir_host && ! hash_equals( (string) $site_host, (string) $redir_host ) ) {
                $redirect = home_url('/');
            }

            $store['redirectBack'] = $redirect;

        $this->store_for_sid($sid, $store);

        $params = array(
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => $this->redirect_uri(),
            'scope'                 => 'streaming user-read-email user-read-private user-read-playback-state user-modify-playback-state',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        );
        $authorizeUrl = 'https://accounts.spotify.com/authorize?' . http_build_query($params);

        // return array('authorizeUrl' => $authorizeUrl);
        return $this->rest_resp(array('authorizeUrl' => $authorizeUrl), 200);
    }

    /** GET /oauth/callback?code=...&state=... */
        public function callback(WP_REST_Request $req) {
            add_filter('bspfy_cookie_samesite', function($v){ return 'Lax'; }, 99);

            $sid   = $this->get_sid();
            $state = sanitize_text_field($req->get_param('state'));
            $code  = sanitize_text_field($req->get_param('code'));

            $store = $this->read_for_sid($sid);
            if (empty($store['state']) || $store['state'] !== $state) {
                return $this->render_popup_result(false, 'State mismatch');
            }

            // SIKKER redirectBack-verdi vi kan bruke og så rydde bort
            $raw_redirect = $req->get_param('redirectBack');
            $redirectBack = esc_url_raw( $raw_redirect ?: ($store['redirectBack'] ?? home_url('/')) );
            $site_host    = wp_parse_url( home_url(),    PHP_URL_HOST );
            $redir_host   = wp_parse_url( $redirectBack, PHP_URL_HOST );
            if ( $redir_host && ! hash_equals((string)$site_host, (string)$redir_host) ) {
                $redirectBack = home_url('/');
            }

            // PKCE token exchange (uendret) ...
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

            $tokens = array(
                'access_token'  => $body['access_token'],
                'expires_in'    => time() + intval($body['expires_in'] ?? 3600) - 30,
                'refresh_token' => $body['refresh_token'] ?? '',
                'token_type'    => $body['token_type'] ?? 'Bearer',
            );

            // HttpOnly refresh-cookie
            if (!empty($tokens['refresh_token'])) {
                $this->set_rt_cookie($tokens['refresh_token']);
            }

            if (is_user_logged_in()) {
                $uid = get_current_user_id();
                if (!empty($tokens['refresh_token'])) {
                    update_user_meta($uid, 'bspfy_refresh_token', $tokens['refresh_token']);
                }
                update_user_meta($uid, 'bspfy_access_cache', array(
                    'access_token' => $tokens['access_token'],
                    'expires_in'   => $tokens['expires_in'],
                    'token_type'   => $tokens['token_type'],
                    // NB: refresh_token lagres i egen meta, ikke her
                ));

                // ⬅️ RYDD PKCE-rester for innlogget brukerkontekst
                $this->delete_for_sid($sid); // hele transienten er overflødig når bruker er innlogget
            } else {
                // Gjest: behold tokens i transient, men fjern PKCE + ikke lagre refresh_token der
                $clean = $this->read_for_sid($sid);
                unset($clean['pkce_verifier'], $clean['state']);   // ⬅️ fjern PKCE-rester
                unset($clean['redirectBack']);                     // ikke nødvendig lenger

                $clean['tokens'] = array(
                    'access_token' => $tokens['access_token'],
                    'expires_in'   => $tokens['expires_in'],
                    'token_type'   => $tokens['token_type'],
                    // bevisst IKKE refresh_token – bruk kun HttpOnly cookie ved refresh
                );
                $this->store_for_sid($sid, $clean);
            }

            return $this->render_popup_result(true, 'OK', $redirectBack);
        }


    /** GET /oauth/token */
    public function token(WP_REST_Request $req) {
            $sid = $this->get_sid();
            $dbg = (int) $req->get_param('dbg') === 1;

            // Små helpers for debug-felter
            $has_rt_cookie = !empty($_COOKIE[self::RT_COOKIE]);
            $now = time();

            // 1) Innlogget bruker → usermeta er sannhet
            if (is_user_logged_in()) {
                $uid        = get_current_user_id();
                $cache      = get_user_meta($uid, 'bspfy_access_cache', true);
                $user_has_rt= (bool) get_user_meta($uid, 'bspfy_refresh_token', true);
                if (!is_array($cache)) $cache = array();

                $exp_ts  = isset($cache['expires_in']) ? (int) $cache['expires_in'] : 0;
                $exp_ok  = $exp_ts > $now;

                // 1a) Gyldig access fra cache
                if (!empty($cache['access_token']) && $exp_ok) {
                    $out = array(
                        'authenticated' => true,
                        'access_token'  => $cache['access_token'],
                    );
                    if ($dbg) {
                        $out['source']               = 'user_access_cache';
                        $out['user_id']              = $uid;
                        $out['sid']                  = $sid;
                        $out['rt_user']              = $user_has_rt;
                        $out['rt_cookie']            = $has_rt_cookie;
                        $out['cache_expires_at']     = $exp_ts;
                        $out['cache_seconds_left']   = max(0, $exp_ts - $now);
                        $out['wp_is_user'] = is_user_logged_in();
                        $out['wp_user_id'] = get_current_user_id();
                    }
                    // return $out;
                    return $this->rest_resp($out, 200);
                }

                // 1b) Prøv refresh (single-flight)
                $new = $this->with_refresh_lock("uid_$uid", function() use ($uid) {
                    $refresh = get_user_meta($uid, 'bspfy_refresh_token', true);
                    if (!$refresh) $refresh = $this->get_rt_cookie();
                    if (!$refresh) return array();

                    $n = $this->refresh_with($refresh);
                    if (empty($n['access_token'])) return array();

                    // Oppdater cache + persistér RT (ny eller eksisterende)
                    update_user_meta($uid, 'bspfy_access_cache', $n);
                    $store_rt = !empty($n['refresh_token']) ? $n['refresh_token'] : $refresh;
                    update_user_meta($uid, 'bspfy_refresh_token', $store_rt);
                    $this->set_rt_cookie($store_rt);

                    return $n;
                    //return $this->rest_resp($out, 200);
                });

                if (!empty($new['access_token'])) {
                    $out = array(
                        'authenticated' => true,
                        'access_token'  => $new['access_token'],
                    );
                    if ($dbg) {
                        $exp_ts_new = isset($new['expires_in']) ? (int) $new['expires_in'] : 0;
                        $out['source']             = 'user_refresh';
                        $out['user_id']            = $uid;
                        $out['sid']                = $sid;
                        $out['rt_user']            = (bool) get_user_meta($uid, 'bspfy_refresh_token', true);
                        $out['rt_cookie']          = !empty($_COOKIE[self::RT_COOKIE]);
                        $out['cache_expires_at']   = $exp_ts_new;
                        $out['cache_seconds_left'] = max(0, $exp_ts_new - $now);
                        $out['wp_is_user'] = is_user_logged_in();
                        $out['wp_user_id'] = get_current_user_id();
                    }
                    //return $out;
                    return $this->rest_resp($out, 200);
                }

                // 1c) Ingen token
                $out = array('authenticated'=>false);
                if ($dbg) {
                    $out['source']     = 'user_none';
                    $out['user_id']    = $uid;
                    $out['sid']        = $sid;
                    $out['rt_user']    = $user_has_rt;
                    $out['rt_cookie']  = $has_rt_cookie;
                    $out['wp_is_user'] = is_user_logged_in();
                    $out['wp_user_id'] = get_current_user_id();
                }
                //return new WP_REST_Response($out, 401);
                return $this->rest_resp($out, 401);
            }

            // 2) Anonym sesjon → refresh-cookie er sannhet; transient er cache
            $store = $this->read_for_sid($sid);
            $tok   = $store['tokens'] ?? array();

            // 2a) Gyldig access fra transient-cache
            if (!empty($tok['access_token']) && $now < (int)($tok['expires_in'] ?? 0)) {
                $out = array(
                    'authenticated' => true,
                    'access_token'  => $tok['access_token'],
                );
                if ($dbg) {
                    $out['source']             = 'guest_cache';
                    $out['sid']                = $sid;
                    $out['rt_cookie']          = $has_rt_cookie;
                    $out['cache_expires_at']   = (int) $tok['expires_in'];
                    $out['cache_seconds_left'] = max(0, (int)$tok['expires_in'] - $now);
                    $out['wp_is_user'] = is_user_logged_in();
                    $out['wp_user_id'] = get_current_user_id();
                }
                //return $out;
                return $this->rest_resp($out, 200);
            }

            // 2b) Prøv refresh for gjest (RT-cookie eller lagret refresh i transient)
            $new = $this->with_refresh_lock("sid_$sid", function() use ($sid, $tok, $store) {
                $refresh = !empty($tok['refresh_token']) ? $tok['refresh_token'] : $this->get_rt_cookie();
                if (!$refresh) return array();

                $n = $this->refresh_with($refresh);
                if (empty($n['access_token'])) return array();

                // Oppdater transient + RT-cookie (om ny)
                $s = $store;
                $s['tokens'] = $n;
                $this->store_for_sid($sid, $s);
                $this->set_rt_cookie($n['refresh_token'] ?? $refresh);

                return $n;
                //return $this->rest_resp($out, 200);
            });

            if (!empty($new['access_token'])) {
                $out = array(
                    'authenticated' => true,
                    'access_token'  => $new['access_token'],
                );
                if ($dbg) {
                    $exp_ts_new = isset($new['expires_in']) ? (int) $new['expires_in'] : 0;
                    $out['source']             = 'guest_refresh';
                    $out['sid']                = $sid;
                    $out['rt_cookie']          = !empty($_COOKIE[self::RT_COOKIE]);
                    $out['cache_expires_at']   = $exp_ts_new;
                    $out['cache_seconds_left'] = max(0, $exp_ts_new - $now);
                    $out['wp_is_user'] = is_user_logged_in();
                    $out['wp_user_id'] = get_current_user_id();
                }
                //return $out;
                return $this->rest_resp($out, 200);
            }

            // 2c) Ingen token for gjest
            $out = array('authenticated'=>false);
            if ($dbg) {
                $out['source']    = 'guest_none';
                $out['sid']       = $sid;
                $out['rt_cookie'] = $has_rt_cookie;
                $out['wp_is_user'] = is_user_logged_in();
                $out['wp_user_id'] = get_current_user_id();
            }
            //return new WP_REST_Response($out, 401);
            return $this->rest_resp($out, 401);
        }


        // Legg til i klassen:
    private function cookie_domain() {
        // Bruk WP-konfig hvis satt, ellers domenet fra site_url
        $d = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : parse_url(site_url(), PHP_URL_HOST);
        // Tillat override
        $d = apply_filters('bspfy_cookie_domain', $d);
        // Returner tom streng hvis no-match, ellers domenet
        return is_string($d) && $d !== '' ? $d : '';
    }

    // NEW: explicit refresh endpoint (optional)
    public function force_refresh(WP_REST_Request $req) {
        $res = $this->token($req);
        return $res;
    }

    // NEW: logout/cleanup
        public function logout(WP_REST_Request $req) {
            // 1) Les SID direkte fra cookie (IKKE get_sid() – den fornyer cookien)
            $sid = !empty($_COOKIE[self::SID_COOKIE]) ? sanitize_text_field($_COOKIE[self::SID_COOKIE]) : '';

            // 2) Rydd anonym session-cache
            if ($sid) {
                $this->delete_for_sid($sid);
            }

            // 3) Rydd innlogget bruker (server truth)
            if (is_user_logged_in()) {
                $uid = get_current_user_id();
                delete_user_meta($uid, 'bspfy_access_cache');
                delete_user_meta($uid, 'bspfy_refresh_token');
            }

            // 4) Slett cookies med korrekt domain/flags
            $this->delete_cookie(self::SID_COOKIE);
            $this->delete_cookie(self::RT_COOKIE);

           // return array('ok' => true);
           return $this->rest_resp(array('ok' => true), 200);
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

    // NEW: simple single-flight using a transient lock (short)
    private function with_refresh_lock($key, $fn) {
        $lock = self::TRANSIENT_NS . 'lock_' . $key;
        $tries = 0;
        while (get_transient($lock) && $tries < 40) { // ~4s max
            usleep(100000);
            $tries++;
        }
        set_transient($lock, 1, 10);
        try {
            return $fn();
        } finally {
            delete_transient($lock);
        }
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
                    try { window.close(); } catch(e){}
                    setTimeout(function(){ window.location = '$redirect'; }, 500);
                    </script>
                    </body></html>
                    HTML;

        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}
