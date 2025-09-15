<?php
/**
 * Plugin deactivator for BeTA iT – Spotify Playlist.
 *
 * What happens on deactivation:
 * - Flush rewrite rules (harmless even if no custom rewrites are in use).
 * - Clear any scheduled cron events owned by this plugin (if they exist).
 * - Best-effort cleanup of short-lived transients (OAuth state/guest cache).
 *
 * What does NOT happen here:
 * - No options or tables are deleted. Place destructive cleanup in uninstall.php.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_Deactivator
 */
class Betait_Spfy_Playlist_Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_scheduled_events();
		self::clear_ephemeral_transients();

		// Flush rewrites to drop any rules that may have been added by this plugin.
		flush_rewrite_rules();
	}

	/**
	 * Unschedule cron events that this plugin may register.
	 *
	 * Safe to call even if the hooks were never scheduled.
	 *
	 * @return void
	 */
	private static function clear_scheduled_events() {
		$hooks = array(
			// Potential future jobs — keep names stable if you add them later:
			'bspfy_token_refresh',
			'bspfy_oauth_healthcheck',
			'bspfy_insights_rollup',
		);

		foreach ( $hooks as $hook ) {
			// Remove all scheduled occurrences of this hook.
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Delete short-lived transients that are safe to forget on deactivation.
	 *
	 * Note: These are best-effort; if a key does not exist, delete_transient() is harmless.
	 *
	 * @return void
	 */
	private static function clear_ephemeral_transients() {
		$transients = array(
			// Guest/token caches and OAuth handshake artifacts (names may vary):
			'bspfy_guest_access_cache',
			'bspfy_oauth_state',
			'bspfy_pkce_verifier',
		);

		foreach ( $transients as $key ) {
			delete_transient( $key );
		}
	}
}
