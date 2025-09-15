<?php
/**
 * Template Part: Playlist Header
 * Context:      Used by the BSPFY playlist template to render the header area.
 *
 * ❖ Overriding in a theme
 * Copy this file to:
 *   /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-header.php
 * (Parent theme also supported; plugin fallback used if none found.)
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/templates
 * @since     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();

// Title (filterable)
$title = get_the_title( $post_id );
$title = apply_filters( 'bspfy_playlist_title', $title, $post_id );

// Description (stored in post meta, rendered as safe HTML)
$raw_description = get_post_meta( $post_id, '_playlist_description', true );
$desc_html       = '';
if ( ! empty( $raw_description ) ) {
	$desc_html = wpautop( wp_kses_post( $raw_description ) );
}
$desc_html = apply_filters( 'bspfy_playlist_description_html', $desc_html, $post_id );

// Header image (featured image) with sensible fallback alt
$thumb_html = '';
if ( has_post_thumbnail( $post_id ) ) {
	$thumb_id = get_post_thumbnail_id( $post_id );
	$alt      = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
	if ( '' === $alt ) {
		$alt = $title;
	}

	/**
	 * Filter the image size used in the header.
	 *
	 * @param string $size    Image size slug.
	 * @param int    $post_id Post ID.
	 */
	$size       = apply_filters( 'bspfy_header_image_size', 'large', $post_id );
	$thumb_html = get_the_post_thumbnail(
		$post_id,
		$size,
		array(
			'alt'   => esc_attr( $alt ),
			'class' => 'bspfy-header-thumb',
			'loading' => 'lazy',
			'decoding' => 'async',
		)
	);
}

// Optional: Genres list (taxonomy), easy to style or remove in overrides
$genres_html = '';
$genres_list = get_the_term_list( $post_id, 'genre', '', ', ' );
if ( $genres_list && ! is_wp_error( $genres_list ) ) {
	$genres_html = sprintf(
		'<div class="bspfy-header-genres"><span class="screen-reader-text">%s</span>%s</div>',
		esc_html__( 'Genres:', 'betait-spfy-playlist' ),
		$genres_list // already escaped by WP core.
	);
}

// Hooks for extensions
do_action( 'bspfy_before_header', $post_id );
?>

        <div class="bspfy-header-title">
			<h1 class="bspfy-title"><?php echo esc_html( $title ); ?></h1>
            <span class="bspfy-meta"><?php 
            // Optional taxonomy output.
			if ( $genres_html ) :
				echo $genres_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			endif;?>
        </div>
			
            <?php
			/**
			 * Fires before the playlist description is printed.
			 *
			 * @param int $post_id Post ID.
			 */
			do_action( 'bspfy_before_description', $post_id );

        if ( $desc_html ) :
            ?>
            <div class="bspfy-playlist-description bspfy-header-description">
                <?php echo $desc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>

			<?php
			/**
			 * Fires after the playlist description is printed.
			 *
			 * @param int $post_id Post ID.
			 */
			do_action( 'bspfy_after_description', $post_id );?> 

			
		

		<?php if ( $thumb_html ) : ?>
			<div class="bspfy-header-image" role="img" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>
	

<?php
do_action( 'bspfy_after_header', $post_id );
