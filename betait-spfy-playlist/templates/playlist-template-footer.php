<?php
/**
 * Template Part: Playlist – Footer / Attribution
 *
 * ❖ Overriding in a theme
 * Copy this file to:
 *   /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-footer.php
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
 * Resolve Spotify logo URL (filterable).
 */
$logo_url = '';
if ( defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ) {
	$logo_url = plugins_url( 'assets/Spotify_Full_Logo_RGB_Green.png', BETAIT_SPFY_PLAYLIST_FILE );
} else {
	// Fallback if main-file constant is not available for any reason.
	$logo_url = trailingslashit( plugin_dir_url( __FILE__ ) ) . '../assets/Spotify_Full_Logo_RGB_Green.png';
}

$logo_url = apply_filters( 'bspfy_spotify_logo_url', $logo_url );

/**
 * Attribution text (filterable).
 */
$attrib_text = apply_filters(
	'bspfy_attribution_text',
	__( 'BeTA Spfy Playlist is powered by', 'betait-spfy-playlist' )
);

// Final escapes for output.
$logo_url_e  = esc_url( $logo_url );
$attrib_text_e = esc_html( $attrib_text );
$logo_alt_e  = esc_attr__( 'Spotify', 'betait-spfy-playlist' );
?>

<div class="bspfy-spotify-attribution" role="contentinfo" aria-label="<?php esc_attr_e( 'Attribution', 'betait-spfy-playlist' ); ?>">
	<span><?php echo $attrib_text_e; ?></span>
	<img class="bspfy-spotify-logo"
	     src="<?php echo $logo_url_e; ?>"
	     alt="<?php echo $logo_alt_e; ?>"
	     loading="lazy"
	     decoding="async" />
</div>
