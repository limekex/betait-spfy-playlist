<?php
/**
 * Spotify Web API helper for BeTA iT – Spotify Playlist.
 *
 * Provides thin wrappers around common calls and input handling:
 * - Extract Bearer tokens from headers (user tokens via PKCE)
 * - Fallback to Client Credentials when no user token is present
 * - Search tracks/artists/albums with optional market handling
 * - Defensive logging without leaking tokens
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_API_Handler
 *
 * Notes:
 * - Uses user Bearer when available (best results, market=from_token).
 * - Falls back to Client Credentials (no user context), with market fallback (default "NO").
 * - Returns arrays shaped like: ['success' => bool, 'data' => mixed ].
 */
class Betait_Spfy_Playlist_API_Handler {

	/**
	 * Spotify Web API base URL.
	 *
	 * @var string
	 */
	private $api_base = 'https://api.spotify.com/v1/';

	/**
	 * Spotify Client ID (for client_credentials fallback).
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Spotify Client Secret (for client_credentials fallback).
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * Constructor: load saved credentials (if any).
	 */
	public function __construct() {
		$this->client_id     = (string) get_option( 'bspfy_client_id', '' );
		$this->client_secret = (string) get_option( 'bspfy_client_secret', '' );
	}

	/**
	 * Conditional debug logger (redacts Bearer tokens).
	 *
	 * @param string $message Log message.
	 * @param mixed  $context Optional extra data (will be json_encoded).
	 * @return void
	 */
	private function log_debug( $message, $context = null ) {
		if ( (int) get_option( 'bspfy_debug', 0 ) !== 1 ) {
			return;
		}

		$redact = static function( $str ) {
			$str = (string) $str;
			return preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $str );
		};

