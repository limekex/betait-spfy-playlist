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
	}

	/**
	 * Check if user is logged in for saving playlist.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return is_user_logged_in();
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
	 * Get access token from cookie/session.
	 *
	 * @return string|null Access token or null if not found.
	 */
	private function get_access_token() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$cache = get_user_meta( $user_id, 'bspfy_access_cache', true );
		if ( ! is_array( $cache ) ) {
			return null;
		}

		$token     = $cache['access_token'] ?? '';
		$expires   = $cache['expires_in'] ?? 0;
		$is_valid  = $token && ( $expires > time() );

		return $is_valid ? $token : null;
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

		$has_cover = has_post_thumbnail( $post_id );

		$title_template = get_option( 'bspfy_save_playlist_title_template', '{{playlistTitle}} – {{siteName}}' );
		$title          = str_replace(
			array( '{{playlistTitle}}', '{{siteName}}' ),
			array( $post->post_title, get_bloginfo( 'name' ) ),
			$title_template
		);

		$desc_template = get_option( 'bspfy_save_playlist_description_template', '' );
		$description   = str_replace(
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

		$this->log_debug( "Creating playlist for user: {$spotify_user_id}" );

		// Check for existing mapping.
		$user_id     = get_current_user_id();
		$mapping_key = "bspfy_user_pl_{$post_id}";
		$existing_id = get_user_meta( $user_id, $mapping_key, true );

		$playlist_id = null;

		// If mapping exists, verify playlist still exists.
		if ( $existing_id ) {
			$check = $this->spotify_request( 'GET', "/playlists/{$existing_id}", null, $access_token );
			if ( $check['success'] ) {
				$playlist_id = $existing_id;
				$this->log_debug( "Found existing playlist: {$playlist_id}" );
			} else {
				// Playlist deleted, remove mapping.
				delete_user_meta( $user_id, $mapping_key );
			}
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

			// Store mapping.
			update_user_meta( $user_id, $mapping_key, $playlist_id );
			$this->log_debug( "Created new playlist: {$playlist_id}" );
		}

		// Upload cover image if requested and available.
		if ( $use_cover && has_post_thumbnail( $post_id ) ) {
			$cover_uploaded = $this->upload_cover_image( $playlist_id, $post_id, $access_token );
			if ( ! $cover_uploaded ) {
				$this->log_debug( 'Cover image upload failed or skipped' );
			}
		}

		// Add tracks in batches of 100.
		$track_uris = array();
		foreach ( $tracks as $track ) {
			if ( isset( $track['uri'] ) ) {
				$track_uris[] = $track['uri'];
			}
		}

		$added   = 0;
		$skipped = 0;

		// Get existing tracks if this is an existing playlist.
		$existing_uris = array();
		if ( $existing_id ) {
			$existing_tracks = $this->get_playlist_tracks( $playlist_id, $access_token );
			foreach ( $existing_tracks as $item ) {
				if ( isset( $item['track']['uri'] ) ) {
					$existing_uris[] = $item['track']['uri'];
				}
			}
		}

		// Filter out tracks that are already in the playlist.
		$uris_to_add = array_diff( $track_uris, $existing_uris );
		$skipped     = count( $track_uris ) - count( $uris_to_add );

		$batches = array_chunk( $uris_to_add, 100 );
		foreach ( $batches as $batch ) {
			$add_result = $this->spotify_request(
				'POST',
				"/playlists/{$playlist_id}/tracks",
				array( 'uris' => $batch ),
				$access_token
			);

			if ( $add_result['success'] ) {
				$added += count( $batch );
			} else {
				$this->log_debug( 'Failed to add batch', $add_result );
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
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return false;
		}

		// Get image path.
		$image_path = get_attached_file( $thumbnail_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return false;
		}

		// Convert to JPEG if needed and resize to max 256KB.
		$jpeg_data = $this->prepare_cover_image( $image_path );
		if ( ! $jpeg_data ) {
			return false;
		}

		// Upload to Spotify.
		$url = $this->api_base . "/playlists/{$playlist_id}/images";

		$args = array(
			'method'  => 'PUT',
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'image/jpeg',
			),
			'body'    => $jpeg_data,
			'timeout' => 30,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'Cover upload error: ' . $response->get_error_message() );
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		return ( $status >= 200 && $status < 300 );
	}

	/**
	 * Prepare cover image (convert to JPEG, resize to fit 256KB limit).
	 *
	 * @param string $image_path Path to image file.
	 * @return string|false Base64-encoded JPEG data or false on error.
	 */
	private function prepare_cover_image( $image_path ) {
		$editor = wp_get_image_editor( $image_path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		// Resize to reasonable dimensions (Spotify recommends at least 300x300).
		$editor->resize( 640, 640, false );

		// Save as JPEG.
		$editor->set_quality( 90 );
		$saved = $editor->save( null, 'image/jpeg' );

		if ( is_wp_error( $saved ) ) {
			return false;
		}

		$jpeg_path = $saved['path'];
		$jpeg_data = file_get_contents( $jpeg_path );

		// Check size limit (256KB).
		if ( strlen( $jpeg_data ) > 256 * 1024 ) {
			// Reduce quality and try again.
			$editor->set_quality( 75 );
			$saved = $editor->save( null, 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				return false;
			}
			$jpeg_path = $saved['path'];
			$jpeg_data = file_get_contents( $jpeg_path );

			// Still too large?
			if ( strlen( $jpeg_data ) > 256 * 1024 ) {
				$this->log_debug( 'Cover image too large even after compression' );
				return false;
			}
		}

		// Clean up temp file if it's different from original.
		if ( $jpeg_path !== $image_path && file_exists( $jpeg_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $jpeg_path );
		}

		return $jpeg_data;
	}
}
