<?php
/**
 * Gutenberg block registration for BeTA iT – Spotify Playlist.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Betait_Spfy_Playlist_Blocks {

	/**
	 * Constructor: register blocks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register Gutenberg blocks.
	 *
	 * @return void
	 */
	public function register_blocks() {
		// Only register if block editor is available.
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Register the save playlist block.
		register_block_type(
			'bspfy/save-playlist',
			array(
				'render_callback' => array( $this, 'render_save_playlist_block' ),
				'attributes'      => array(
					'playlistId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'buttonLabel' => array(
						'type'    => 'string',
						'default' => '',
					),
					'visibility' => array(
						'type'    => 'string',
						'default' => '',
					),
					'useCover' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Render the save playlist block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public function render_save_playlist_block( $attributes ) {
		$playlist_id = isset( $attributes['playlistId'] ) ? (int) $attributes['playlistId'] : 0;
		
		// If no playlist ID is set, try to get the current post ID.
		if ( ! $playlist_id ) {
			$playlist_id = get_the_ID();
		}

		$args = array();

		if ( ! empty( $attributes['buttonLabel'] ) ) {
			$args['button_text'] = $attributes['buttonLabel'];
		}

		if ( ! empty( $attributes['visibility'] ) && in_array( $attributes['visibility'], array( 'public', 'private' ), true ) ) {
			$args['visibility'] = $attributes['visibility'];
		}

		if ( isset( $attributes['useCover'] ) ) {
			$args['use_cover'] = (bool) $attributes['useCover'];
		}

		ob_start();
		if ( function_exists( 'bspfy_render_save_button' ) ) {
			bspfy_render_save_button( $playlist_id, $args );
		}
		return ob_get_clean();
	}
}
