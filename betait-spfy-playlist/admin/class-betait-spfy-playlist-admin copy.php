<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/admin
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_Admin {

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
	 * @param      string    $betait_spfy_playlist       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $betait_spfy_playlist, $version ) {

		$this->betait_spfy_playlist = $betait_spfy_playlist;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the admin area.
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

		wp_enqueue_style( $this->betait_spfy_playlist, plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

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

		wp_enqueue_script( $this->betait_spfy_playlist, plugin_dir_url( __FILE__ ) . 'js/betait-spfy-playlist-admin.js', array( 'jquery' ), $this->version, false );

	}

}
