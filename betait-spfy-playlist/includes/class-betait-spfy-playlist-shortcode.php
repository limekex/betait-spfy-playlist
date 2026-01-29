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

		// Use shared template.
		$template = plugin_dir_path( dirname( __FILE__ ) ) . 'templates/widget-playlist-template.php';
		if ( file_exists( $template ) ) {
			echo '<div class="bspfy-shortcode-wrapper">';
			include $template;
			echo '</div>';
		}

		return ob_get_clean();
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

		// Enqueue widget CSS.
		wp_enqueue_style(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/betait-spfy-playlist-widget.css',
			array(),
			$ver,
			'all'
		);

		// Enqueue widget JS.
		wp_enqueue_script(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/betait-spfy-playlist-widget.js',
			array( 'jquery', 'betait-spfy-playlist' ),
			$ver,
			true
		);
	}
}
