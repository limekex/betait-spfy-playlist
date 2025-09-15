<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/public
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $betait_spfy_playlist    The ID of this plugin.
	 */
	private $betait_spfy_playlist;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $betait_spfy_playlist       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $betait_spfy_playlist, $version ) {

		$this->betait_spfy_playlist = $betait_spfy_playlist;
		$this->version = $version;
		
		// Hook to include our custom template.
		add_filter( 'template_include', array( $this, 'load_playlist_template' ) );

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Betait_Spfy_Playlist_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Betait_Spfy_Playlist_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->betait_spfy_playlist, plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-public.css', array(), $this->version, 'all' );
		wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', array(), '6.5.0', 'all' );
		wp_enqueue_style('bspfy-overlay',plugins_url('assets/css/bspfy-overlay.css', array(), $this->version, 'all' );
	
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

    // Spotify SDK (kan fint lastes i footer)
    wp_enqueue_script(
        'spotify-sdk',
        'https://sdk.scdn.co/spotify-player.js',
        array(),
        null,
        true
    );

    // Public JS for plugin (anbefaler footer=true for å ikke blokkere render)
    wp_enqueue_script(
        $this->betait_spfy_playlist,
        plugin_dir_url(__FILE__) . 'js/betait-spfy-playlist-public.js',
        array('jquery'),
        $this->version,
        true
    );

	wp_enqueue_script(
        'bspfy-overlay',
        plugins_url('assets/js/bspfy-overlay.js', BETAIT_SPFY_PLAYLIST_FILE),
        [],
       	$this->version,
        true
    );

    // Gjør innstillinger tilgjengelig i frontend (IKKE legg hemmeligheter her)
    wp_localize_script(
        $this->betait_spfy_playlist,
        'bspfyPublic',
        array(
            // Player
            'player_name'     => get_option('bspfy_player_name', 'BeTA iT Web Player'),
            'default_volume'  => (float) get_option('bspfy_default_volume', 0.5), // 0–1
            'player_theme'    => get_option('bspfy_player_theme', 'default'),

            // Playlist
            'playlist_theme'  => get_option('bspfy_playlist_theme', 'default'),

            // Øvrig nyttig konfig (ufarlig på frontend)
            'debug'           => (bool) get_option('bspfy_debug', 0),
            'rest_base'       => esc_url_raw( rest_url('bspfy/v1/') ),

            // Feature flags (kan også styres via filter)
            'require_premium' => (bool) apply_filters('bspfy_require_premium', (bool) get_option('bspfy_require_premium', 1)),
        	'strict_samesite' => (bool) apply_filters('bspfy_strict_samesite', (bool) get_option('bspfy_strict_samesite', 0)),
    )
    );
}

	


		/**
		 * Load the custom template for the playlist post type.
		 *
		 * @param string $template The path to the template to be loaded.
		 * @return string The modified template path.
		 */
		public function load_playlist_template( $template ) {
			if ( is_singular( 'playlist' ) ) {
				$custom_template = plugin_dir_path( __FILE__ ) . '../templates/playlist-template.php';
				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
			return $template;
		}

}
