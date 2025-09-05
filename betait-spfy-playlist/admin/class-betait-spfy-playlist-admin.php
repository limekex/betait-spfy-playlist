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

		wp_enqueue_style( $this->betait_spfy_playlist, plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-admin.css', array(), $this->version, 'all' );
        wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', array(), '6.5.0', 'all' );

	}

	/**
 * Register the JavaScript for the admin area.
 *
 * @since    1.0.0
 */
public function enqueue_scripts() {
    $script_handle = $this->betait_spfy_playlist; // Ensure the same handle is used.

    wp_enqueue_script(
        $script_handle,
        plugin_dir_url( __FILE__ ) . 'js/betait-spfy-playlist-admin.js',
        array( 'jquery' ),
        $this->version,
        true // Load in the footer.
    );

    wp_localize_script(
        $script_handle, // Use the same handle as in wp_enqueue_script.
        'bspfyDebug',
        array(
            'debug' => get_option( 'bspfy_debug', false ),
            'client_id' => get_option('bspfy_client_id', ''), // Pass client_id to JS
            'ajaxurl' => admin_url( 'admin-ajax.php' ), // Add AJAX URL for WordPress.
        )
    );

    wp_enqueue_script('spotify-sdk', 'https://sdk.scdn.co/spotify-player.js', array(), null, true);
}


	/**
 * Add a custom admin menu page.
 *
 * @since    1.0.0
 */
public function add_admin_menu() {
    // Main menu for Spotify Playlists.
    add_menu_page(
        __( 'Spotify Playlists', 'betait-spfy-playlist' ), // Page title.
        __( 'Spfy Playlists', 'betait-spfy-playlist' ),    // Menu title.
        'manage_options',                                 // Capability.
        'betait-spfy-playlist',                           // Menu slug.
        array( $this, 'display_admin_page' ),             // Callback function.
        'dashicons-playlist-audio',                        // Icon.
		20
    );
	 // Add New submenu.
	 add_submenu_page(
        'betait-spfy-playlist',                           // Parent slug.
        __( 'Add New Playlist', 'betait-spfy-playlist' ), // Page title.
        __( 'Add New', 'betait-spfy-playlist' ),          // Menu title.
        'manage_options',                                 // Capability.
        'post-new.php?post_type=playlist',                // URL for Add New CPT.
        null                                              // No callback needed, uses default WordPress page.
    );

    // Genres submenu.
    add_submenu_page(
        'betait-spfy-playlist',                           // Parent slug.
        __( 'Genres', 'betait-spfy-playlist' ),           // Page title.
        __( 'Genres', 'betait-spfy-playlist' ),           // Menu title.
        'manage_options',                                 // Capability.
        'edit-tags.php?taxonomy=genre&post_type=playlist', // URL for Genres taxonomy.
        null                                              // No callback needed, uses default WordPress page.
    );

    // Submenu for Settings.
    add_submenu_page(
        'betait-spfy-playlist',                           // Parent slug.
        __( 'Settings', 'betait-spfy-playlist' ),         // Page title.
        __( 'Settings', 'betait-spfy-playlist' ),         // Menu title.
        'manage_options',                                 // Capability.
        'betait-spfy-playlist-settings',                 // Menu slug.
        array( $this, 'display_settings_page' )           // Callback function.
    );
}

/**
 * Display the custom admin page.
 *
 * @since    1.0.0
 */
public function display_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>' . __( 'Spotify Playlists', 'betait-spfy-playlist' ) . '</h1>';
    echo '<form method="post" action="">';
    echo '<input type="text" name="spotify_query" placeholder="' . __( 'Search for tracks...', 'betait-spfy-playlist' ) . '" class="bspfy-input">';
    echo '<button type="submit" class="bspfy-button">' . __( 'Search', 'betait-spfy-playlist' ) . '</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Display the settings page.
 *
 * @since    1.0.0
 */
public function display_settings_page() {
    if ( isset( $_POST['bspfy_save_settings'] ) ) {
        // Save settings.
        $client_id = sanitize_text_field( $_POST['bspfy_client_id'] );
        $client_secret = sanitize_text_field( $_POST['bspfy_client_secret'] );
        $debug_enabled = isset( $_POST['bspfy_debug'] ) ? 1 : 0;

        update_option( 'bspfy_client_id', $client_id );
        update_option( 'bspfy_client_secret', $client_secret );
        update_option( 'bspfy_debug', $debug_enabled );

        echo '<div class="updated notice"><p>' . __( 'Settings saved!', 'betait-spfy-playlist' ) . '</p></div>';
    }

    // Retrieve saved options.
    $client_id = get_option( 'bspfy_client_id', '' );
    $client_secret = get_option( 'bspfy_client_secret', '' );
    $debug_enabled = get_option( 'bspfy_debug', 0 );

    echo '<div class="wrap">';
    echo '<h1>' . __( 'Spotify API Settings', 'betait-spfy-playlist' ) . '</h1>';
    echo '<form method="post" action="">';

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="bspfy_client_id">' . __( 'Spotify Client ID', 'betait-spfy-playlist' ) . '</label></th>';
    echo '<td><input type="text" id="bspfy_client_id" name="bspfy_client_id" value="' . esc_attr( $client_id ) . '" class="bspfy-input" /></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="bspfy_client_secret">' . __( 'Spotify Client Secret', 'betait-spfy-playlist' ) . '</label></th>';
    echo '<td><input type="text" id="bspfy_client_secret" name="bspfy_client_secret" value="' . esc_attr( $client_secret ) . '" class="bspfy-input" /></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="bspfy_debug">' . __( 'Enable Debugging', 'betait-spfy-playlist' ) . '</label></th>';
    echo '<td><input type="checkbox" id="bspfy_debug" name="bspfy_debug" ' . checked( 1, $debug_enabled, false ) . ' /></td>';
    echo '</tr>';
    echo '</table>';

    echo '<p class="bspfy-instructions">';
    echo __( 'To obtain your Spotify API credentials, follow these steps:', 'betait-spfy-playlist' );
    echo '<ol>';
    echo '<li>' . __( 'Go to the Spotify Developer Dashboard: <a href="https://developer.spotify.com/dashboard/" target="_blank">https://developer.spotify.com/dashboard/</a>', 'betait-spfy-playlist' ) . '</li>';
    echo '<li>' . __( 'Log in or create a Spotify account.', 'betait-spfy-playlist' ) . '</li>';
    echo '<li>' . __( 'Click "Create an App" and fill in the required details.', 'betait-spfy-playlist' ) . '</li>';
    echo '<li>' . __( 'Once your app is created, navigate to the "Settings" tab to find your Client ID and Client Secret.', 'betait-spfy-playlist' ) . '</li>';
    echo '<li>' . __( 'Copy and paste the credentials into the fields above.', 'betait-spfy-playlist' ) . '</li>';
    echo '</ol>';
    echo '</p>';

    echo '<p><button type="submit" name="bspfy_save_settings" class="bspfy-button">' . __( 'Save Settings', 'betait-spfy-playlist' ) . '</button></p>';
    echo '</form>';
    echo '</div>';
}


}
