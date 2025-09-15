<?php
/**
 * Internationalization bootstrap for BeTA iT – Spotify Playlist.
 *
 * Loads the plugin text domain so translations are available.
 * It first attempts the global languages directory (for updates
 * delivered via translate.wordpress.org), then falls back to the
 * plugin's bundled /languages directory.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_i18n
 */
class Betait_Spfy_Playlist_i18n {

	/**
	 * Load the plugin text domain for translation.
	 *
	 * Hook this method on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain() {
		$domain = defined( 'BETAIT_SPFY_PLAYLIST_TEXTDOMAIN' )
			? BETAIT_SPFY_PLAYLIST_TEXTDOMAIN
			: 'betait-spfy-playlist';

		// Determine current locale (respects site/user settings).
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = apply_filters( 'plugin_locale', $locale, $domain );

		// 1) Try the global languages directory first (recommended).
		$global_mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
		load_textdomain( $domain, $global_mofile );

		// 2) Fallback to the plugin's own /languages directory.
		load_plugin_textdomain(
			$domain,
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}
