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
}
