<?php
/**
 * Template Part: Sidebar – Now Playing Cover
 * Context:      Shown next to the track list; updated by JS to reflect the current track’s album cover.
 *
 * ❖ Overriding in a theme
 * Copy this file to:
 *   /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-sidebar-cover.php
 * (Parent theme also supported; plugin fallback used if none found.)
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/templates
 * @since     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a default/fallback cover image.
 * You can override via the `bspfy_default_cover_url` filter.
 */
$default_cover = '';
if ( defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ) {
	$default_cover = plugins_url( 'assets/default_cover.jpg', BETAIT_SPFY_PLAYLIST_FILE );
} else {
	// Fallback if the plugin main-file constant is not available for any reason.
	$default_cover = trailingslashit( plugin_dir_url( __FILE__ ) ) . '../assets/default_cover.jpg';
}

// Allow themes/plugins to override the default cover.
$default_cover = apply_filters( 'bspfy_default_cover_url', $default_cover );

// Final escape for output.
$default_cover_e = esc_url( $default_cover );
?>

<!-- Now Playing cover area. JS should update the <img> src + alt -->
<section id="bspfy-now-playing"
         class="bspfy-now-playing"
         aria-label="<?php esc_attr_e( 'Now playing', 'betait-spfy-playlist' ); ?>"
         aria-live="polite">
	<img id="bspfy-now-playing-cover"
	     src="<?php echo $default_cover_e; ?>"
	     alt="<?php esc_attr_e( 'Now playing cover', 'betait-spfy-playlist' ); ?>"
	     loading="lazy"
	     decoding="async" />

		 <?php
// Render "Save to Spotify" button if enabled.
if ( function_exists( 'bspfy_render_save_button' ) ) {
	bspfy_render_save_button();
}
?>
</section>
