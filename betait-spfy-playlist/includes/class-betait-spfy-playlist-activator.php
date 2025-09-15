<?php
/**
 * Plugin activator for BeTA iT – Spotify Playlist.
 *
 * Responsibilities on activation:
 * - Seed default options (only if missing).
 * - Persist the installed plugin version for future migrations.
 * - Flush rewrite rules (assumes rewrites are registered on `init` at runtime).
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_Activator
 */
class Betait_Spfy_Playlist_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * - Seeds defaults (idempotent).
	 * - Stores current plugin version.
	 * - Flushes rewrite rules (your plugin must add any custom rewrites on `init`).
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_default_options();
		self::store_installed_version();

		/**
		 * Best practice: rewrite rules should be registered on `init` in runtime.
		 * Flushing here will then persist those rules immediately after activation.
		 */
		flush_rewrite_rules();
	}

	/**
	 * Add default options the first time (no overwrite if already set).
	 *
	 * Debug toggle is included here and defaults to OFF.
	 *
	 * @return void
	 */
	private static function ensure_default_options() {
		// Admin UI defaults.
		add_option( 'bspfy_player_name',     'BeTA iT Web Player' ); // Web Playback SDK device name.
		add_option( 'bspfy_default_volume',  0.5 );                  // 0..1
		add_option( 'bspfy_player_theme',    'default' );
		add_option( 'bspfy_playlist_theme',  'default' );

		// Diagnostics / logging.
		add_option( 'bspfy_debug',           0 );                    // OFF by default.

		// Security & UX toggles.
		add_option( 'bspfy_require_premium', 1 );                    // Premium required by default.
		add_option( 'bspfy_strict_samesite', 0 );                    // Use Lax for OAuth redirects by default.

		// Spotify API credentials (empty by default).
		add_option( 'bspfy_client_id',       '' );
		add_option( 'bspfy_client_secret',   '' );
	}

	/**
	 * Persist the installed plugin version (useful for future db/schema migrations).
	 *
	 * Uses the constant if available; otherwise falls back to '0.0.0'.
	 *
	 * @return void
	 */
	private static function store_installed_version() {
		$version = defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : '0.0.0';

		// Keep a "first installed" marker (write once).
		if ( get_option( 'bspfy_version_installed' ) === false ) {
			add_option( 'bspfy_version_installed', $version );
		}

		// Always update "current" version on activation.
		update_option( 'bspfy_version_current', $version );
	}
}
