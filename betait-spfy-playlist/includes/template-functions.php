<?php
/**
 * Template helper functions for BeTA iT – Spotify Playlist.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "Save to Spotify" button.
 *
 * @param int|null $post_id Optional playlist post ID. Defaults to current post.
 * @param array    $args    Optional arguments.
 * @return void
 */
function bspfy_render_save_button( $post_id = null, $args = array() ) {
	// Check if feature is enabled.
	if ( (int) get_option( 'bspfy_save_playlist_enabled', 1 ) !== 1 ) {
		return;
	}

	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	$post = get_post( $post_id );
	if ( ! $post || 'playlist' !== $post->post_type ) {
		return;
	}

	// Default arguments.
	$defaults = array(
		'visibility'  => get_option( 'bspfy_save_playlist_default_visibility', 'public' ),
		'use_cover'   => (int) get_option( 'bspfy_save_playlist_use_cover', 1 ) === 1,
		'button_text' => get_option( 'bspfy_save_playlist_button_label', __( 'Save to Spotify', 'betait-spfy-playlist' ) ),
		'show_icon'   => true,
		'class'       => '',
	);

	$args = wp_parse_args( $args, $defaults );

	// Generate title and description from templates.
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

	// Sanitize for data attributes.
	$title       = esc_attr( $title );
	$description = esc_attr( $description );
	$visibility  = esc_attr( $args['visibility'] );
	$use_cover   = $args['use_cover'] ? 'true' : 'false';
	$button_text = esc_html( $args['button_text'] );
	$class       = esc_attr( $args['class'] );

	?>
	<div class="bspfy-save-playlist-container">
		<button 
			type="button"
			class="bspfy-save-playlist-btn <?php echo $class; ?>"
			data-post-id="<?php echo (int) $post_id; ?>"
			data-title="<?php echo $title; ?>"
			data-description="<?php echo $description; ?>"
			data-visibility="<?php echo $visibility; ?>"
			data-use-cover="<?php echo $use_cover; ?>"
			aria-label="<?php echo esc_attr( $button_text ); ?>"
		>
			<?php if ( $args['show_icon'] ) : ?>
				<svg class="bspfy-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
					<path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
				</svg>
			<?php endif; ?>
			<span><?php echo $button_text; ?></span>
		</button>
	</div>
	<?php
}

/**
 * Shortcode for rendering the save button.
 *
 * Usage: [bspfy_save_playlist id="123" label="Save to Spotify"]
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function bspfy_save_playlist_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'         => get_the_ID(),
			'label'      => '',
			'visibility' => '',
			'use_cover'  => '',
		),
		$atts,
		'bspfy_save_playlist'
	);

	$args = array();

	if ( ! empty( $atts['label'] ) ) {
		$args['button_text'] = $atts['label'];
	}

	if ( ! empty( $atts['visibility'] ) && in_array( $atts['visibility'], array( 'public', 'private' ), true ) ) {
		$args['visibility'] = $atts['visibility'];
	}

	if ( '' !== $atts['use_cover'] ) {
		$args['use_cover'] = (bool) $atts['use_cover'];
	}

	ob_start();
	bspfy_render_save_button( (int) $atts['id'], $args );
	return ob_get_clean();
}
add_shortcode( 'bspfy_save_playlist', 'bspfy_save_playlist_shortcode' );
