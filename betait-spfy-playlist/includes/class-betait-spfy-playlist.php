<?php
/**
 * Core plugin class for BeTA iT – Spotify Playlist.
 *
 * Responsibilities:
 * - Load dependencies (loader, i18n, admin/public, CPT, AJAX, OAuth, API handler).
 * - Set up internationalization.
 * - Register admin and public hooks via the Loader.
 *
 * Notes:
 * - Pretty rewrite endpoints previously used for OAuth have been dropped.
 *   The plugin now relies on REST routes under `/wp-json/bspfy/v1/*`.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist
 */
class Betait_Spfy_Playlist {

	/**
	 * Loader that maintains and registers all hooks for the plugin.
	 *
	 * @var Betait_Spfy_Playlist_Loader
	 */
	protected $loader;

	/**
	 * Unique identifier (slug) for this plugin.
	 *
	 * @var string
	 */
	protected $betait_spfy_playlist;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Optionally keep a reference to the OAuth helper.
	 *
	 * @var Betait_Spfy_Playlist_OAuth|null
	 */
	protected $oauth = null;

	/**
	 * Constructor.
	 *
	 * Sets the plugin slug, resolves version, loads dependencies,
	 * configures i18n, and wires up admin/public hooks.
	 */
	public function __construct() {
		$this->version              = defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : '1.0.0';
		$this->betait_spfy_playlist = 'betait-spfy-playlist';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load required dependencies.
	 *
	 * Files loaded:
	 * - Loader (orchestrates add_action/add_filter calls)
	 * - i18n (text domain loading)
	 * - Admin/CPT/AJAX/OAuth/API handler
	 * - Public
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$base = plugin_dir_path( dirname( __FILE__ ) );

		// Loader & i18n.
		require_once $base . 'includes/class-betait-spfy-playlist-loader.php';
		require_once $base . 'includes/class-betait-spfy-playlist-i18n.php';

		// Core/feature classes.
		require_once $base . 'admin/class-betait-spfy-playlist-admin.php';
		require_once $base . 'admin/class-betait-spfy-playlist-api-handler.php';
		require_once $base . 'admin/class-betait-spfy-playlist-cpt.php';
		require_once $base . 'admin/class-betait-spfy-playlist-ajax.php';
		require_once $base . 'includes/class-betait-spfy-playlist-oauth.php';
		require_once $base . 'includes/class-betait-spfy-playlist-save-handler.php';
		require_once $base . 'includes/template-functions.php';

		// Public frontend.
		require_once $base . 'public/class-betait-spfy-playlist-public.php';

		$this->loader = new Betait_Spfy_Playlist_Loader();
	}

	/**
	 * Configure internationalization.
	 *
	 * @return void
	 */
	private function set_locale() {
		$plugin_i18n = new Betait_Spfy_Playlist_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register hooks for admin area.
	 *
	 * - Enqueue admin styles/scripts
	 * - Admin menu
	 * - CPT & taxonomy
	 * - Meta boxes and saving
	 * - AJAX handlers
	 * - OAuth bootstrap (REST lives elsewhere)
	 *
	 * @return void
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Betait_Spfy_Playlist_Admin( $this->get_betait_spfy_playlist(), $this->get_version() );
		$plugin_cpt   = new Betait_Spfy_Playlist_CPT();
		$plugin_ajax  = new Betait_Spfy_Playlist_Ajax();
		$this->oauth  = new Betait_Spfy_Playlist_OAuth();
		$plugin_save  = new Betait_Spfy_Playlist_Save_Handler();

		// Assets.
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_profile_inline_js' );

		// Admin UI.
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_admin_menu' );

		// CPT & taxonomy.
		$this->loader->add_action( 'init', $plugin_cpt, 'register_cpt_and_taxonomy' );

		// Meta boxes & save handlers.
		$this->loader->add_action( 'add_meta_boxes', $plugin_cpt, 'add_meta_boxes' );
		$this->loader->add_action( 'save_post', $plugin_cpt, 'save_playlist_meta' );

		// Profile actions / disconnect notice.
		$this->loader->add_action( 'show_user_profile', $plugin_admin, 'render_spotify_auth_profile_row' );
		$this->loader->add_action( 'edit_user_profile', $plugin_admin, 'render_spotify_auth_profile_row' );
		$this->loader->add_action( 'admin_post_bspfy_disconnect_spotify', $plugin_admin, 'handle_disconnect_spotify' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'maybe_render_disconnect_notice' );

		// AJAX endpoints (admin-ajax.php).
		$this->loader->add_action( 'wp_ajax_search_spotify_tracks', $plugin_ajax, 'search_spotify_tracks' );
		$this->loader->add_action( 'wp_ajax_save_spotify_access_token', $plugin_ajax, 'save_spotify_access_token' );
		$this->loader->add_action( 'wp_ajax_save_spotify_user_name', $plugin_ajax, 'save_spotify_user_name' );
	}

	/**
	 * Register hooks for public-facing side.
	 *
	 * @return void
	 */
	private function define_public_hooks() {
		$plugin_public = new Betait_Spfy_Playlist_Public( $this->get_betait_spfy_playlist(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		// Optional: playlist single template override.
		$this->loader->add_filter( 'template_include', $plugin_public, 'load_playlist_template' );
	}

	/**
	 * Execute all registered hooks with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		$this->loader->run();

		// Legacy rewrite support has been removed in favor of REST routes.
		// If you ever re-introduce a pretty endpoint, register rewrites on `init`
		// here via the loader and keep flush in the Activator only.
	}

	/**
	 * Get the plugin slug.
	 *
	 * @return string
	 */
	public function get_betait_spfy_playlist() {
		return $this->betait_spfy_playlist;
	}

	/**
	 * Get the Loader instance.
	 *
	 * @return Betait_Spfy_Playlist_Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Get current plugin version.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}
