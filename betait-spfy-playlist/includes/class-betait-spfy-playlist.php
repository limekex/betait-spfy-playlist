<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Betait_Spfy_Playlist_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $betait_spfy_playlist    The string used to uniquely identify this plugin.
	 */
	protected $betait_spfy_playlist;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ) {
			$this->version = BETAIT_SPFY_PLAYLIST_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->betait_spfy_playlist = 'betait-spfy-playlist';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Betait_Spfy_Playlist_Loader. Orchestrates the hooks of the plugin.
	 * - Betait_Spfy_Playlist_i18n. Defines internationalization functionality.
	 * - Betait_Spfy_Playlist_Admin. Defines all hooks for the admin area.
	 * - Betait_Spfy_Playlist_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-betait-spfy-playlist-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-betait-spfy-playlist-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-betait-spfy-playlist-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-betait-spfy-playlist-api-handler.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-betait-spfy-playlist-cpt.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-betait-spfy-playlist-ajax.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-betait-spfy-playlist-oauth.php';



		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-betait-spfy-playlist-public.php';

		$this->loader = new Betait_Spfy_Playlist_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Betait_Spfy_Playlist_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Betait_Spfy_Playlist_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}
 /**
     * Register custom query vars and template redirects.
     *
     * @since 1.0.0
     */
    public function register_custom_hooks() {

        // Add query var for handling the custom endpoint.
        add_filter('query_vars', function ($vars) {
            $vars[] = 'spotify_auth_redirect';
            return $vars;
        });

        // Handle custom endpoint template rendering.
        add_action('template_redirect', function () {
            if (get_query_var('spotify_auth_redirect') == 1) {
                include plugin_dir_path(__FILE__) . '../templates/spotify-auth-redirect.php';
                exit;
            }
        });
    }
	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Betait_Spfy_Playlist_Admin( $this->get_betait_spfy_playlist(), $this->get_version() );
		$plugin_cpt = new Betait_Spfy_Playlist_CPT();
		$plugin_ajax = new Betait_Spfy_Playlist_Ajax();
		//$plugin_oauth = new Betait_Spfy_OAuth();
		$this->oauth = new Betait_Spfy_Playlist_OAuth();

	
		// Enqueue admin styles and scripts.
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
	
		// Add admin menu and page.
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_admin_menu' );
	
		// Register Custom Post Type and Taxonomy.
		$this->loader->add_action( 'init', $plugin_cpt, 'register_cpt_and_taxonomy' );
	
		// Add metaboxes for Playlists.
		$this->loader->add_action( 'add_meta_boxes', $plugin_cpt, 'add_meta_boxes' );
	
		// Save playlist meta data.
		$this->loader->add_action( 'save_post', $plugin_cpt, 'save_playlist_meta' );
	}
	
	

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Betait_Spfy_Playlist_Public( $this->get_betait_spfy_playlist(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
		$this->register_custom_hooks();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_betait_spfy_playlist() {
		return $this->betait_spfy_playlist;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Betait_Spfy_Playlist_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
