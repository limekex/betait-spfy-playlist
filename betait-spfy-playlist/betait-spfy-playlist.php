<?php
/**
 * Plugin Name:       BeTA iT – Spotify Playlist
 * Plugin URI:        https://betait.no/spfy
 * Description:       WordPress integration with the Spotify Web API & Web Playback SDK. Create/save playlists, search tracks, and play in-browser (Premium required for SDK playback).
 * Version:           2.5.14
 * Author:            BeTA iT
 * Author URI:        https://betait.no
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       betait-spfy-playlist
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Betait_Spfy_Playlist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

/**
 * Core plugin constants.
 * These are defined early so they can be used across includes and during activation.
 */
if ( ! defined( 'BETAIT_SPFY_PLAYLIST_FILE' ) ) {
	define( 'BETAIT_SPFY_PLAYLIST_FILE', __FILE__ );
}
if ( ! defined( 'BETAIT_SPFY_PLAYLIST_DIR' ) ) {
	define( 'BETAIT_SPFY_PLAYLIST_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'BETAIT_SPFY_PLAYLIST_URL' ) ) {
	define( 'BETAIT_SPFY_PLAYLIST_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ) {
	define( 'BETAIT_SPFY_PLAYLIST_VERSION', '2.0.0' ); // Keep in sync with header.
}

/**
 * Minimum runtime requirements.
 */
if ( ! defined( 'BETAIT_SPFY_MIN_WP' ) ) {
	define( 'BETAIT_SPFY_MIN_WP', '6.0' );
}
if ( ! defined( 'BETAIT_SPFY_MIN_PHP' ) ) {
	define( 'BETAIT_SPFY_MIN_PHP', '7.4' );
}

/**
 * Check if the environment meets plugin requirements.
 *
 * @return bool
 */
function betait_spfy_requirements_met() {
	global $wp_version;
	return version_compare( PHP_VERSION, BETAIT_SPFY_MIN_PHP, '>=' )
		&& version_compare( $wp_version, BETAIT_SPFY_MIN_WP, '>=' );
}

/**
 * Admin notice when requirements are not met.
 *
 * @return void
 */
function betait_spfy_requirements_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: PHP version, 2: WP version */
				'BeTA iT – Spotify Playlist requires PHP %1$s+ and WordPress %2$s+. Please upgrade your environment.',
				BETAIT_SPFY_MIN_PHP,
				BETAIT_SPFY_MIN_WP
			)
		)
	);
}

/**
 * Deactivate the plugin if requirements are not met during activation.
 *
 * @return void
 */
function betait_spfy_on_activation_check() {
	if ( ! betait_spfy_requirements_met() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			sprintf(
				/* translators: 1: PHP version, 2: WP version */
				'BeTA iT – Spotify Playlist requires PHP %1$s+ and WordPress %2$s+. The plugin has been deactivated.',
				BETAIT_SPFY_MIN_PHP,
				BETAIT_SPFY_MIN_WP
			),
			'Plugin activation halted',
			array( 'back_link' => true )
		);
	}
}

/**
 * Plugin activation callback.
 * Invokes the activator class to handle DB/table setup and initial options.
 *
 * @return void
 */
function activate_betait_spfy_playlist() {
	betait_spfy_on_activation_check();

	require_once BETAIT_SPFY_PLAYLIST_DIR . 'includes/class-betait-spfy-playlist-activator.php';
	if ( class_exists( 'Betait_Spfy_Playlist_Activator' ) ) {
		Betait_Spfy_Playlist_Activator::activate();
	}
}

/**
 * Plugin deactivation callback.
 * Invokes the deactivator class to clean up scheduled events and transient state.
 *
 * @return void
 */
function deactivate_betait_spfy_playlist() {
	require_once BETAIT_SPFY_PLAYLIST_DIR . 'includes/class-betait-spfy-playlist-deactivator.php';
	if ( class_exists( 'Betait_Spfy_Playlist_Deactivator' ) ) {
		Betait_Spfy_Playlist_Deactivator::deactivate();
	}
}

register_activation_hook( __FILE__, 'activate_betait_spfy_playlist' );
register_deactivation_hook( __FILE__, 'deactivate_betait_spfy_playlist' );

/**
 * Load text domain for internationalization.
 *
 * @return void
 */
function betait_spfy_load_textdomain() {
	load_plugin_textdomain(
		'betait-spfy-playlist',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'betait_spfy_load_textdomain' );

/**
 * If requirements are not met at runtime (e.g. environment changed), show an admin notice and bail.
 */
if ( ! betait_spfy_requirements_met() ) {
	if ( is_admin() ) {
		add_action( 'admin_notices', 'betait_spfy_requirements_notice' );
	}
	return;
}

 // Always keep Unicode characters unescaped in JSON produced by WordPress.
 add_filter( 'wp_json_encode_options', function( $options ) {
     if ( ! defined( 'JSON_UNESCAPED_UNICODE' ) ) {
         return $options; // very old PHP fallback
     }
     return $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
}, 99 );


/**
 * Bootstrap the core plugin class.
 * The main class wires up i18n, admin hooks, and public hooks via its loader.
 */
require_once BETAIT_SPFY_PLAYLIST_DIR . 'includes/class-betait-spfy-playlist.php';

/**
 * Run the plugin.
 *
 * @return void
 */
function run_betait_spfy_playlist() {
	$plugin = new Betait_Spfy_Playlist();
	$plugin->run();
}
run_betait_spfy_playlist();
