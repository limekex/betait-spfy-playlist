<?php
/**
 * Public-facing assets and template loader for Betait Spfy Playlist.
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/public
 * @author    Bjorn-Tore <bt@betait.no>
 * @link      https://betait.no
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles enqueueing styles/scripts on the public side and loading the CPT template.
 */
class Betait_Spfy_Playlist_Public {

	/**
	 * Plugin handle/slug used for asset handles.
	 *
	 * @var string
	 */
	private $betait_spfy_playlist;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $betait_spfy_playlist Plugin handle/slug.
	 * @param string $version              Plugin version.
	 */
	public function __construct( $betait_spfy_playlist, $version ) {
		$this->betait_spfy_playlist = $betait_spfy_playlist;
		$this->version              = $version;

		// Load a custom single template for the playlist CPT.
		add_filter( 'template_include', array( $this, 'load_playlist_template' ) );
	}

	/**
	 * Decide whether public assets should load on the current request.
	 *
	 * By default: load on single playlist screens or if a known shortcode is present.
	 * Developers can override via the 'bspfy_should_enqueue_public' filter.
	 *
	 * @return bool
	 */
	private function should_enqueue_public_assets() : bool {
		$should = false;

		if ( is_singular( 'playlist' ) ) {
			$should = true;
		} else {
			// Check for shortcodes on regular pages/posts.
			if ( is_singular() ) {
				$post = get_post();
				if ( $post && is_a( $post, 'WP_Post' ) ) {
					$content = $post->post_content ?? '';
					// Keep this list in sync with your real shortcodes.
					$shortcodes = array( 'bspfy_save_button', 'bspfy_player' );
					foreach ( $shortcodes as $sc ) {
						if ( has_shortcode( $content, $sc ) ) {
							$should = true;
							break;
						}
					}
				}
			}
		}

		/**
		 * Filter: force-enable or disable public assets on custom conditions.
		 *
		 * @param bool $should Current decision.
		 */
		return (bool) apply_filters( 'bspfy_should_enqueue_public', $should );
	}

	/**
	 * Enqueue styles for the public-facing site.
	 *
	 * Keep this lean; we early-return if not needed.
	 *
	 * @return void
	 */
	public function enqueue_styles() : void {
		if ( ! $this->should_enqueue_public_assets() ) {
			return;
		}

		$ver = ( defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ) ? time() : $this->version;

		// Base public stylesheet for the plugin.
		wp_enqueue_style(
			$this->betait_spfy_playlist,
			plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-public.css',
			array(),
			$ver,
			'all'
		);

		// Font Awesome (avoid double-loading if a theme already provides it).
		if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
			wp_enqueue_style(
				'font-awesome',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
				array(),
				'6.5.0',
				'all'
			);
		}

		// Overlay preloader CSS.
		wp_enqueue_style(
			'bspfy-overlay',
			plugin_dir_url( __FILE__ ) . '../assets/css/bspfy-overlay.css',
			array(),
			$ver,
			'all'
		);
	}

	/**
	 * Enqueue scripts for the public-facing site.
	 *
	 * @return void
	 */
	public function enqueue_scripts() : void {
		if ( ! $this->should_enqueue_public_assets() ) {
			return;
		}

		$ver = ( defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ) ? time() : $this->version;

		// 1) Overlay preloader JS first so it's available to other scripts.
		wp_enqueue_script(
			'bspfy-overlay',
			plugins_url(
				'assets/js/bspfy-overlay.js',
				defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ? BETAIT_SPFY_PLAYLIST_FILE : ''
			),
			array(),
			$ver,
			true
		);

		// 2) Spotify Web Playback SDK (footer).
		wp_enqueue_script(
			'spotify-sdk',
			'https://sdk.scdn.co/spotify-player.js',
			array(),
			null,
			true
		);

		// 3) Plugin public JS (depends on jQuery and overlay).
		wp_enqueue_script(
			$this->betait_spfy_playlist,
			plugin_dir_url( __FILE__ ) . 'js/betait-spfy-playlist-public.js',
			array( 'jquery', 'bspfy-overlay' ),
			$ver,
			true
		);

		// Expose safe configuration to the frontend (no secrets).
		wp_localize_script(
			$this->betait_spfy_playlist,
			'bspfyPublic',
			array(
				// Player.
				'player_name'     => get_option( 'bspfy_player_name', 'BeTA iT Web Player' ),
				'default_volume'  => (float) get_option( 'bspfy_default_volume', 0.5 ), // 0–1
				'player_theme'    => get_option( 'bspfy_player_theme', 'default' ),

				// Playlist.
				'playlist_theme'  => get_option( 'bspfy_playlist_theme', 'default' ),

				// Misc config.
				'debug'           => (bool) get_option( 'bspfy_debug', 0 ),
				'rest_base'       => esc_url_raw( rest_url( 'bspfy/v1/' ) ),
				'rest_nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',

				// Feature flags (also filterable).
				'require_premium' => (bool) apply_filters( 'bspfy_require_premium', (bool) get_option( 'bspfy_require_premium', 1 ) ),
				'strict_samesite' => (bool) apply_filters( 'bspfy_strict_samesite', (bool) get_option( 'bspfy_strict_samesite', 0 ) ),
			)
		);
	}

	/**
	 * Load the custom template for the playlist post type (single view).
	 *
	 * @param string $template Default template path.
	 * @return string          Template path to use.
	 */
	public function load_playlist_template( $template ) : string {
		// Adjust the CPT slug if your register_post_type() uses a different one.
		if ( is_singular( 'playlist' ) ) {
			$custom_template = plugin_dir_path( __FILE__ ) . '../templates/playlist-template.php';
			if ( file_exists( $custom_template ) ) {
				return $custom_template;
			}
		}
		return $template;
	}
}