		$prefix = '[BSPFY API] ';
		if ( null === $context ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $prefix . $redact( $message ) );
			return;
		}

		$ctx = wp_json_encode( $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $prefix . $redact( $message ) . ' | ' . $redact( (string) $ctx ) );
	}

	/**
	 * True if both Client ID and Secret are present.
	 *
	 * @return bool
	 */
	private function credentials_are_set() {
		return $this->client_id !== '' && $this->client_secret !== '';
	}

	/**
	 * Extract Authorization: Bearer <token> from the current request.
	 *
	 * @return string Empty string when not present.
	 */
	private function get_bearer_from_request() {
		$hdr = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$hdr = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$hdr = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( isset( $all['Authorization'] ) ) {
				$hdr = (string) $all['Authorization'];
			}
		}

		if ( $hdr && preg_match( '/Bearer\s([^\s]+)/i', $hdr, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		return '';
	}

	/**
	 * Obtain an app-only access token using Client Credentials flow.
	 *
	 * @return string|array Access token on success, or ['error' => '...'].
	 */
	private function get_access_token_client_credentials() {
		if ( ! $this->credentials_are_set() ) {
			return array( 'error' => __( 'Spotify API credentials are not set.', 'betait-spfy-playlist' ) );
		}

		$args = array(
			'body'    => array( 'grant_type' => 'client_credentials' ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
			'timeout' => 12,
		);

		// Include a helpful UA if constants exist.
		if ( defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ) {
			$args['user-agent'] = 'BSPFY/' . BETAIT_SPFY_PLAYLIST_VERSION . ( defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ? ' (' . plugin_basename( BETAIT_SPFY_PLAYLIST_FILE ) . ')' : '' );
		}

		$resp = wp_remote_post( 'https://accounts.spotify.com/api/token', $args );

		if ( is_wp_error( $resp ) ) {
			$this->log_debug( 'client_credentials error', $resp->get_error_message() );
			return array( 'error' => __( 'Error connecting to Spotify Accounts service.', 'betait-spfy-playlist' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$this->log_debug( 'client_credentials unexpected response', compact( 'code', 'body' ) );
			return array( 'error' => __( 'Unable to fetch access token (client credentials).', 'betait-spfy-playlist' ) );
		}

		return (string) $body['access_token'];
	}

	/**
	 * Perform a GET request to the Spotify Web API with Bearer auth.
	 *
	 * Handles basic 429 backoff if Retry-After is present.
	 *
	 * @param string $url          Full API URL.
	 * @param string $access_token Bearer token.
	 * @return array [ array|null $json, int $status_code ]
	 */
	private function http_get( $url, $access_token ) {
		$args = array(
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			'timeout' => 12,
		);

		if ( defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ) {
			$args['user-agent'] = 'BSPFY/' . BETAIT_SPFY_PLAYLIST_VERSION . ( defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ? ' (' . plugin_basename( BETAIT_SPFY_PLAYLIST_FILE ) . ')' : '' );
		}

		$resp = wp_remote_get( $url, $args );

		if ( is_wp_error( $resp ) ) {
			$this->log_debug( 'http_get WP_Error', $resp->get_error_message() );
			return array( null, 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );

		// Handle simple 429 backoff (single retry).
		if ( 429 === $code ) {
			$headers     = wp_remote_retrieve_headers( $resp );
			$retry_after = 1;
			if ( isset( $headers['retry-after'] ) ) {
				$retry_after = max( 1, (int) $headers['retry-after'] );
			}
			sleep( $retry_after );
			$resp = wp_remote_get( $url, $args );
			if ( is_wp_error( $resp ) ) {
				return array( null, 0 );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		return array( ( 200 === $code ? $body : null ), $code );
	}

	/**
	 * Search Spotify for tracks (and optionally via artist/album paths).
	 *
	 * Strategy:
	 * - If a user Bearer is available (header or param), use that and set market=from_token.
	 * - If not, fall back to client credentials and use a default market (filterable, default "NO").
	 * - Supports comma-separated $type_str of: track,artist,album.
	 *
	 * @param string      $query        The query string.
	 * @param string|null $access_token Optional explicit bearer token (otherwise inferred from request).
	 * @param string      $type_str     Comma-separated types (track,artist,album). Default 'track'.
	 * @param int         $limit        Max results per API call (1–50). Default 20.
	 * @return array { success: bool, data: array{ tracks: array }|array{ message: string } }
	 */
	public function search_tracks( $query, $access_token = null, $type_str = 'track', $limit = 20 ) {
		$query = trim( (string) $query );
		$limit = max( 1, min( 50, (int) $limit ) );

		// Determine whether this call came with a user token.
		$header_bearer      = $this->get_bearer_from_request();
		$have_user_bearer   = $header_bearer !== '';
		$effective_bearer   = $access_token ?: $header_bearer;

		// Fallback to client credentials if no bearer present.
		if ( '' === (string) $effective_bearer ) {
			$have_user_bearer = false;
			$this->log_debug( 'No Bearer in request; falling back to client_credentials for search.' );

			$tok = $this->get_access_token_client_credentials();
			if ( is_array( $tok ) && isset( $tok['error'] ) ) {
				return array(
					'success' => false,
					'data'    => array( 'message' => (string) $tok['error'] ),
				);
			}
			$effective_bearer = (string) $tok;
		}

		// Market handling: use from_token when user bearer is available.
		$default_market = 'NO';
		/**
		 * Filter the default Spotify market used when no user token is present.
		 *
		 * @param string $market Default market (2-letter ISO 3166-1 alpha-2). Default 'NO'.
		 */
		$default_market = apply_filters( 'bspfy_default_market', $default_market );

		$market = $have_user_bearer ? 'from_token' : $default_market;

		// Types: sanitize and clamp to allowed set.
		$types = array_filter(
			array_map(
				'trim',
				explode( ',', strtolower( (string) $type_str ) )
			)
		);
		$types = array_values( array_intersect( $types, array( 'track', 'artist', 'album' ) ) );
		if ( empty( $types ) ) {
			$types = array( 'track' );
		}

		$tracks_out = array();
		$seen       = array();

		$add_track = static function ( $t ) use ( &$tracks_out, &$seen ) {
			if ( empty( $t['id'] ) || isset( $seen[ $t['id'] ] ) ) {
				return;
			}
			$seen[ $t['id'] ] = true;
			$tracks_out[]     = $t;
		};

		// 1) Direct track search.
		if ( in_array( 'track', $types, true ) ) {
			$q   = array(
				'q'      => $query,
				'type'   => 'track',
				'limit'  => $limit,
				'market' => $market,
			);
			$url = $this->api_base . 'search?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
			list( $data, $code ) = $this->http_get( $url, $effective_bearer );
			if ( 200 === $code && ! empty( $data['tracks']['items'] ) ) {
				foreach ( $data['tracks']['items'] as $t ) {
					$add_track( $t );
				}
			}
		}

		// 2) Artist → top-tracks for a few best matches.
		if ( in_array( 'artist', $types, true ) ) {
			$q   = array(
				'q'    => $query,
				'type' => 'artist',
				'limit'=> 3,
			);
			$url = $this->api_base . 'search?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
			list( $data, $code ) = $this->http_get( $url, $effective_bearer );

			if ( 200 === $code && ! empty( $data['artists']['items'] ) ) {
				foreach ( $data['artists']['items'] as $a ) {
					if ( empty( $a['id'] ) ) {
						continue;
					}
					$url_top = $this->api_base . 'artists/' . rawurlencode( (string) $a['id'] ) . '/top-tracks?' . http_build_query(
						array( 'market' => $market ),
						'',
						'&',
						PHP_QUERY_RFC3986
					);
					list( $tops, $code_t ) = $this->http_get( $url_top, $effective_bearer );
					if ( 200 === $code_t && ! empty( $tops['tracks'] ) ) {
						foreach ( $tops['tracks'] as $t ) {
							$add_track( $t );
						}
					}
				}
			}
		}

		// 3) Album → expand a few albums into their tracks (enrich with album cover where missing).
		if ( in_array( 'album', $types, true ) ) {
			$q   = array(
				'q'      => $query,
				'type'   => 'album',
				'limit'  => 3,
				'market' => $market,
			);
			$url = $this->api_base . 'search?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
			list( $data, $code ) = $this->http_get( $url, $effective_bearer );

			if ( 200 === $code && ! empty( $data['albums']['items'] ) ) {
				foreach ( $data['albums']['items'] as $alb ) {
					if ( empty( $alb['id'] ) ) {
						continue;
					}
					$cover   = isset( $alb['images'] ) ? $alb['images'] : array();
					$url_alb = $this->api_base . 'albums/' . rawurlencode( (string) $alb['id'] ) . '/tracks?' . http_build_query(
						array(
							'limit'  => min( 10, $limit ),
							'market' => $market,
						),
						'',
						'&',
						PHP_QUERY_RFC3986
					);
					list( $albtracks, $code_a ) = $this->http_get( $url_alb, $effective_bearer );

					if ( 200 === $code_a && ! empty( $albtracks['items'] ) ) {
						foreach ( $albtracks['items'] as $t ) {
							// Enrich minimal track payloads with album name/cover to help UI display.
							if ( empty( $t['album'] ) ) {
								$t['album'] = array(
									'name'   => isset( $alb['name'] ) ? $alb['name'] : '',
									'images' => $cover,
								);
							} elseif ( empty( $t['album']['images'] ) ) {
								$t['album']['images'] = $cover;
							}
							$add_track( $t );
						}
					}
				}
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'tracks' => array_values( $tracks_out ),
			),
		);
	}
}
