<?php
/**
 * Shortcode handler for displaying Spotify playlists.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler class for [bspfy_playlist] shortcode.
 */
class Betait_Spfy_Playlist_Shortcode {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'bspfy_playlist', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the [bspfy_playlist] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Shortcode output.
	 */
	public function render_shortcode( $atts ) {
		// Parse shortcode attributes.
		$atts = shortcode_atts(
			array(
				'id'          => 0,
				'show_title'  => 'yes',
				'show_player' => 'yes',
				'show_tracks' => 'yes',
				'show_footer' => 'yes',
				'limit'       => 0,
			),
			$atts,
			'bspfy_playlist'
		);

		// Validate playlist ID.
		$playlist_id = absint( $atts['id'] );
		if ( ! $playlist_id || 'playlist' !== get_post_type( $playlist_id ) || 'publish' !== get_post_status( $playlist_id ) ) {
			return '';
		}

		// Enqueue widget assets directly when shortcode is rendered (most reliable method).
		$this->enqueue_widget_assets();

		// Parse display options.
		$options = array(
			'show_title'  => 'yes' === strtolower( $atts['show_title'] ),
			'show_player' => 'yes' === strtolower( $atts['show_player'] ),
			'show_tracks' => 'yes' === strtolower( $atts['show_tracks'] ),
			'show_footer' => 'yes' === strtolower( $atts['show_footer'] ),
			'limit'       => absint( $atts['limit'] ),
		);

		// Start output buffering.
		ob_start();

		// Use shared template (same as widget for consistent styling).
		$template = plugin_dir_path( dirname( __FILE__ ) ) . 'templates/widget-playlist-template.php';
		if ( file_exists( $template ) ) {
			include $template;
		}

		$output = ob_get_clean();
		
		// Remove any unwanted <p> and <br> tags added by wpautop().
		// This prevents WordPress from auto-wrapping our content with paragraph tags.
		$output = preg_replace( '/<p>\s*<\/p>/', '', $output ); // Remove empty <p></p> tags
		$output = preg_replace( '/<p>\s+/', '<p>', $output ); // Remove whitespace after opening <p>
		$output = preg_replace( '/\s+<\/p>/', '</p>', $output ); // Remove whitespace before closing </p>
		$output = str_replace( array( '<p>', '</p>' ), '', $output ); // Remove remaining <p> tags
		
		return $output;
	}

	/**
	 * Enqueue widget-specific assets.
	 * Called when shortcode is actually rendered to ensure assets load.
	 *
	 * @return void
	 */
	private function enqueue_widget_assets() {
		$plugin_version = defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : '1.0.0';
		$ver = ( defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ) ? time() : $plugin_version;

		// Ensure main plugin public assets are loaded first (widget JS depends on bspfyAuth).
		$this->enqueue_public_dependencies( $ver );

		// Enqueue widget CSS.
		wp_enqueue_style(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/betait-spfy-playlist-widget.css',
			array(),
			$ver,
			'all'
		);

		// Enqueue widget JS with dependency on main plugin JS.
		wp_enqueue_script(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/betait-spfy-playlist-widget.js',
			array( 'jquery', 'betait-spfy-playlist' ),
			$ver,
			true
		);
	}

	/**
	 * Enqueue public plugin dependencies required for shortcode functionality.
	 * Shortcode requires bspfyAuth from main plugin JS.
	 *
	 * @param string $ver Version string for cache busting.
	 * @return void
	 */
	private function enqueue_public_dependencies( $ver ) {
		// Enqueue overlay preloader JS (dependency for main plugin JS).
		if ( ! wp_script_is( 'bspfy-overlay', 'enqueued' ) ) {
			wp_enqueue_script(
				'bspfy-overlay',
				plugins_url(
					'assets/js/bspfy-overlay.js',
					defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ? BETAIT_SPFY_PLAYLIST_FILE : dirname( dirname( __FILE__ ) ) . '/betait-spfy-playlist.php'
				),
				array(),
				$ver,
				true
			);
		}

		// Enqueue Spotify SDK (dependency for player functionality).
		if ( ! wp_script_is( 'spotify-sdk', 'enqueued' ) ) {
			wp_enqueue_script(
				'spotify-sdk',
				'https://sdk.scdn.co/spotify-player.js',
				array(),
				null,
				true
			);
		}

		// Enqueue main plugin JS (provides bspfyAuth).
		if ( ! wp_script_is( 'betait-spfy-playlist', 'enqueued' ) ) {
			wp_enqueue_script(
				'betait-spfy-playlist',
				plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/betait-spfy-playlist-public.js',
				array( 'jquery', 'bspfy-overlay' ),
				$ver,
				true
			);

			// Localize script with necessary config data.
			$this->localize_public_script();
		}

		// Enqueue main plugin CSS.
		if ( ! wp_style_is( 'betait-spfy-playlist', 'enqueued' ) ) {
			wp_enqueue_style(
				'betait-spfy-playlist',
				plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/betait-spfy-playlist-public.css',
				array(),
				$ver,
				'all'
			);
		}

		// Enqueue Font Awesome.
		if ( ! wp_style_is( 'bspfy-font-awesome', 'enqueued' ) ) {
			wp_enqueue_style(
				'bspfy-font-awesome',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
				array(),
				'6.5.0',
				'all'
			);
		}
	}

	/**
	 * Localize main plugin script with configuration data.
	 *
	 * @return void
	 */
	private function localize_public_script() {
		// Provide the same config that the public class normally provides.
		wp_localize_script(
			'betait-spfy-playlist',
			'bspfyPublic',
			array(
				// Player.
				'player_name'     => get_option( 'bspfy_player_name', 'BeTA iT Web Player' ),
				'default_volume'  => (float) get_option( 'bspfy_default_volume', 0.5 ),
				'player_theme'    => get_option( 'bspfy_player_theme', 'default' ),

				// Playlist.
				'playlist_theme'  => get_option( 'bspfy_playlist_theme', 'default' ),

				// Misc config.
				'debug'           => (bool) get_option( 'bspfy_debug', 0 ),
				'rest_base'       => esc_url_raw( rest_url( 'bspfy/v1/' ) ),
				'rest_nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',

				// Feature flags.
				'require_premium' => (bool) apply_filters( 'bspfy_require_premium', (bool) get_option( 'bspfy_require_premium', 1 ) ),
				'strict_samesite' => (bool) apply_filters( 'bspfy_strict_samesite', (bool) get_option( 'bspfy_strict_samesite', 0 ) ),
			)
		);
	}
}
