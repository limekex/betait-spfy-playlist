<?php
/**
 * Spotify PKCE OAuth controller (hardened) for BeTA iT – Spotify Playlist.
 *
 * Responsibilities:
 * - Authorize via PKCE (no client secret on the client).
 * - Store short-lived access tokens and refresh tokens securely.
 * - Provide REST endpoints used by the frontend.
 * - Handle cookies with appropriate SameSite/Secure/httpOnly flags.
 * - Build scopes dynamically to enable future features (playlist save, cover upload).
 *
 * Endpoints (all under /wp-json/bspfy/v1/oauth):
 * - POST  /start      => Begin PKCE auth, returns authorizeUrl
 * - GET   /callback   => OAuth redirect URI (popup target)
 * - GET   /token      => Returns a valid access_token or 401 if not authenticated
 * - POST  /refresh    => Optional explicit refresh; delegates to /token
 * - POST  /logout     => Clears cookies and server-side caches
 * - GET   /health     => Lightweight diagnostics (no secrets)
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Betait_Spfy_Playlist_OAuth {

	/** Session cookie name (httpOnly). */
	const SID_COOKIE = 'bspfy_sid';
	/** Session cookie TTL (seconds). */
	const SID_TTL    = 60 * 60 * 24 * 30; // 30 days

	/** Namespacing for transients (session store). */
	const TRANSIENT_NS = 'bspfy_oauth_';

	/** Options for Spotify app credentials. */
	const OPTION_CID = 'bspfy_client_id';
	const OPTION_SEC = 'bspfy_client_secret';

	/** Encrypted httpOnly refresh-token cookie. */
	const RT_COOKIE = 'bspfy_rt';
	/** Refresh cookie TTL (seconds). */
	const RT_TTL    = 60 * 60 * 24 * 30; // 30 days

	/**
	 * Bootstrap: register REST routes and disable caching for auth endpoints.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'disable_rest_caching' ), 10, 4 );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'bspfy/v1',
			'/oauth/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'bspfy/v1',
			'/oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'bspfy/v1',
			'/oauth/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);

		// Optional explicit refresh endpoint (single-flight).
		register_rest_route(
			'bspfy/v1',
			'/oauth/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'force_refresh' ),
				'permission_callback' => '__return_true',
			)
		);

		// Logout/cleanup (clear cookies + caches).
		register_rest_route(
			'bspfy/v1',
			'/oauth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => '__return_true',
			)
		);

		// Lightweight diagnostics.
		register_rest_route(
			'bspfy/v1',
			'/oauth/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** @return string */
	private function client_id() {
		return get_option( self::OPTION_CID, '' );
	}

	/** @return string */
	private function client_secret() {
		return get_option( self::OPTION_SEC, '' );
	}

	/** @return string */
	private function redirect_uri() {
		return home_url( '/wp-json/bspfy/v1/oauth/callback' );
	}

	// =============================================================================
	// Cookie / Caching helpers
	// =============================================================================

	/**
	 * Force no-store on OAuth endpoints (for proxies/CDN/LSCache).
	 */
	public function disable_rest_caching( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( strpos( $route, '/bspfy/v1/oauth/' ) === 0 ) {
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );
				header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				header( 'Vary: Cookie, Origin, User-Agent' );
			}
		}
		return $served;
	}

	/**
	 * Compute SameSite policy (filterable).
	 *
	 * @return string One of Lax|Strict|None (None requires Secure).
	 */
	private function cookie_samesite() {
		$strict   = (int) get_option( 'bspfy_strict_samesite', 0 ) === 1;
		$samesite = $strict ? 'Strict' : 'Lax';
		$samesite = apply_filters( 'bspfy_cookie_samesite', $samesite );
		if ( strcasecmp( $samesite, 'None' ) === 0 && ! is_ssl() ) {
			// Browsers require Secure when SameSite=None.
			$samesite = 'Lax';
		}
		return $samesite;
	}

	/** @return bool */
	private function cookie_secure( $samesite ) {
		return is_ssl() || strcasecmp( $samesite, 'None' ) === 0;
	}

	/**
	 * Domain for cookies (filterable). Empty string => host-only cookie.
	 *
	 * @return string
	 */
	private function cookie_domain() {
		$domain = ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : parse_url( site_url(), PHP_URL_HOST );
		$domain = apply_filters( 'bspfy_cookie_domain', $domain );
		return ( is_string( $domain ) && $domain !== '' ) ? $domain : '';
	}

	/** @return bool */
	private function set_sid_cookie( $sid ) {
		$samesite = $this->cookie_samesite();
		$secure   = $this->cookie_secure( $samesite );
		$domain   = $this->cookie_domain();

		$opts = array(
			'expires'  => time() + self::SID_TTL,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => $samesite,
		);
		if ( $domain ) {
			$opts['domain'] = $domain;
		}

		return setcookie( self::SID_COOKIE, $sid, $opts );
	}

	/** @return string */
	private function get_sid() {
		$sid = ! empty( $_COOKIE[ self::SID_COOKIE ] ) ? sanitize_text_field( $_COOKIE[ self::SID_COOKIE ] ) : wp_generate_uuid4();
		// Prolong on each read.
		$this->set_sid_cookie( $sid );
		return $sid;
	}

	/**
	 * Delete cookie both with and without explicit domain.
	 *
	 * @param string $name Cookie name.
	 * @return void
	 */
	private function delete_cookie( $name ) {
		$samesite = $this->cookie_samesite();
		$secure   = $this->cookie_secure( $samesite );
		$domain   = $this->cookie_domain();

		if ( $domain ) {
			setcookie(
				$name,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => '/',
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => $samesite,
				)
			);
		}

		setcookie(
			$name,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => $samesite,
			)
		);
	}

	/**
	 * Standardized REST response with no-store headers.
	 *
	 * @param mixed $data
	 * @param int   $status
	 * @return WP_REST_Response
	 */
	private function rest_resp( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$r->header( 'Pragma', 'no-cache' );
		$r->header( 'Expires', '0' );
		$r->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		$r->header( 'Vary', 'Cookie, Origin, User-Agent' );
		return $r;
	}

	// =============================================================================
	// Minimal crypto for refresh cookie (AES-256-GCM)
	// =============================================================================

	/** @return string 32-byte key material derived from WP salts */
	private function crypto_key() {
		$material = AUTH_KEY . SECURE_AUTH_KEY . AUTH_SALT . SECURE_AUTH_SALT;
		return hash( 'sha256', $material, true );
	}

	/** @return string base64(iv|tag|ciphertext) or '' on failure */
	private function enc_refresh( $plain ) {
		if ( ! $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key = $this->crypto_key();
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( $ct === false ) {
			return '';
		}
		return base64_encode( $iv . $tag . $ct );
	}

	/** @return string plaintext or '' on failure */
	private function dec_refresh( $b64 ) {
		if ( ! $b64 || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( $b64, true );
		if ( $raw === false || strlen( $raw ) < 28 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$key = $this->crypto_key();
		$pt  = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return $pt ?: '';
	}

	/** @return bool */
	private function set_rt_cookie( $refresh_token ) {
		if ( ! $refresh_token ) {
			return false;
		}
		$enc      = $this->enc_refresh( $refresh_token );
		if ( $enc === '' ) {
			// If OpenSSL is not available, skip RT-cookie (we still support usermeta for logged-in users).
			return false;
		}

		$samesite = $this->cookie_samesite();
		$secure   = $this->cookie_secure( $samesite );
		$domain   = $this->cookie_domain();

		$opts = array(
			'expires'  => time() + self::RT_TTL,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => $samesite,
		);
		if ( $domain ) {
			$opts['domain'] = $domain;
		}

		return setcookie( self::RT_COOKIE, $enc, $opts );
	}

	/** @return string */
	private function get_rt_cookie() {
		if ( empty( $_COOKIE[ self::RT_COOKIE ] ) ) {
			return '';
		}
		$val = sanitize_text_field( $_COOKIE[ self::RT_COOKIE ] );
		return $this->dec_refresh( $val );
	}

	private function clear_rt_cookie() {
		$this->delete_cookie( self::RT_COOKIE );
	}

	// =============================================================================
	// Transient-based session store
	// =============================================================================

	/** @return void */
	private function store_for_sid( $sid, $data ) {
		set_transient( self::TRANSIENT_NS . $sid, $data, self::SID_TTL );
	}

	/** @return array */
	private function read_for_sid( $sid ) {
		return get_transient( self::TRANSIENT_NS . $sid ) ?: array();
	}

	/** @return void */
	private function delete_for_sid( $sid ) {
		delete_transient( self::TRANSIENT_NS . $sid );
	}

	/** @return array{0:string,1:string} */
	private function pkce_pair() {
		$verifier  = wp_generate_password( 64, false, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array( $verifier, $challenge );
	}

	// =============================================================================
	// Scopes (future-ready)
	// =============================================================================

	/**
	 * Build the scope list for authorization.
	 *
	 * @param string $context       Optional semantic context (e.g., 'core', 'save_playlist_private', 'save_playlist_public', 'save_playlist_with_image').
	 * @param array  $extra_scopes  Additional scopes to merge.
	 * @return string Space-separated scopes.
	 */
	private function build_scopes( $context = 'core', $extra_scopes = array() ) {
		$core = array(
			'streaming',
			'user-read-email',
			'user-read-private',
			'user-read-playback-state',
			'user-modify-playback-state',
		);

		$ctx_scopes = array();
		switch ( $context ) {
			case 'save_playlist_private':
				$ctx_scopes = array( 'playlist-modify-private' );
				break;
			case 'save_playlist_public':
				$ctx_scopes = array( 'playlist-modify-public' );
				break;
			case 'save_playlist_with_image':
				// Ask both modify and image upload for maximum compatibility.
				$ctx_scopes = array( 'playlist-modify-private', 'playlist-modify-public', 'ugc-image-upload' );
				break;
			case 'core':
			default:
				$ctx_scopes = array();
				break;
		}

		// Merge core + context + caller-provided extra.
		$scopes = array_values( array_unique( array_merge( $core, $ctx_scopes, array_map( 'strval', (array) $extra_scopes ) ) ) );

		/**
		 * Filter the final OAuth scopes before generating authorizeUrl.
		 *
		 * @param string[] $scopes  Array of scope strings.
		 * @param string   $context Context string passed to start().
		 */
		$scopes = apply_filters( 'bspfy_oauth_scopes', $scopes, $context );

		return implode( ' ', $scopes );
	}

	// =============================================================================
	// REST: /oauth/start
	// =============================================================================

	/**
	 * Begin the PKCE authorization flow.
	 *
	 * Body JSON (optional):
	 * - redirectBack  (string) Same-origin URL to return to in the opener (UI convenience).
	 * - context       (string)  Scope context. E.g. 'core', 'save_playlist_public', 'save_playlist_private', 'save_playlist_with_image'.
	 * - extra_scopes  (array)   Additional scopes to request.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function start( WP_REST_Request $req ) {
		$client_id = $this->client_id();
		if ( ! $client_id ) {
			return $this->rest_resp( array( 'error' => 'Missing client_id' ), 500 );
		}

		$sid = $this->get_sid();
		list( $verifier, $challenge ) = $this->pkce_pair();
		$state = wp_generate_uuid4();

		$store             = $this->read_for_sid( $sid );
		$store['pkce_verifier'] = $verifier;
		$store['state']         = $state;

		// Sanitize & constrain redirectBack (same-origin, reasonable length).
		$raw_redirect = $req->get_param( 'redirectBack' );
		$redirect     = esc_url_raw( $raw_redirect ?: home_url( '/' ) );
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$redir_host   = wp_parse_url( $redirect, PHP_URL_HOST );
		if ( $redir_host && ! hash_equals( (string) $site_host, (string) $redir_host ) ) {
			$redirect = home_url( '/' );
		}
		if ( strlen( $redirect ) > 1000 ) {
			$redirect = home_url( '/' );
		}
		$store['redirectBack'] = $redirect;

		// Scope handling (future-ready).
		$context      = (string) $req->get_param( 'context' ) ?: 'core';
		$extra_scopes = $req->get_param( 'extra_scopes' );
		if ( ! is_array( $extra_scopes ) ) {
			$extra_scopes = array();
		}
		$scope_string = $this->build_scopes( $context, $extra_scopes );

		$this->store_for_sid( $sid, $store );

		$params       = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $this->redirect_uri(),
			'scope'                 => $scope_string,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		$authorizeUrl = 'https://accounts.spotify.com/authorize?' . http_build_query( $params );

		return $this->rest_resp( array( 'authorizeUrl' => $authorizeUrl ), 200 );
	}

	// =============================================================================
	// REST: /oauth/callback
	// =============================================================================

	/**
	 * OAuth redirect handler (popup).
	 *
	 * @param WP_REST_Request $req
	 * @return void (prints HTML and exits)
	 */
	public function callback( WP_REST_Request $req ) {
		// During callback, prefer Lax to ensure cookie compatibility.
		add_filter(
			'bspfy_cookie_samesite',
			function ( $v ) {
				return 'Lax';
			},
			99
		);

		$sid   = $this->get_sid();
		$state = sanitize_text_field( $req->get_param( 'state' ) );
		$code  = sanitize_text_field( $req->get_param( 'code' ) );

		$store = $this->read_for_sid( $sid );
		if ( empty( $store['state'] ) || $store['state'] !== $state ) {
			$this->render_popup_result( false, 'State mismatch' );
		}

		// Safe redirectBack fallback.
		$raw_redirect = $req->get_param( 'redirectBack' );
		$redirectBack = esc_url_raw( $raw_redirect ?: ( $store['redirectBack'] ?? home_url( '/' ) ) );
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$redir_host   = wp_parse_url( $redirectBack, PHP_URL_HOST );
		if ( $redir_host && ! hash_equals( (string) $site_host, (string) $redir_host ) ) {
			$redirectBack = home_url( '/' );
		}

		// PKCE token exchange.
		$resp = wp_remote_post(
			'https://accounts.spotify.com/api/token',
			array(
				'body' => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $this->redirect_uri(),
					'client_id'     => $this->client_id(),
					'code_verifier' => $store['pkce_verifier'],
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			$this->render_popup_result( false, 'Token request failed' );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['access_token'] ) ) {
			$this->render_popup_result( false, 'No access token' );
		}

		$tokens = array(
			'access_token'  => $body['access_token'],
			'expires_in'    => time() + intval( $body['expires_in'] ?? 3600 ) - 30,
			'refresh_token' => $body['refresh_token'] ?? '',
			'token_type'    => $body['token_type'] ?? 'Bearer',
		);

		// HttpOnly refresh cookie (encrypted if OpenSSL is available).
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$this->set_rt_cookie( $tokens['refresh_token'] );
		}

		if ( is_user_logged_in() ) {
			$uid = get_current_user_id();

			if ( ! empty( $tokens['refresh_token'] ) ) {
				update_user_meta( $uid, 'bspfy_refresh_token', $tokens['refresh_token'] );
			}

			update_user_meta(
				$uid,
				'bspfy_access_cache',
				array(
					'access_token' => $tokens['access_token'],
					'expires_in'   => $tokens['expires_in'],
					'token_type'   => $tokens['token_type'],
				)
			);

			// Clear PKCE artifacts once the user is fully authenticated.
			$this->delete_for_sid( $sid );
		} else {
			// Guest: keep access in transient; do not store refresh_token there.
			$clean = $this->read_for_sid( $sid );
			unset( $clean['pkce_verifier'], $clean['state'], $clean['redirectBack'] );
			$clean['tokens'] = array(
				'access_token' => $tokens['access_token'],
				'expires_in'   => $tokens['expires_in'],
				'token_type'   => $tokens['token_type'],
			);
			$this->store_for_sid( $sid, $clean );
		}

		$this->render_popup_result( true, 'OK', $redirectBack );
	}

	// =============================================================================
	// REST: /oauth/token (and /oauth/refresh)
	// =============================================================================

	/**
	 * Return a valid access_token if available (may refresh).
	 *
	 * Query parameter:
	 * - dbg=1  Include debug fields (source, expiry, etc.)
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function token( WP_REST_Request $req ) {
		$sid       = $this->get_sid();
		$dbg       = (int) $req->get_param( 'dbg' ) === 1;
		$has_rt_ck = ! empty( $_COOKIE[ self::RT_COOKIE ] );
		$now       = time();

		// 1) Logged-in user => usermeta is the source of truth.
		if ( is_user_logged_in() ) {
			$uid         = get_current_user_id();
			$cache       = get_user_meta( $uid, 'bspfy_access_cache', true );
			$user_has_rt = (bool) get_user_meta( $uid, 'bspfy_refresh_token', true );
			if ( ! is_array( $cache ) ) {
				$cache = array();
			}

			$exp_ts = isset( $cache['expires_in'] ) ? (int) $cache['expires_in'] : 0;
			$exp_ok = $exp_ts > $now;

			// 1a) Cached access token.
			if ( ! empty( $cache['access_token'] ) && $exp_ok ) {
				$out = array(
					'authenticated' => true,
					'access_token'  => $cache['access_token'],
				);
				if ( $dbg ) {
					$out['source']             = 'user_access_cache';
					$out['user_id']            = $uid;
					$out['sid']                = $sid;
					$out['rt_user']            = $user_has_rt;
					$out['rt_cookie']          = $has_rt_ck;
					$out['cache_expires_at']   = $exp_ts;
					$out['cache_seconds_left'] = max( 0, $exp_ts - $now );
					$out['wp_is_user']         = true;
					$out['wp_user_id']         = $uid;
				}
				return $this->rest_resp( $out, 200 );
			}

			// 1b) Refresh (single-flight).
			$new = $this->with_refresh_lock(
				"uid_$uid",
				function () use ( $uid ) {
					$refresh = get_user_meta( $uid, 'bspfy_refresh_token', true );
					if ( ! $refresh ) {
						$refresh = $this->get_rt_cookie();
					}
					if ( ! $refresh ) {
						return array();
					}

					$n = $this->refresh_with( $refresh );
					if ( empty( $n['access_token'] ) ) {
						return array();
					}

					update_user_meta( $uid, 'bspfy_access_cache', $n );
					$store_rt = ! empty( $n['refresh_token'] ) ? $n['refresh_token'] : $refresh;
					update_user_meta( $uid, 'bspfy_refresh_token', $store_rt );
					$this->set_rt_cookie( $store_rt );

					return $n;
				}
			);

			if ( ! empty( $new['access_token'] ) ) {
				$out = array(
					'authenticated' => true,
					'access_token'  => $new['access_token'],
				);
				if ( $dbg ) {
					$exp_ts_new              = isset( $new['expires_in'] ) ? (int) $new['expires_in'] : 0;
					$out['source']           = 'user_refresh';
					$out['user_id']          = $uid;
					$out['sid']              = $sid;
					$out['rt_user']          = (bool) get_user_meta( $uid, 'bspfy_refresh_token', true );
					$out['rt_cookie']        = ! empty( $_COOKIE[ self::RT_COOKIE ] );
					$out['cache_expires_at'] = $exp_ts_new;
					$out['cache_seconds_left'] = max( 0, $exp_ts_new - $now );
					$out['wp_is_user']       = true;
					$out['wp_user_id']       = $uid;
				}
				return $this->rest_resp( $out, 200 );
			}

			$out = array( 'authenticated' => false );
			if ( $dbg ) {
				$out['source']     = 'user_none';
				$out['user_id']    = $uid;
				$out['sid']        = $sid;
				$out['rt_user']    = $user_has_rt;
				$out['rt_cookie']  = $has_rt_ck;
				$out['wp_is_user'] = true;
				$out['wp_user_id'] = $uid;
			}
			return $this->rest_resp( $out, 401 );
		}

		// 2) Guest session => RT-cookie (encrypted) is source of truth; transient is access cache.
		$store = $this->read_for_sid( $sid );
		$tok   = $store['tokens'] ?? array();

		// 2a) Cached access token.
		if ( ! empty( $tok['access_token'] ) && $now < (int) ( $tok['expires_in'] ?? 0 ) ) {
			$out = array(
				'authenticated' => true,
				'access_token'  => $tok['access_token'],
			);
			if ( $dbg ) {
				$out['source']             = 'guest_cache';
				$out['sid']                = $sid;
				$out['rt_cookie']          = $has_rt_ck;
				$out['cache_expires_at']   = (int) $tok['expires_in'];
				$out['cache_seconds_left'] = max( 0, (int) $tok['expires_in'] - $now );
				$out['wp_is_user']         = false;
				$out['wp_user_id']         = 0;
			}
			return $this->rest_resp( $out, 200 );
		}

		// 2b) Attempt refresh for guest.
		$new = $this->with_refresh_lock(
			"sid_$sid",
			function () use ( $sid, $tok, $store ) {
				$refresh = ! empty( $tok['refresh_token'] ) ? $tok['refresh_token'] : $this->get_rt_cookie();
				if ( ! $refresh ) {
					return array();
				}

				$n = $this->refresh_with( $refresh );
				if ( empty( $n['access_token'] ) ) {
					return array();
				}

				$s           = $store;
				$s['tokens'] = $n;
				$this->store_for_sid( $sid, $s );
				$this->set_rt_cookie( $n['refresh_token'] ?? $refresh );

				return $n;
			}
		);

		if ( ! empty( $new['access_token'] ) ) {
			$out = array(
				'authenticated' => true,
				'access_token'  => $new['access_token'],
			);
			if ( $dbg ) {
				$exp_ts_new                = isset( $new['expires_in'] ) ? (int) $new['expires_in'] : 0;
				$out['source']             = 'guest_refresh';
				$out['sid']                = $sid;
				$out['rt_cookie']          = ! empty( $_COOKIE[ self::RT_COOKIE ] );
				$out['cache_expires_at']   = $exp_ts_new;
				$out['cache_seconds_left'] = max( 0, $exp_ts_new - $now );
				$out['wp_is_user']         = false;
				$out['wp_user_id']         = 0;
			}
			return $this->rest_resp( $out, 200 );
		}

		$out = array( 'authenticated' => false );
		if ( $dbg ) {
			$out['source']     = 'guest_none';
			$out['sid']        = $sid;
			$out['rt_cookie']  = $has_rt_ck;
			$out['wp_is_user'] = false;
			$out['wp_user_id'] = 0;
		}
		return $this->rest_resp( $out, 401 );
	}

	/**
	 * Explicit refresh endpoint; delegates to token().
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function force_refresh( WP_REST_Request $req ) {
		return $this->token( $req );
	}

	// =============================================================================
	// REST: /oauth/logout
	// =============================================================================

	/**
	 * Clear tokens/cookies for current session (and usermeta for logged-in users).
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $req ) {
		// 1) Read SID directly (do not prolong).
		$sid = ! empty( $_COOKIE[ self::SID_COOKIE ] ) ? sanitize_text_field( $_COOKIE[ self::SID_COOKIE ] ) : '';

		// 2) Clear guest session cache.
		if ( $sid ) {
			$this->delete_for_sid( $sid );
		}

		// 3) Clear usermeta (server truth).
		if ( is_user_logged_in() ) {
			$uid = get_current_user_id();
			delete_user_meta( $uid, 'bspfy_access_cache' );
			delete_user_meta( $uid, 'bspfy_refresh_token' );
		}

		// 4) Drop cookies with correct flags/domains.
		$this->delete_cookie( self::SID_COOKIE );
		$this->delete_cookie( self::RT_COOKIE );

		return $this->rest_resp( array( 'ok' => true ), 200 );
	}

	// =============================================================================
	// REST: /oauth/health
	// =============================================================================

	/**
	 * Lightweight diagnostics endpoint.
	 *
	 * Returns non-sensitive status useful for debugging production issues (CORS, SameSite, etc.).
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public function health( WP_REST_Request $req ) {
		$sid_cookie = ! empty( $_COOKIE[ self::SID_COOKIE ] );
		$rt_cookie  = ! empty( $_COOKIE[ self::RT_COOKIE ] );
		$samesite   = $this->cookie_samesite();
		$secure     = $this->cookie_secure( $samesite );
		$domain     = $this->cookie_domain();

		$out = array(
			'version'          => defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : 'unknown',
			'wp_is_user'       => is_user_logged_in(),
			'sid_cookie'       => $sid_cookie,
			'rt_cookie'        => $rt_cookie,
			'samesite'         => $samesite,
			'secure'           => (bool) $secure,
			'domain'           => (string) $domain,
			'origin'           => site_url(),
			'time'             => time(),
		);

		return $this->rest_resp( $out, 200 );
	}

	// =============================================================================
	// Refresh utilities
	// =============================================================================

	/**
	 * Exchange a refresh_token for a new access_token (and possibly a new refresh_token).
	 *
	 * @param string $refresh_token
	 * @return array<string,mixed> token payload or empty array on failure
	 */
	private function refresh_with( $refresh_token ) {
		$resp = wp_remote_post(
			'https://accounts.spotify.com/api/token',
			array(
				'body' => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => $this->client_id(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array();
		}

		$b = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $b['access_token'] ) ) {
			return array();
		}

		return array(
			'access_token'  => $b['access_token'],
			'expires_in'    => time() + intval( $b['expires_in'] ?? 3600 ) - 30,
			'refresh_token' => $b['refresh_token'] ?? $refresh_token,
			'token_type'    => $b['token_type'] ?? 'Bearer',
		);
	}

	/**
	 * Tiny single-flight lock using a transient key.
	 *
	 * @param string   $key
	 * @param callable $fn
	 * @return mixed
	 */
	private function with_refresh_lock( $key, $fn ) {
		$lock  = self::TRANSIENT_NS . 'lock_' . $key;
		$tries = 0;

		while ( get_transient( $lock ) && $tries < 40 ) { // ~4s max
			usleep( 100000 );
			$tries++;
		}

		set_transient( $lock, 1, 10 );
		try {
			return $fn();
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Render a minimal popup result page and exit.
	 *
	 * @param bool   $success
	 * @param string $msg
	 * @param string $redirectBack
	 * @return void
	 */
	private function render_popup_result( $success, $msg, $redirectBack = '' ) {
		$origin   = esc_js( site_url() );
		$payload  = wp_json_encode( array( 'type' => 'bspfy-auth', 'success' => (bool) $success, 'message' => (string) $msg ) );
		$redirect = esc_js( $redirectBack ?: site_url( '/' ) );

		$html = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Spotify Auth</title></head>
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
</body>
</html>
HTML;

		if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
			@ob_end_clean();
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
