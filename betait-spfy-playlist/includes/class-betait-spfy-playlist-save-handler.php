<?php
/**
 * Handles saving playlists to user's Spotify account.
 *
 * Responsibilities:
 * - REST endpoint for saving playlists to Spotify
 * - REST endpoint for previewing playlist data
 * - Batch add tracks (100 per request)
 * - Handle rate limiting and retries
 * - Store playlist mappings in user meta
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Betait_Spfy_Playlist_Save_Handler {

	/**
	 * Spotify Web API base URL.
	 *
	 * @var string
	 */
	private $api_base = 'https://api.spotify.com/v1';

	/**
	 * Constructor: register REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'bspfy/v1',
			'/playlist/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_playlist' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'bspfy/v1',
			'/playlist/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preview_playlist' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'bspfy/v1',
			'/playlist/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'playlist_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Check if user has access to save playlists.
	 * We allow both logged-in users and guests (with Spotify auth).
	 *
	 * @return bool
	 */
	public function check_permission() {
		// Allow all requests - we'll check for Spotify authentication in save_playlist().
		// This allows both:
		// - Logged-in WP users (tokens in user meta, mappings in user meta)
		// - Guest users (tokens in session cookies, no persistent mappings)
		return true;
	}

	/**
	 * Conditional debug logger.
	 *
	 * @param string $message Log message.
	 * @param mixed  $context Optional extra data.
	 * @return void
	 */
	private function log_debug( $message, $context = null ) {
		if ( (int) get_option( 'bspfy_debug', 0 ) !== 1 ) {
			return;
		}

		$prefix = '[BSPFY Save] ';
		if ( null === $context ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $prefix . (string) $message );
			return;
		}

		$ctx = wp_json_encode( $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $prefix . (string) $message . ' | ' . (string) $ctx );
	}

	/**
	 * Get access token from user meta or session.
	 * This method mirrors the logic in Betait_Spfy_Playlist_OAuth::token()
	 *
	 * Checks both user meta (for logged-in users) AND session cookies
	 * (in case user authenticated as guest then logged in).
	 *
	 * @return string|null Access token or null if not found.
	 */
	private function get_access_token() {
		$now = time();

		// 1) Logged-in user => check usermeta cache first.
		if ( is_user_logged_in() ) {
			$uid   = get_current_user_id();
			$cache = get_user_meta( $uid, 'bspfy_access_cache', true );

			$this->log_debug( 'Checking access token for logged-in user', array(
				'user_id'     => $uid,
				'cache_found' => ! empty( $cache ),
				'cache_type'  => gettype( $cache ),
				'has_token'   => ! empty( $cache['access_token'] ),
			) );

			if ( is_array( $cache ) && ! empty( $cache['access_token'] ) ) {
				$exp_ts = isset( $cache['expires_in'] ) ? (int) $cache['expires_in'] : 0;
				$this->log_debug( 'Token expiry check (user meta)', array(
					'expires_at'    => $exp_ts,
					'current_time'  => $now,
					'seconds_left'  => $exp_ts - $now,
					'is_valid'      => $exp_ts > $now,
				) );

				if ( $exp_ts > $now ) {
					$this->log_debug( 'Using cached access token from user meta' );
					return $cache['access_token'];
				} else {
					$this->log_debug( 'Access token expired in user meta' );
				}
			} else {
				$this->log_debug( 'No valid token cache found in user meta' );
			}

			// Fall through to check session cookie (user might have authenticated as guest).
		}

		// 2) Check session cookie (works for both guest and logged-in users).
		$sid_cookie = 'bspfy_sid';
		if ( ! empty( $_COOKIE[ $sid_cookie ] ) ) {
			$sid   = sanitize_text_field( $_COOKIE[ $sid_cookie ] );
			$store = get_transient( 'bspfy_oauth_' . $sid );

			$this->log_debug( 'Checking session cookie', array(
				'sid'         => $sid,
				'store_found' => ! empty( $store ),
				'is_logged_in' => is_user_logged_in(),
			) );

			if ( is_array( $store ) ) {
				$tok = $store['tokens'] ?? array();

				if ( ! empty( $tok['access_token'] ) ) {
					$exp_ts = (int) ( $tok['expires_in'] ?? 0 );
					$this->log_debug( 'Token expiry check (session)', array(
						'expires_at'    => $exp_ts,
						'current_time'  => $now,
						'seconds_left'  => $exp_ts - $now,
						'is_valid'      => $exp_ts > $now,
					) );

					if ( $exp_ts > $now ) {
						$this->log_debug( 'Using cached access token from session' );
						return $tok['access_token'];
					} else {
						$this->log_debug( 'Access token expired in session' );
					}
				} else {
					$this->log_debug( 'No access token in session data' );
				}
			} else {
				$this->log_debug( 'No session data found' );
			}
		} else {
			$this->log_debug( 'No session cookie found' );
		}

		// No valid token found anywhere.
		$this->log_debug( 'No valid access token found' );
		return null;
	}

	/**
	 * Make a request to Spotify API.
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint (relative to base).
	 * @param array  $body Request body.
	 * @param string $access_token Access token.
	 * @param int    $retry_count Current retry count.
	 * @return array Response with 'success', 'data', 'status', 'error'.
	 */
	private function spotify_request( $method, $endpoint, $body = null, $access_token = '', $retry_count = 0 ) {
		$url = $this->api_base . $endpoint;

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'status'  => 0,
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		// Handle rate limiting with retry.
		if ( 429 === $status && $retry_count < 1 ) {
			$retry_after = wp_remote_retrieve_header( $response, 'Retry-After' );
			$retry_after = $retry_after ? (int) $retry_after : 2;
			$this->log_debug( "Rate limited (429), retrying after {$retry_after}s" );
			sleep( $retry_after );
			return $this->spotify_request( $method, $endpoint, $body, $access_token, $retry_count + 1 );
		}

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'success' => true,
				'data'    => $data,
				'status'  => $status,
			);
		}

		return array(
			'success' => false,
			'error'   => $data['error']['message'] ?? 'Unknown error',
			'status'  => $status,
			'data'    => $data,
		);
	}

	/**
	 * Get Spotify user ID.
	 *
	 * @param string $access_token Access token.
	 * @return string|null User ID or null on error.
	 */
	private function get_spotify_user_id( $access_token ) {
		$result = $this->spotify_request( 'GET', '/me', null, $access_token );
		if ( ! $result['success'] ) {
			return null;
		}

		return $result['data']['id'] ?? null;
	}

	/**
	 * Check if feature is enabled.
	 *
	 * @return bool
	 */
	private function is_feature_enabled() {
		return (int) get_option( 'bspfy_save_playlist_enabled', 1 ) === 1;
	}

	/**
	 * Check if user already saved this playlist to Spotify.
	 *
	 * GET /bspfy/v1/playlist/status?id=123
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function playlist_status( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', __( 'Playlist ID is required.', 'betait-spfy-playlist' ), array( 'status' => 400 ) );
		}

		// Guests don't have persistent mappings.
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array(
					'saved'        => false,
					'spotify_url'  => null,
					'spotify_id'   => null,
					'message'      => __( 'Not logged in – no persistent mapping.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		$user_id = get_current_user_id();
		$meta_key = 'bspfy_user_pl_' . $post_id;
		$spotify_id = get_user_meta( $user_id, $meta_key, true );

		// No mapping exists.
		if ( empty( $spotify_id ) ) {
			return new WP_REST_Response(
				array(
					'saved'        => false,
					'spotify_url'  => null,
					'spotify_id'   => null,
					'message'      => __( 'Playlist not yet saved to Spotify.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		// Verify playlist still exists on Spotify.
		$token = $this->get_access_token();
		if ( ! $token ) {
			return new WP_REST_Response(
				array(
					'saved'        => false,
					'spotify_url'  => null,
					'spotify_id'   => null,
					'message'      => __( 'Not authenticated with Spotify.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		$url = 'https://api.spotify.com/v1/playlists/' . $spotify_id;
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[BSPFY Status] Error checking playlist: ' . $response->get_error_message() );
			return new WP_REST_Response(
				array(
					'saved'        => false,
					'spotify_url'  => null,
					'spotify_id'   => $spotify_id,
					'message'      => __( 'Could not verify playlist on Spotify.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Playlist was deleted from Spotify.
		if ( 404 === $code ) {
			error_log( "[BSPFY Status] Playlist $spotify_id no longer exists on Spotify. Clearing user mapping." );
			delete_user_meta( $user_id, $meta_key );
			return new WP_REST_Response(
				array(
					'saved'        => false,
					'spotify_url'  => null,
					'spotify_id'   => null,
					'message'      => __( 'Playlist was deleted from Spotify.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		// Playlist exists.
		if ( 200 === $code && isset( $data['external_urls']['spotify'] ) ) {
			return new WP_REST_Response(
				array(
					'saved'        => true,
					'spotify_url'  => $data['external_urls']['spotify'],
					'spotify_id'   => $spotify_id,
					'name'         => $data['name'] ?? '',
					'track_count'  => $data['tracks']['total'] ?? 0,
					'message'      => __( 'Playlist already saved to Spotify.', 'betait-spfy-playlist' ),
				),
				200
			);
		}

		// Unexpected response.
		error_log( "[BSPFY Status] Unexpected response from Spotify: $code" );
		return new WP_REST_Response(
			array(
				'saved'        => false,
				'spotify_url'  => null,
				'spotify_id'   => $spotify_id,
				'message'      => __( 'Unexpected response from Spotify.', 'betait-spfy-playlist' ),
			),
			200
		);
	}

	/**
	 * Preview playlist data.
	 *
	 * GET /bspfy/v1/playlist/preview?id=123
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_playlist( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', __( 'Playlist ID is required.', 'betait-spfy-playlist' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'playlist' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Playlist not found.', 'betait-spfy-playlist' ), array( 'status' => 404 ) );
		}

		$tracks_json = get_post_meta( $post_id, '_playlist_tracks', true );
		$tracks      = $tracks_json ? json_decode( $tracks_json, true ) : array();
		if ( ! is_array( $tracks ) ) {
			$tracks = array();
		}

		// Check for custom cover image and use_cover flag.
		$use_custom_cover = (bool) get_post_meta( $post_id, '_playlist_spotify_use_cover', true );
		$has_cover        = $use_custom_cover && get_post_meta( $post_id, '_playlist_spotify_image_id', true );

		// Get title template (post-specific or global fallback).
		$title_template = get_post_meta( $post_id, '_playlist_spotify_title_template', true );
		if ( empty( $title_template ) ) {
			$title_template = get_option( 'bspfy_save_playlist_title_template', '{{playlistTitle}} – {{siteName}}' );
		}
		$title = str_replace(
			array( '{{playlistTitle}}', '{{siteName}}' ),
			array( $post->post_title, get_bloginfo( 'name' ) ),
			$title_template
		);

		// Get description template (post-specific or global fallback).
		$desc_template = get_post_meta( $post_id, '_playlist_spotify_description_template', true );
		if ( empty( $desc_template ) ) {
			$desc_template = get_option( 'bspfy_save_playlist_description_template', '' );
		}
		$description = str_replace(
			array( '{{playlistTitle}}', '{{siteName}}', '{{playlistExcerpt}}' ),
			array( $post->post_title, get_bloginfo( 'name' ), wp_trim_words( $post->post_excerpt, 20 ) ),
			$desc_template
		);

		return new WP_REST_Response(
			array(
				'success'     => true,
				'title'       => $title,
				'description' => $description,
				'track_count' => count( $tracks ),
				'has_cover'   => $has_cover,
			),
			200
		);
	}

	/**
	 * Save playlist to user's Spotify account.
	 *
	 * POST /bspfy/v1/playlist/save
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_playlist( $request ) {
		// Check if feature is enabled.
		if ( ! $this->is_feature_enabled() ) {
			return new WP_Error( 'feature_disabled', __( 'This feature is currently disabled.', 'betait-spfy-playlist' ), array( 'status' => 403 ) );
		}

		$post_id     = (int) $request->get_param( 'post_id' );
		$visibility  = sanitize_key( $request->get_param( 'visibility' ) );
		$title       = sanitize_text_field( $request->get_param( 'title' ) );
		$description = sanitize_textarea_field( $request->get_param( 'description' ) );
		$use_cover   = (bool) $request->get_param( 'use_cover' );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'Playlist ID is required.', 'betait-spfy-playlist' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'playlist' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Playlist not found.', 'betait-spfy-playlist' ), array( 'status' => 404 ) );
		}

		// Get access token.
		$access_token = $this->get_access_token();
		if ( ! $access_token ) {
			return new WP_Error( 'not_authenticated', __( 'Not authenticated. Please connect your Spotify account.', 'betait-spfy-playlist' ), array( 'status' => 401 ) );
		}

		// Get tracks.
		$tracks_json = get_post_meta( $post_id, '_playlist_tracks', true );
		$tracks      = $tracks_json ? json_decode( $tracks_json, true ) : array();
		if ( ! is_array( $tracks ) || empty( $tracks ) ) {
			return new WP_Error( 'empty_playlist', __( 'This playlist is empty.', 'betait-spfy-playlist' ), array( 'status' => 400 ) );
		}

		// Get Spotify user ID.
		$spotify_user_id = $this->get_spotify_user_id( $access_token );
		if ( ! $spotify_user_id ) {
			return new WP_Error( 'user_fetch_failed', __( 'Failed to fetch Spotify user information.', 'betait-spfy-playlist' ), array( 'status' => 500 ) );
		}

		$this->log_debug( "Creating playlist for Spotify user: {$spotify_user_id}" );

		// Check for existing mapping (only for logged-in WP users).
		$playlist_id = null;
		$existing_id = null;
		
		if ( is_user_logged_in() ) {
			$user_id     = get_current_user_id();
			$mapping_key = "bspfy_user_pl_{$post_id}";
			$existing_id = get_user_meta( $user_id, $mapping_key, true );

			// If mapping exists, verify playlist still exists.
			if ( $existing_id ) {
				$check = $this->spotify_request( 'GET', "/playlists/{$existing_id}", null, $access_token );
				if ( $check['success'] ) {
					$playlist_id = $existing_id;
					$this->log_debug( "Found existing playlist for logged-in user: {$playlist_id}" );
				} else {
					// Playlist deleted, remove mapping.
					delete_user_meta( $user_id, $mapping_key );
					$existing_id = null;
				}
			}
		} else {
			// For guests, we always create a new playlist (no persistent mapping).
			$this->log_debug( 'Guest user - will create new playlist' );
		}

		// Create new playlist if needed.
		if ( ! $playlist_id ) {
			$is_public = ( 'public' === $visibility );

			$create_body = array(
				'name'        => wp_strip_all_tags( $title ),
				'description' => wp_strip_all_tags( $description ),
				'public'      => $is_public,
			);

			$result = $this->spotify_request( 'POST', "/users/{$spotify_user_id}/playlists", $create_body, $access_token );

			if ( ! $result['success'] ) {
				$error_msg = $result['error'] ?? __( 'Failed to create playlist.', 'betait-spfy-playlist' );
				return new WP_Error( 'create_failed', $error_msg, array( 'status' => $result['status'] ?? 500 ) );
			}

			$playlist_id = $result['data']['id'] ?? null;
			if ( ! $playlist_id ) {
				return new WP_Error( 'no_playlist_id', __( 'No playlist ID returned.', 'betait-spfy-playlist' ), array( 'status' => 500 ) );
			}

			// Store mapping (only for logged-in users).
			if ( is_user_logged_in() ) {
				update_user_meta( $user_id, $mapping_key, $playlist_id );
				$this->log_debug( "Created new playlist: {$playlist_id} (saved mapping for user {$user_id})" );
			} else {
				$this->log_debug( "Created new playlist: {$playlist_id} (no mapping saved for guest)" );
			}
		}

		// Upload cover image if requested and available.
		$this->log_debug( "use_cover param: " . ( $use_cover ? 'true' : 'false' ) );
		$this->log_debug( "has_post_thumbnail: " . ( has_post_thumbnail( $post_id ) ? 'true' : 'false' ) );
		
		if ( $use_cover ) {
			$custom_img_id = get_post_meta( $post_id, '_playlist_spotify_image_id', true );
			$this->log_debug( "Custom Spotify image ID: " . ( $custom_img_id ? $custom_img_id : 'none' ) );
			
			if ( $custom_img_id || has_post_thumbnail( $post_id ) ) {
				$this->log_debug( "Attempting to upload cover image to playlist {$playlist_id}" );
				$cover_uploaded = $this->upload_cover_image( $playlist_id, $post_id, $access_token );
				if ( $cover_uploaded ) {
					$this->log_debug( 'Cover image uploaded successfully' );
				} else {
					$this->log_debug( 'Cover image upload failed or skipped' );
				}
			} else {
				$this->log_debug( 'No cover image available to upload' );
			}
		} else {
			$this->log_debug( 'Cover upload not requested (use_cover=false)' );
		}

		// Add tracks in batches of 100.
		$track_uris = array();
		foreach ( $tracks as $track ) {
			if ( isset( $track['uri'] ) ) {
				$track_uris[] = $track['uri'];
			}
		}

		$this->log_debug( 'Total tracks to process: ' . count( $track_uris ) );
		$this->log_debug( 'First few URIs: ' . json_encode( array_slice( $track_uris, 0, 3 ) ) );

		$added   = 0;
		$skipped = 0;

		// Get existing tracks if this is an existing playlist.
		$existing_uris = array();
		if ( $existing_id ) {
			$this->log_debug( "Fetching existing tracks from playlist {$playlist_id}" );
			$existing_tracks = $this->get_playlist_tracks( $playlist_id, $access_token );
			foreach ( $existing_tracks as $item ) {
				if ( isset( $item['track']['uri'] ) ) {
					$existing_uris[] = $item['track']['uri'];
				}
			}
			$this->log_debug( 'Found ' . count( $existing_uris ) . ' existing tracks' );
		}

		// Filter out tracks that are already in the playlist.
		$uris_to_add = array_diff( $track_uris, $existing_uris );
		$skipped     = count( $track_uris ) - count( $uris_to_add );

		$this->log_debug( "Tracks to add: " . count( $uris_to_add ) . ", skipped: {$skipped}" );

		if ( empty( $uris_to_add ) ) {
			$this->log_debug( 'No new tracks to add - all tracks already in playlist' );
		} else {
			$batches = array_chunk( $uris_to_add, 100 );
			$this->log_debug( 'Processing ' . count( $batches ) . ' batches' );
			
			foreach ( $batches as $batch_num => $batch ) {
				$this->log_debug( "Adding batch " . ( $batch_num + 1 ) . " with " . count( $batch ) . " tracks" );
				$add_result = $this->spotify_request(
					'POST',
					"/playlists/{$playlist_id}/tracks",
					array( 'uris' => $batch ),
					$access_token
				);

				if ( $add_result['success'] ) {
					$added += count( $batch );
					$this->log_debug( "Batch " . ( $batch_num + 1 ) . " added successfully" );
				} else {
					$this->log_debug( "Failed to add batch " . ( $batch_num + 1 ), $add_result );
				}
			}
		}

		$playlist_url = "https://open.spotify.com/playlist/{$playlist_id}";

		return new WP_REST_Response(
			array(
				'success'             => true,
				'spotify_playlist_id' => $playlist_id,
				'spotify_playlist_url' => $playlist_url,
				'added'               => $added,
				'skipped'             => $skipped,
			),
			200
		);
	}

	/**
	 * Get all tracks from a playlist (handles pagination).
	 *
	 * @param string $playlist_id Playlist ID.
	 * @param string $access_token Access token.
	 * @return array Array of track items.
	 */
	private function get_playlist_tracks( $playlist_id, $access_token ) {
		$all_items = array();
		$offset    = 0;
		$limit     = 100;

		do {
			$result = $this->spotify_request(
				'GET',
				"/playlists/{$playlist_id}/tracks?limit={$limit}&offset={$offset}",
				null,
				$access_token
			);

			if ( ! $result['success'] ) {
				break;
			}

			$items = $result['data']['items'] ?? array();
			$all_items = array_merge( $all_items, $items );

			$offset += $limit;
		} while ( ! empty( $items ) );

		return $all_items;
	}

	/**
	 * Upload cover image to playlist.
	 *
	 * @param string $playlist_id Playlist ID.
	 * @param int    $post_id Post ID.
	 * @param string $access_token Access token.
	 * @return bool Success status.
	 */
	private function upload_cover_image( $playlist_id, $post_id, $access_token ) {
		// Check for custom Spotify image first, fallback to post thumbnail.
		$thumbnail_id = (int) get_post_meta( $post_id, '_playlist_spotify_image_id', true );
		if ( ! $thumbnail_id ) {
			$thumbnail_id = get_post_thumbnail_id( $post_id );
		}
		if ( ! $thumbnail_id ) {
			$this->log_debug( 'No image found for upload' );
			return false;
		}

		$this->log_debug( "Found image ID: {$thumbnail_id}" );

		// Get image path.
		$image_path = get_attached_file( $thumbnail_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			$this->log_debug( "Image file not found or invalid path: " . ( $image_path ?: 'null' ) );
			return false;
		}

		$this->log_debug( "Image path: {$image_path}" );
		$this->log_debug( "Image file size: " . filesize( $image_path ) . " bytes" );
		$this->log_debug( "Image mime type: " . mime_content_type( $image_path ) );

		// Convert to JPEG if needed and resize to max 256KB.
		$jpeg_data = $this->prepare_cover_image( $image_path );
		if ( ! $jpeg_data ) {
			$this->log_debug( 'prepare_cover_image() returned false or empty' );
			return false;
		}

		$this->log_debug( "Prepared base64 data length: " . strlen( $jpeg_data ) . " characters" );

		// Check if we have ugc-image-upload scope by calling /me to see current scopes.
		$me_result = $this->spotify_request( 'GET', '/me', null, $access_token );
		if ( $me_result['success'] ) {
			$this->log_debug( 'User authenticated, checking available scopes...' );
			// Note: Spotify doesn't return scopes in /me, but we can infer from auth flow.
			// Let's try the upload and see what happens.
		}

		// Upload to Spotify.
		// Note: Spotify expects base64-encoded JPEG data as the body.
		// The Content-Type must be "image/jpeg" even though the body is base64-encoded.
		$url = $this->api_base . "/playlists/{$playlist_id}/images";

		$args = array(
			'method'  => 'PUT',
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'image/jpeg',
			),
			'body'    => $jpeg_data, // Already base64-encoded from prepare_cover_image.
			'timeout' => 30,
		);

		$this->log_debug( "Uploading cover to Spotify: {$url}" );
		$this->log_debug( "Body length (base64): " . strlen( $jpeg_data ) . " chars" );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'Cover upload error: ' . $response->get_error_message() );
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		$this->log_debug( "Spotify cover upload response: status={$status}, body=" . substr( $body, 0, 500 ) );

		if ( $status >= 200 && $status < 300 ) {
			$this->log_debug( 'Cover uploaded successfully!' );
			return true;
		} else {
			$this->log_debug( "Cover upload failed with status {$status}" );
			return false;
		}
	}

	/**
	 * Prepare cover image (convert to JPEG, resize to fit 256KB limit).
	 *
	 * @param string $image_path Path to image file.
	 * @return string|false Base64-encoded JPEG data or false on error.
	 */
	private function prepare_cover_image( $image_path ) {
		$this->log_debug( "Starting prepare_cover_image for: {$image_path}" );
		
		// If this is an AVIF file, try to find the original JPEG in the same directory.
		if ( preg_match( '/\.avif$/i', $image_path ) ) {
			$jpeg_path = preg_replace( '/\.avif$/i', '.jpg', $image_path );
			if ( file_exists( $jpeg_path ) ) {
				$this->log_debug( "Found original JPEG file: {$jpeg_path}" );
				$image_path = $jpeg_path;
			} else {
				$this->log_debug( "No original JPEG found at: {$jpeg_path}, using AVIF" );
			}
		}
		
		$editor = wp_get_image_editor( $image_path );
		if ( is_wp_error( $editor ) ) {
			$this->log_debug( 'wp_get_image_editor failed: ' . $editor->get_error_message() );
			return false;
		}

		$size = $editor->get_size();
		$this->log_debug( "Original image dimensions: {$size['width']}x{$size['height']}" );

		// Resize to 300x300 (Spotify's minimum recommended size).
		$resize_result = $editor->resize( 300, 300, false );
		if ( is_wp_error( $resize_result ) ) {
			$this->log_debug( 'Image resize failed: ' . $resize_result->get_error_message() );
			return false;
		}
		$this->log_debug( 'Resized to 300x300' );

		$editor->set_quality( 90 );
		
		// Create a temporary file for JPEG output.
		$temp_dir = sys_get_temp_dir();
		$temp_file = $temp_dir . '/spotify-cover-' . uniqid() . '.jpg';
		
		// Try to use GD directly if we can access the image resource.
		$jpeg_saved = false;
		
		// Check if this is a GD editor and we can use reflection to get the resource.
		if ( is_a( $editor, 'WP_Image_Editor_GD' ) && function_exists( 'imagejpeg' ) ) {
			try {
				$reflection = new ReflectionClass( $editor );
				$property = $reflection->getProperty( 'image' );
				$property->setAccessible( true );
				$image_resource = $property->getValue( $editor );
				
				if ( $image_resource && ( is_resource( $image_resource ) || is_a( $image_resource, 'GdImage' ) ) ) {
					$jpeg_saved = imagejpeg( $image_resource, $temp_file, 90 );
					$this->log_debug( "Direct imagejpeg() save: " . ( $jpeg_saved ? 'success' : 'failed' ) );
				}
			} catch ( Exception $e ) {
				$this->log_debug( 'Reflection failed: ' . $e->getMessage() );
			}
		}
		
		// Fallback to WordPress save if direct save failed.
		if ( ! $jpeg_saved || ! file_exists( $temp_file ) ) {
			$this->log_debug( 'Using WordPress save method as fallback' );
			$saved = $editor->save( $temp_file );
			
			if ( is_wp_error( $saved ) ) {
				$this->log_debug( 'JPEG save failed: ' . $saved->get_error_message() );
				return false;
			}
			
			$temp_file = $saved['path'];
		}
		
		$jpeg_path = $temp_file;
		$this->log_debug( "Saved JPEG to: {$jpeg_path}" );
		
		// Verify it's actually a JPEG.
		if ( file_exists( $jpeg_path ) ) {
			$jpeg_mime = mime_content_type( $jpeg_path );
			$this->log_debug( "Converted file mime type: {$jpeg_mime}" );
		}
		
		$jpeg_data = file_get_contents( $jpeg_path );
		$initial_size = strlen( $jpeg_data );
		$this->log_debug( "Initial JPEG size: {$initial_size} bytes (quality 90)" );
		
		// Verify JPEG magic bytes (FF D8 FF).
		$magic = bin2hex( substr( $jpeg_data, 0, 3 ) );
		$this->log_debug( "JPEG magic bytes: {$magic} (should be ffd8ff)" );

		// Check size limit (256KB).
		if ( $initial_size > 256 * 1024 ) {
			$this->log_debug( 'Image too large, reducing quality to 75' );
			// Reduce quality and try again.
			$editor->set_quality( 75 );
			$temp_file2 = $temp_dir . '/spotify-cover-' . uniqid() . '-q75.jpg';
			$saved = $editor->save( $temp_file2 );
			if ( is_wp_error( $saved ) ) {
				$this->log_debug( 'JPEG save (quality 75) failed: ' . $saved->get_error_message() );
				// Clean up first temp file.
				if ( file_exists( $temp_file ) ) {
					unlink( $temp_file );
				}
				return false;
			}
			
			// Clean up first temp file.
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			
			$jpeg_path = $saved['path'];
			$jpeg_data = file_get_contents( $jpeg_path );
			$reduced_size = strlen( $jpeg_data );
			$this->log_debug( "Reduced JPEG size: {$reduced_size} bytes (quality 75)" );

			// Still too large?
			if ( $reduced_size > 256 * 1024 ) {
				$this->log_debug( "Cover image still too large even after compression: {$reduced_size} bytes" );
				if ( file_exists( $jpeg_path ) ) {
					unlink( $jpeg_path );
				}
				return false;
			}
		}

		// Base64 encode the image data (Spotify API requirement).
		$base64_data = base64_encode( $jpeg_data );
		$this->log_debug( 'Image preparation completed successfully, base64 encoded' );
		
		// Clean up temp file.
		if ( file_exists( $jpeg_path ) ) {
			unlink( $jpeg_path );
		}
		
		return $base64_data;
	}
}
