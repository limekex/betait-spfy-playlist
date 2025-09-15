<?php
/**
 * AJAX handlers for BeTA iT – Spotify Playlist (search, token save, profile updates).
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authenticated AJAX operations used by the plugin.
 *
 * Actions (admin-ajax.php):
 * - wp_ajax_search_spotify_tracks          : Search Spotify (requires Bearer in Authorization header)
 * - wp_ajax_save_spotify_access_token      : Store access token on user and backfill display_name
 * - wp_ajax_save_spotify_user_name         : Update cached Spotify display name on user
 *
 * Security:
 * - Read/search uses current login session + Authorization header from client.
 * - Write actions require a nonce: check with check_ajax_referer( 'bspfy_ajax', 'nonce' ).
 */
class Betait_Spfy_Playlist_Ajax {

	/**
	 * Constructor: register AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_search_spotify_tracks', array( $this, 'search_spotify_tracks' ) );
		add_action( 'wp_ajax_save_spotify_access_token', array( $this, 'save_spotify_access_token' ) );
		add_action( 'wp_ajax_save_spotify_user_name', array( $this, 'save_spotify_user_name' ) );
	}

	/**
	 * Conditional debug logger (tokens masked).
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log_debug( $message ) {
		if ( (int) get_option( 'bspfy_debug', 0 ) !== 1 ) {
			return;
		}
		// Ensure we don't accidentally log Bearer or refresh tokens.
		$redacted = preg_replace( '/Bearer\s+[A-Za-z0-9\.\-\_\~\+\/]+=*/', 'Bearer [REDACTED]', (string) $message );
		error_log( '[BSPFY AJAX] ' . $redacted ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Extract Bearer token from request headers (server-agnostic).
	 *
	 * @return string Empty string if not found.
	 */
	private function get_bearer_from_headers() {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( isset( $all['Authorization'] ) ) {
				$header = (string) $all['Authorization'];
			}
		}

		if ( $header && preg_match( '/Bearer\s([^\s]+)/i', $header, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		return '';
	}

	/**
	 * AJAX: Perform a Spotify search.
	 *
	 * Requires:
	 * - Logged-in user (wp_ajax_…)
	 * - Authorization: Bearer <token> (from client)
	 *
	 * POST:
	 * - query : string (required)
	 * - type  : string (optional; default 'track') – accepted: track, album, artist
	 * - limit : int    (optional; default 20; 1–50)
	 *
	 * @return void (JSON)
	 */
	public function search_spotify_tracks() {
		$this->log_debug( 'search_spotify_tracks: IN ' . wp_json_encode( $_POST ) );

		$access_token = $this->get_bearer_from_headers();
		if ( '' === $access_token ) {
			$this->log_debug( 'search_spotify_tracks: no Authorization Bearer header' );
			wp_send_json_error(
				array( 'message' => __( 'Access token is required for this request.', 'betait-spfy-playlist' ) ),
				401
			);
		}

		$query_raw = isset( $_POST['query'] ) ? wp_unslash( $_POST['query'] ) : '';
		$query     = sanitize_text_field( $query_raw );
		if ( '' === $query ) {
			$this->log_debug( 'search_spotify_tracks: missing query' );
			wp_send_json_error(
				array( 'message' => __( 'No search query provided.', 'betait-spfy-playlist' ) ),
				400
			);
		}

		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'track';
		$limit = isset( $_POST['limit'] ) ? (int) wp_unslash( $_POST['limit'] ) : 20;
		$limit = max( 1, min( 50, $limit ) );

		// Ensure API handler exists.
		if ( ! class_exists( 'Betait_Spfy_Playlist_API_Handler' ) ) {
			$this->log_debug( 'search_spotify_tracks: API handler class missing' );
			wp_send_json_error(
				array( 'message' => __( 'Internal error: API handler missing.', 'betait-spfy-playlist' ) ),
				500
			);
		}

		$this->log_debug( sprintf( 'search_spotify_tracks: q="%s" type=%s limit=%d', $query, $type, $limit ) );

		try {
			$api       = new Betait_Spfy_Playlist_API_Handler();
			$response  = $api->search_tracks( $query, $access_token, $type, $limit ); // Expected to return structured array.

			if ( is_array( $response ) && ! empty( $response['success'] ) && isset( $response['data']['tracks'] ) ) {
				$this->log_debug( 'search_spotify_tracks: OK' );
				wp_send_json_success(
					array(
						'message' => __( 'Tracks fetched successfully.', 'betait-spfy-playlist' ),
						'tracks'  => $response['data']['tracks'],
					),
					200
				);
			}

			$this->log_debug( 'search_spotify_tracks: empty/no tracks ' . wp_json_encode( $response ) );
			wp_send_json_error(
				array(
					'message'  => __( 'No tracks found in the Spotify response.', 'betait-spfy-playlist' ),
					'response' => $response,
				),
				404
			);
		} catch ( Exception $e ) {
			$this->log_debug( 'search_spotify_tracks: exception ' . $e->getMessage() );
			wp_send_json_error(
				array( 'message' => __( 'Unexpected error while searching.', 'betait-spfy-playlist' ) ),
				500
			);
		}
	}

	/**
	 * AJAX: Save Spotify access token to current user and backfill display name.
	 *
	 * Security: requires logged-in user + nonce.
	 * Expects POST:
	 * - access_token : string (required)
	 * - nonce        : string (required; wp_create_nonce('bspfy_ajax'))
	 *
	 * @return void (JSON)
	 */
	public function save_spotify_access_token() {
		check_ajax_referer( 'bspfy_ajax', 'nonce' );

		$uid = get_current_user_id();
		if ( ! $uid ) {
			$this->log_debug( 'save_spotify_access_token: not logged in' );
			wp_send_json_error( array( 'message' => __( 'User not logged in.', 'betait-spfy-playlist' ) ), 401 );
		}

		$token_raw = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : '';
		$token     = sanitize_text_field( $token_raw );
		if ( '' === $token ) {
			$this->log_debug( 'save_spotify_access_token: missing token' );
			wp_send_json_error( array( 'message' => __( 'No access token provided.', 'betait-spfy-playlist' ) ), 400 );
		}

		// Save token to user meta (note: consider short-lived; httpOnly cookie flow is preferred for refresh).
		update_user_meta( $uid, 'spotify_access_token', $token );
		$this->log_debug( 'save_spotify_access_token: token saved for user ' . $uid );

		// Fetch Spotify profile to backfill display_name.
		$response = wp_remote_get(
			'https://api.spotify.com/v1/me',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 12,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'save_spotify_access_token: profile error ' . $response->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Error fetching Spotify user profile.', 'betait-spfy-playlist' ) ), 502 );
		}

		$body      = wp_remote_retrieve_body( $response );
		$user_data = json_decode( $body, true );

		if ( isset( $user_data['error'] ) ) {
			$msg = isset( $user_data['error']['message'] ) ? (string) $user_data['error']['message'] : 'API error';
			$this->log_debug( 'save_spotify_access_token: Spotify API error ' . $msg );
			wp_send_json_error( array( 'message' => sprintf( '%s %s', __( 'Spotify API error:', 'betait-spfy-playlist' ), $msg ) ), 400 );
		}

		$spotify_user_name = '';
		if ( isset( $user_data['display_name'] ) && '' !== $user_data['display_name'] ) {
			$spotify_user_name = sanitize_text_field( $user_data['display_name'] );
			update_user_meta( $uid, 'spotify_user_name', $spotify_user_name );
			$this->log_debug( 'save_spotify_access_token: username saved for user ' . $uid );
		} else {
			$this->log_debug( 'save_spotify_access_token: no display_name in profile' );
		}

		wp_send_json_success(
			array(
				'message'           => __( 'Access token and Spotify username saved successfully.', 'betait-spfy-playlist' ),
				'spotify_user_name' => $spotify_user_name ?: __( 'Unknown User', 'betait-spfy-playlist' ),
			),
			200
		);
	}

	/**
	 * AJAX: Save (or update) cached Spotify display name for current user.
	 *
	 * Security: requires logged-in user + nonce.
	 * Expects POST:
	 * - spotify_user_name : string (required)
	 * - nonce             : string (required; wp_create_nonce('bspfy_ajax'))
	 *
	 * @return void (JSON)
	 */
	public function save_spotify_user_name() {
		check_ajax_referer( 'bspfy_ajax', 'nonce' );

		$uid = get_current_user_id();
		if ( ! $uid ) {
			$this->log_debug( 'save_spotify_user_name: not logged in' );
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'betait-spfy-playlist' ) ), 401 );
		}

		$name_raw = isset( $_POST['spotify_user_name'] ) ? wp_unslash( $_POST['spotify_user_name'] ) : '';
		$name     = sanitize_text_field( $name_raw );
		if ( '' === $name ) {
			$this->log_debug( 'save_spotify_user_name: missing spotify_user_name' );
			wp_send_json_error( array( 'message' => __( 'Missing spotify_user_name.', 'betait-spfy-playlist' ) ), 400 );
		}

		update_user_meta( $uid, 'spotify_user_name', $name );
		$this->log_debug( 'save_spotify_user_name: saved for user ' . $uid );

		wp_send_json_success(
			array(
				'ok'                => true,
				'spotify_user_name' => $name,
			),
			200
		);
	}
}
