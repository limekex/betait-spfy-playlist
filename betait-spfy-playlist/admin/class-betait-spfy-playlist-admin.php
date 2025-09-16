<?php
/**
 * Admin-specific functionality for BeTA iT – Spotify Playlist.
 *
 * Handles:
 * - Admin menus & settings UI
 * - User profile section for Spotify connection
 * - Admin asset enqueueing
 * - Disconnect handler and success notice
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/admin
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin side controller.
 */
class Betait_Spfy_Playlist_Admin {

	/**
	 * Plugin handle/slug used for asset handles.
	 *
	 * @var string
	 */
	private $betait_spfy_playlist;

	/**
	 * Plugin version (fallback if constant is not defined).
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $betait_spfy_playlist Plugin handle/slug.
	 * @param string $version              Plugin version.
	 */
	public function __construct( $betait_spfy_playlist, $version ) {
		$this->betait_spfy_playlist = $betait_spfy_playlist;
		$this->version              = $version;

		// User profile (view/edit) integration.
		add_action( 'show_user_profile', array( $this, 'render_spotify_auth_profile_row' ) );
		add_action( 'edit_user_profile', array( $this, 'render_spotify_auth_profile_row' ) );

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_profile_inline_js' ), 15 );

		// Menu & pages.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Disconnect POST handler + notice.
		add_action( 'admin_post_bspfy_disconnect_spotify', array( $this, 'handle_disconnect_spotify' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_disconnect_notice' ) );

		// Debugging and status.
		add_action( 'admin_init', [ $this, 'register_tools_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_run_unicode_normalizer' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_unicode_notice' ] );
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		$ver = defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ? time() : ( defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : $this->version );

		wp_enqueue_style(
			$this->betait_spfy_playlist,
			plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-admin.css',
			array(),
			$ver,
			'all'
		);
	
		wp_enqueue_style(
        'bspfy-overlay',
        plugins_url('assets/css/bspfy-overlay.css', BETAIT_SPFY_PLAYLIST_FILE),
        [],
        $ver
    );

		// Avoid double-loading Font Awesome if a theme/admin already enqueues it.
		if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
			wp_enqueue_style(
				'font-awesome',
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
				array(),
				'6.5.0',
				'all'
			);
		}
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$ver    = defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ? time() : ( defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : $this->version );
		$handle = $this->betait_spfy_playlist;

		wp_enqueue_script(
			$handle,
			plugin_dir_url( __FILE__ ) . 'js/betait-spfy-playlist-admin.js',
			array( 'jquery' ),
			$ver,
			true
		);

		 wp_enqueue_script(
        'bspfy-overlay',
        plugins_url('assets/js/bspfy-overlay.js', BETAIT_SPFY_PLAYLIST_FILE),
        [],
        $ver,
        true
    );

		// Safe data for admin JS (never expose client secret here).
		wp_localize_script(
			$handle,
			'bspfyDebug',
			array(
				'debug'        => (bool) get_option( 'bspfy_debug', false ),
				'client_id'    => get_option( 'bspfy_client_id', '' ),
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
				'rest_root'    => esc_url_raw( rest_url() ),
				'ajax_nonce'    => wp_create_nonce( 'bspfy_ajax' ),
				'player_name'  => get_option( 'bspfy_player_name', 'BeTA iT Web Player' ),
				'default_volume' => (float) get_option( 'bspfy_default_volume', 0.5 ), // 0..1
				'require_premium' => (int) get_option( 'bspfy_require_premium', 1 ),
			)
		);

		// Load Spotify SDK only on edit/new screens for CPT=playlist.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->base, array( 'post', 'post-new' ), true ) && 'playlist' === $screen->post_type ) {
			wp_enqueue_script( 'spotify-sdk', 'https://sdk.scdn.co/spotify-player.js', array(), null, true );
		}
	}

	/**
	 * Register admin menu and submenus.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page(
			__( 'Spotify Playlists', 'betait-spfy-playlist' ),
			__( 'Spfy Playlists', 'betait-spfy-playlist' ),
			'manage_options',
			'betait-spfy-playlist',
			array( $this, 'display_admin_page' ),
			'dashicons-playlist-audio',
			20
		);

		add_submenu_page(
			'betait-spfy-playlist',
			__( 'Add New Playlist', 'betait-spfy-playlist' ),
			__( 'Add New', 'betait-spfy-playlist' ),
			'manage_options',
			'post-new.php?post_type=playlist',
			null
		);

		add_submenu_page(
			'betait-spfy-playlist',
			__( 'Genres', 'betait-spfy-playlist' ),
			__( 'Genres', 'betait-spfy-playlist' ),
			'manage_options',
			'edit-tags.php?taxonomy=genre&post_type=playlist',
			null
		);

		add_submenu_page(
			'betait-spfy-playlist',
			__( 'Settings', 'betait-spfy-playlist' ),
			__( 'Settings', 'betait-spfy-playlist' ),
			'manage_options',
			'betait-spfy-playlist-settings',
			array( $this, 'display_settings_page' )
		);
	}

	/**
	 * Simple landing page under the main admin menu.
	 *
	 * @return void
	 */
	public function display_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Spotify Playlists', 'betait-spfy-playlist' ) . '</h1>';
		echo '<form method="post" action="">';
		echo '<input type="text" name="spotify_query" placeholder="' . esc_attr__( 'Search for tracks...', 'betait-spfy-playlist' ) . '" class="bspfy-input" />';
		echo ' <button type="submit" class="bspfy-button">' . esc_html__( 'Search', 'betait-spfy-playlist' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Settings page (player options, themes, debug, security).
	 *
	 * @return void
	 */
	public function display_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save.
		if ( isset( $_POST['bspfy_save_settings'] ) && check_admin_referer( 'bspfy_save_settings_nonce', 'bspfy_save_settings_nonce_field' ) ) {
			// API credentials.
			update_option( 'bspfy_client_id', sanitize_text_field( $_POST['bspfy_client_id'] ?? '' ) );
			update_option( 'bspfy_client_secret', sanitize_text_field( $_POST['bspfy_client_secret'] ?? '' ) );
			update_option( 'bspfy_debug', isset( $_POST['bspfy_debug'] ) ? 1 : 0 );

			// Player.
			update_option( 'bspfy_player_name', sanitize_text_field( $_POST['bspfy_player_name'] ?? 'BeTA iT Web Player' ) );
			$vol_percent = isset( $_POST['bspfy_default_volume'] ) ? floatval( $_POST['bspfy_default_volume'] ) : 50;
			$vol_percent = max( 0, min( 100, $vol_percent ) );
			update_option( 'bspfy_default_volume', $vol_percent / 100.0 );

			$player_theme = sanitize_key( $_POST['bspfy_player_theme'] ?? 'default' );
			update_option( 'bspfy_player_theme', $player_theme );

			// Playlist theme: card | list.
			$playlist_theme = sanitize_key( $_POST['bspfy_playlist_theme'] ?? 'card' );
			update_option( 'bspfy_playlist_theme', in_array( $playlist_theme, array( 'card', 'list' ), true ) ? $playlist_theme : 'card' );

			// Security & cookies.
			update_option( 'bspfy_require_premium', isset( $_POST['bspfy_require_premium'] ) ? 1 : 0 );
			update_option( 'bspfy_strict_samesite', isset( $_POST['bspfy_strict_samesite'] ) ? 1 : 0 );

			// Tools & Debug
			update_option('bspfy_enable_unicode_tools',isset($_POST['bspfy_enable_unicode_tools']) ? 1 : 0);

			echo '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Settings saved!', 'betait-spfy-playlist' ) . '</p></div>';
		}

		// Load current settings.
		$client_id       = get_option( 'bspfy_client_id', '' );
		$client_secret   = get_option( 'bspfy_client_secret', '' );
		$debug_enabled   = (int) get_option( 'bspfy_debug', 0 );
		$player_name     = get_option( 'bspfy_player_name', 'BeTA iT Web Player' );
		$vol_0to1        = (float) get_option( 'bspfy_default_volume', 0.5 );
		$vol_percent     = (int) round( $vol_0to1 * 100 );
		$player_theme    = get_option( 'bspfy_player_theme', 'default' );
		$playlist_theme  = get_option( 'bspfy_playlist_theme', 'card' );
		$require_premium = (int) get_option( 'bspfy_require_premium', 1 );
		$strict_samesite = (int) get_option( 'bspfy_strict_samesite', 0 );
		$redirect_uri    = esc_url( home_url( '/wp-json/bspfy/v1/oauth/callback' ) );

		$player_themes = apply_filters(
			'bspfy_player_themes',
			array(
				'default' => __( 'Default', 'betait-spfy-playlist' ),
				'dock'    => __( 'Dock', 'betait-spfy-playlist' ),
			)
		);

		$playlist_themes = apply_filters(
			'bspfy_playlist_themes',
			array(
				'card' => __( 'Card (default)', 'betait-spfy-playlist' ),
				'list' => __( 'List (mobile style)', 'betait-spfy-playlist' ),
			)
		);

		echo '<div class="wrap bspfy-wrap">';
		echo '<h1>' . esc_html__( 'BSPFY Settings', 'betait-spfy-playlist' ) . '</h1>';

		// Tabs.
		echo '<div class="bspfy-tabs">';
		echo '  <button type="button" class="bspfy-tab is-active" data-tab="general">' . esc_html__( 'General settings', 'betait-spfy-playlist' ) . '</button>';
		echo '  <button type="button" class="bspfy-tab" data-tab="api">' . esc_html__( 'Spotify API', 'betait-spfy-playlist' ) . '</button>';
		echo '  <button type="button" class="bspfy-tab" data-tab="tools">' . esc_html__( 'Tools & Debug', 'betait-spfy-playlist' ) . '</button>';
		echo '</div>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'bspfy_save_settings_nonce', 'bspfy_save_settings_nonce_field' );

		/* ----- TAB: GENERAL ----- */
		echo '<div class="bspfy-tabpanel is-active" id="bspfy-tab-general" role="region" aria-label="' . esc_attr__( 'General settings', 'betait-spfy-playlist' ) . '">';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'Player', 'betait-spfy-playlist' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_player_name">' . esc_html__( 'Player name (device name)', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <input type="text" id="bspfy_player_name" name="bspfy_player_name" class="regular-text" value="' . esc_attr( $player_name ) . '" />';
		echo '    <p class="description">' . esc_html__( 'Shown as the device name in Spotify when using Web Playback SDK.', 'betait-spfy-playlist' ) . '</p>';
		echo '  </td>';
		echo '</tr>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_default_volume_range">' . esc_html__( 'Default volume', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <div class="bspfy-volume-field">';
		echo '      <input type="range" id="bspfy_default_volume_range" min="0" max="100" step="1" value="' . esc_attr( $vol_percent ) . '" />';
		echo '      <input type="number" id="bspfy_default_volume" name="bspfy_default_volume" min="0" max="100" step="1" value="' . esc_attr( $vol_percent ) . '" class="small-text" />';
		echo '      <span>%</span>';
		echo '    </div>';
		echo '    <p class="description">' . esc_html__( 'Applied on first SDK init (users can change it later).', 'betait-spfy-playlist' ) . '</p>';
		echo '  </td>';
		echo '</tr>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_player_theme">' . esc_html__( 'Player theme', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <select id="bspfy_player_theme" name="bspfy_player_theme">';
		foreach ( $player_themes as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $player_theme, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '    </select>';
		echo '    <p class="description">' . esc_html__( 'Choose a player layout.', 'betait-spfy-playlist' ) . '</p>';
		echo '  </td>';
		echo '</tr>';

		echo '</tbody></table>';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'Playlist', 'betait-spfy-playlist' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_playlist_theme">' . esc_html__( 'Playlist theme', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <select id="bspfy_playlist_theme" name="bspfy_playlist_theme">';
		foreach ( $playlist_themes as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $playlist_theme, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '    </select>';
		echo '    <p class="description">' . esc_html__( 'Choose a playlist layout (card or Spotify-like list).', 'betait-spfy-playlist' ) . '</p>';
		echo '  </td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '</div>'; // /tabpanel general

		/* ----- TAB: API ----- */
		echo '<div class="bspfy-tabpanel" id="bspfy-tab-api" role="region" aria-label="' . esc_attr__( 'Spotify API', 'betait-spfy-playlist' ) . '">';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'Spotify API credentials', 'betait-spfy-playlist' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_client_id">' . esc_html__( 'Spotify Client ID', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td><input type="text" id="bspfy_client_id" name="bspfy_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text" /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_client_secret">' . esc_html__( 'Spotify Client Secret', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td><input type="password" id="bspfy_client_secret" name="bspfy_client_secret" value="' . esc_attr( $client_secret ) . '" class="regular-text" autocomplete="new-password" /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_debug">' . esc_html__( 'Enable debugging', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td><label><input type="checkbox" id="bspfy_debug" name="bspfy_debug" ' . checked( 1, $debug_enabled, false ) . ' /> ' . esc_html__( 'Verbose console logs (tokens masked).', 'betait-spfy-playlist' ) . '</label></td>';
		echo '</tr>';

		echo '</tbody></table>';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'Security & cookies', 'betait-spfy-playlist' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_require_premium">' . esc_html__( 'Require Spotify Premium', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td><label><input type="checkbox" id="bspfy_require_premium" name="bspfy_require_premium" ' . checked( 1, $require_premium, false ) . ' /> ' . esc_html__( 'Show premium-required UI and block features that fail on Free accounts.', 'betait-spfy-playlist' ) . '</label></td>';
		echo '</tr>';

		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_strict_samesite">' . esc_html__( 'Strict SameSite for auth cookies', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td><label><input type="checkbox" id="bspfy_strict_samesite" name="bspfy_strict_samesite" ' . checked( 1, $strict_samesite, false ) . ' /> ' . esc_html__( 'Use SameSite=Strict (can break OAuth redirects; default is Lax).', 'betait-spfy-playlist' ) . '</label></td>';
		echo '</tr>';

		echo '</tbody></table>';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'How to set up your Spotify App (PKCE)', 'betait-spfy-playlist' ) . '</h2>';
		echo '<ol class="bspfy-ol">';
		echo '  <li>' . wp_kses_post( __( 'Go to the <a href="https://developer.spotify.com/dashboard/" target="_blank" rel="noopener">Spotify Developer Dashboard</a> and create an app.', 'betait-spfy-playlist' ) ) . '</li>';
		echo '  <li>' . esc_html__( 'App type: Web app.', 'betait-spfy-playlist' ) . '</li>';
		echo '  <li>' . esc_html__( 'Add Redirect URI:', 'betait-spfy-playlist' ) . ' <code>' . esc_html( $redirect_uri ) . '</code></li>';
		echo '  <li>' . esc_html__( 'Scopes (minimum):', 'betait-spfy-playlist' ) . ' <code>streaming user-read-playback-state user-modify-playback-state</code></li>';
		echo '  <li>' . esc_html__( 'Save and use Client ID / Secret above.', 'betait-spfy-playlist' ) . '</li>';
		echo '</ol>';

		echo '</div>'; // /tabpanel api
		
		/* ----- TAB: TOOLS & DEBUG ----- */
		echo '<div class="bspfy-tabpanel" id="bspfy-tab-tools" role="region" aria-label="' . esc_attr__( 'Tools & Debug', 'betait-spfy-playlist' ) . '">';

		echo '<h2 class="bspfy-h2">' . esc_html__( 'Tools & Debug', 'betait-spfy-playlist' ) . '</h2>';

		echo '<table class="form-table"><tbody>';
		// Toggle for å aktivere migrering (engangskjøring via signert URL).
		echo '<tr>';
		echo '  <th scope="row"><label for="bspfy_enable_unicode_tools">' . esc_html__( 'Enable Unicode normalizer', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <label>';
		echo '      <input type="checkbox" id="bspfy_enable_unicode_tools" name="bspfy_enable_unicode_tools" value="1" ' . checked( 1, (int) get_option( 'bspfy_enable_unicode_tools', 0 ), false ) . ' />';
		echo '      ' . esc_html__( 'Allow running a one-time migration that normalizes legacy _playlist_tracks to proper UTF-8.', 'betait-spfy-playlist' );
		echo '    </label>';
		echo '    <p class="description">' . esc_html__( 'Take a database backup first. The migration is idempotent and can be re-run if needed.', 'betait-spfy-playlist' ) . '</p>';
		echo '  </td>';
		echo '</tr>';

		// Kjør-lenke (vises kun når togglen er på)
		if ( get_option( 'bspfy_enable_unicode_tools', 0 ) ) {
			$run_url = wp_nonce_url(
				add_query_arg( 'bspfy_fix_unicode_run', '1', menu_page_url( 'betait-spfy-playlist-settings', false ) ),
				'bspfy_fix_unicode'
			);
			echo '<tr>';
			echo '  <th scope="row">' . esc_html__( 'Run migration', 'betait-spfy-playlist' ) . '</th>';
			echo '  <td>';
			echo '    <a href="' . esc_url( $run_url ) . '" class="button button-primary">' . esc_html__( 'Normalize now', 'betait-spfy-playlist' ) . '</a>';
			echo '    <p class="description">' . esc_html__( 'Scans all “playlist” posts and rewrites _playlist_tracks JSON as UTF-8.', 'betait-spfy-playlist' ) . '</p>';
			echo '  </td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<hr />';

		// OAuth Health
		echo '<h3>' . esc_html__( 'OAuth Health', 'betait-spfy-playlist' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Checks the OAuth controller to help diagnose auth/token issues.', 'betait-spfy-playlist' ) . '</p>';
		echo '<p><button type="button" class="button" id="bspfy-check-health">' . esc_html__( 'Check now', 'betait-spfy-playlist' ) . '</button></p>';
		echo '<pre id="bspfy-health-output" style="background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:12px;max-height:320px;overflow:auto;"></pre>';

		echo '</div>'; // /tabpanel tools

		echo '<p><button type="submit" name="bspfy_save_settings" class="button button-primary">' . esc_html__( 'Save Settings', 'betait-spfy-playlist' ) . '</button></p>';
		echo '</form>';
		echo '</div>'; // /wrap
	}

	/**
	 * User profile section: Spotify connection (status + actions).
	 *
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function render_spotify_auth_profile_row( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$cache = get_user_meta( $user->ID, 'bspfy_access_cache', true );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$has_rt        = (bool) get_user_meta( $user->ID, 'bspfy_refresh_token', true );
		$is_self       = ( $user->ID === get_current_user_id() );
		$session_has_rt = ( $is_self && ! empty( $_COOKIE['bspfy_rt'] ) );

		$exp_ts = isset( $cache['expires_in'] ) ? (int) $cache['expires_in'] : 0;
		$exp_ok = $exp_ts > time();

		$is_connected = ( ( ! empty( $cache['access_token'] ) && $exp_ok ) || $has_rt || $session_has_rt );
		$status       = $is_connected ? __( 'Connected', 'betait-spfy-playlist' ) : __( 'Not connected', 'betait-spfy-playlist' );

		$nonce = wp_create_nonce( 'bspfy_disconnect_' . $user->ID );

		$token_type     = isset( $cache['token_type'] ) ? (string) $cache['token_type'] : '';
		$expires_in_txt = '';
		if ( $exp_ok ) {
			$expires_in_txt = sprintf(
				_x( '%s left', 'time left', 'betait-spfy-playlist' ),
				human_time_diff( time(), $exp_ts )
			);
		}
		$desc_parts = array();
		if ( $token_type ) {
			$desc_parts[] = $token_type;
		}
		if ( $expires_in_txt ) {
			$desc_parts[] = $expires_in_txt;
		}

		$rt_tail = '';
		if ( $has_rt ) {
			$rt_full = (string) get_user_meta( $user->ID, 'bspfy_refresh_token', true );
			if ( '' !== $rt_full ) {
				$rt_tail = '…' . substr( $rt_full, -6 );
			}
		}

		echo '<h2>' . esc_html__( 'Spotify connection', 'betait-spfy-playlist' ) . '</h2>';
		echo '<div id="bspfy-profile-root" data-user-id="' . esc_attr( $user->ID ) . '" data-disconnect-nonce="' . esc_attr( $nonce ) . '"></div>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr>';
		echo '  <th><label>' . esc_html__( 'Status', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <strong>' . esc_html( $status ) . '</strong>';

		if ( ! empty( $desc_parts ) ) {
			echo '  <span class="description"> (' . esc_html( implode( ', ', $desc_parts ) ) . ')</span>';
		}

		if ( $has_rt ) {
			echo '  <div class="description">' . esc_html__( 'Refresh token:', 'betait-spfy-playlist' ) . ' ' . esc_html( $rt_tail ) . ' <em>' . esc_html__( 'saved on user', 'betait-spfy-playlist' ) . '</em></div>';
		} elseif ( $session_has_rt ) {
			echo '  <div class="description">' . esc_html__( 'Refresh token:', 'betait-spfy-playlist' ) . ' <em>' . esc_html__( 'present in session (cookie)', 'betait-spfy-playlist' ) . '</em></div>';
		}

		echo '    <p class="submit" style="margin-top:8px;">';
		echo '      <button type="button" class="button" id="bspfy-auth-test">' . esc_html__( 'Test connection', 'betait-spfy-playlist' ) . '</button> ';
		if ( $has_rt || $session_has_rt ) {
			echo '    <button type="button" class="button" id="bspfy-auth-reconnect">' . esc_html__( 'Reconnect', 'betait-spfy-playlist' ) . '</button> ';
			echo '    <button type="button" class="button button-link-delete" id="bspfy-auth-disconnect">' . esc_html__( 'Disconnect', 'betait-spfy-playlist' ) . '</button>';
		} else {
			echo '    <button type="button" class="button button-primary" id="bspfy-auth-connect">' . esc_html__( 'Connect to Spotify', 'betait-spfy-playlist' ) . '</button>';
		}
		echo '    </p>';

		echo '  </td>';
		echo '</tr>';

		echo '</tbody></table>';
	}

	/**
	 * Enqueue small inline JS only on profile/user-edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_profile_inline_js( $hook ) {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}

		$admin_post_url = esc_url_raw( admin_url( 'admin-post.php' ) );

		$inline = <<<JS
document.addEventListener('DOMContentLoaded', function(){
(function(){
    // REST helpers from localized data.
    var REST_ROOT  = ((window.bspfyDebug && bspfyDebug.rest_root)  || (window.location.origin + '/wp-json')).replace(/\\/\$/, '');
    var REST_NONCE =  (window.bspfyDebug && bspfyDebug.rest_nonce) || '';
    var ADMIN_POST = '{$admin_post_url}'; // admin-post endpoint for disconnect

    var base = REST_ROOT + '/bspfy/v1/oauth';

    function \$(s){ return document.querySelector(s); }

    function withNonceUrl(u){
        try {
            var url = new URL(u, window.location.origin);
            if (REST_NONCE) url.searchParams.set('_wpnonce', REST_NONCE);
            return url.toString();
        } catch(e){
            return u + (u.indexOf('?')>-1 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(REST_NONCE || '');
        }
    }

    function fetchJSON(u, opts){
        var headersIn = (opts && opts.headers) ? opts.headers : {};
        var finalHdrs = Object.assign({}, headersIn, (REST_NONCE ? {'X-WP-Nonce': REST_NONCE} : {}));
        var finalOpts = Object.assign({ credentials:'include', cache:'no-store', headers: finalHdrs }, (opts||{}));
        return fetch(withNonceUrl(u), finalOpts).then(function(r){
            return r.json().then(function(j){
                if(!r.ok){ var e=new Error('HTTP ' + r.status); e.status=r.status; e.body=j; throw e; }
                return j;
            });
        });
    }

    var root = document.getElementById('bspfy-profile-root'); if (!root) return;
    var userId = parseInt(root.dataset.userId||'0',10) || 0;
    var nonce  = root.dataset.disconnectNonce || '';

    function getContainers(){
        var table = root.nextElementSibling;
        if (!table || table.tagName.toLowerCase() !== 'table') {
            table = document.querySelector('#bspfy-profile-root + table.form-table, #bspfy-profile-root + table');
        }
        if (!table) return {};
        var td = table.querySelector('tr > td');
        if (!td) return { table: table };
        var submit = td.querySelector('.submit');
        if (!submit) {
            submit = document.createElement('p');
            submit.className = 'submit';
            submit.style.marginTop = '8px';
            td.appendChild(submit);
        }
        return { table: table, td: td, submit: submit };
    }

    function ensureButton(id, html){
        var existing = document.getElementById(id);
        if (existing) return existing;
        var c = getContainers();
        if (!c.submit) return null;
        var temp = document.createElement('span'); temp.innerHTML = html.trim();
        var node = temp.firstElementChild; if (!node) return null;
        c.submit.appendChild(node);
        return node;
    }

    function setStatus(text){
        var c = getContainers();
        if (!c.td) return;
        var strong = c.td.querySelector('strong');
        if (strong) strong.textContent = text;
    }

    function toConnectedUI(){
        setStatus('Connected');
        var btnConn = document.getElementById('bspfy-auth-connect');
        if (btnConn) btnConn.style.display = 'none';
        var btnRe  = ensureButton('bspfy-auth-reconnect','<button type="button" class="button" id="bspfy-auth-reconnect">Reconnect</button>');
        var btnDis = ensureButton('bspfy-auth-disconnect','<button type="button" class="button button-link-delete" id="bspfy-auth-disconnect">Disconnect</button>');
        if (btnRe)  btnRe.onclick  = connectOrReconnect;
        if (btnDis) btnDis.onclick = doDisconnect;
    }

    function openPopupAuth(){
        return fetchJSON(base + '/start', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ redirectBack: window.location.href })
        }).then(function(d){
            var w=520,h=680,l=window.screenX+(window.outerWidth-w)/2,t=window.screenY+(window.outerHeight-h)/2;
            var pop=window.open(d.authorizeUrl,'bspfy-auth','width='+w+',height='+h+',left='+l+',top='+t);
            if(!pop) throw new Error('POPUP_BLOCKED');
            return new Promise(function(resolve,reject){
                var iv=setInterval(function(){ try{ if(!pop || pop.closed){ clearInterval(iv); reject(new Error('popup-closed')); } }catch(_){ } }, 800);
                function onMsg(ev){
                    if(ev.origin!==window.location.origin) return;
                    if(ev.data && ev.data.type==='bspfy-auth' && ev.data.success){
                        window.removeEventListener('message',onMsg);
                        clearInterval(iv); try{ pop.close(); }catch(e){}
                        resolve(true);
                    }
                }
                window.addEventListener('message', onMsg);
            });
        });
    }

    function pollForToken(timeoutMs){
        var deadline = Date.now() + (timeoutMs||5000);
        return new Promise(function(resolve,reject){
            (function tick(){
                fetchJSON(base + '/token').then(function(r){
                    if (r && r.access_token) return resolve(r);
                    if (Date.now() > deadline) return reject(new Error('token-timeout'));
                    setTimeout(tick, 350);
                }).catch(function(_){
                    if (Date.now() > deadline) return reject(new Error('token-timeout'));
                    setTimeout(tick, 350);
                });
            })();
        });
    }

    function connectOrReconnect(){
        openPopupAuth()
            .then(function(){ return pollForToken(5000); })
            .then(function(){ toConnectedUI(); })
            .catch(function(e){ alert('Could not authenticate: ' + (e && e.message ? e.message : 'unknown error')); });
    }

    function doDisconnect(){
        fetchJSON(base + '/logout', { method:'POST' })
            .finally(function(){
                var form = document.createElement('form'); form.method='POST'; form.action=ADMIN_POST;
                form.innerHTML =
                    '<input type="hidden" name="action" value="bspfy_disconnect_spotify" />' +
                    '<input type="hidden" name="user_id" value="'+ String(userId) +'" />' +
                    '<input type="hidden" name="bspfy_disconnect_nonce" value="'+ String(nonce) +'" />';
                document.body.appendChild(form); form.submit();
            });
    }

    // Init buttons.
    var btnTest = document.getElementById('bspfy-auth-test');
    var btnConn = document.getElementById('bspfy-auth-connect');
    var btnRe   = document.getElementById('bspfy-auth-reconnect');
    var btnDis  = document.getElementById('bspfy-auth-disconnect');

    if (btnTest) btnTest.onclick = function(){
        fetchJSON(base + '/token')
            .then(function(res){
                return (res && res.access_token)
                    ? fetch('https://api.spotify.com/v1/me', { headers:{ Authorization:'Bearer ' + res.access_token } })
                        .then(function(m){ return m.json(); })
                        .then(function(me){ alert('Connected as: ' + (me.display_name || me.id || 'unknown')); })
                    : alert('Not connected (try Connect).');
            })
            .catch(function(){ alert('Test failed.'); });
    };

    if (btnConn) btnConn.onclick = connectOrReconnect;
    if (btnRe)   btnRe.onclick   = connectOrReconnect;
    if (btnDis)  btnDis.onclick  = doDisconnect;

    // Only on own profile: do a quick init check.
    var isSelfProfile = document.body.classList.contains('profile-php');
    if (isSelfProfile) {
        fetchJSON(base + '/token')
            .then(function(r){ if (r && r.access_token) { toConnectedUI(); } })
            .catch(function(){ /* ignore */ });
    }
})();
});
JS;

		wp_add_inline_script( $this->betait_spfy_playlist, $inline, 'after' );

		// Ensure FA exists on these screens too (without double-enqueue).
		if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
			wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', array(), '6.5.0', 'all' );
		}
	}

	/**
	 * POST handler: Disconnect Spotify (clears user meta).
	 *
	 * @return void
	 */
	public function handle_disconnect_spotify() {
		$uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $uid || ! current_user_can( 'edit_user', $uid ) ) {
			wp_die( esc_html__( 'No permission.', 'betait-spfy-playlist' ) );
		}
		if ( ! isset( $_POST['bspfy_disconnect_nonce'] ) || ! wp_verify_nonce( $_POST['bspfy_disconnect_nonce'], 'bspfy_disconnect_' . $uid ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'betait-spfy-playlist' ) );
		}

		delete_user_meta( $uid, 'bspfy_refresh_token' );
		delete_user_meta( $uid, 'bspfy_access_cache' );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'profile.php' );
		wp_safe_redirect( add_query_arg( 'bspfy_disconnected', '1', $redirect ) );
		exit;
	}

	/**
	 * Success notice after disconnect.
	 *
	 * @return void
	 */
	public function maybe_render_disconnect_notice() {
		if ( isset( $_GET['bspfy_disconnected'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Spotify connection has been disconnected for this user.', 'betait-spfy-playlist' ) . '</p></div>';
		}
	}

	/**
 * Register Tools & Debug settings.
 * - bspfy_enable_unicode_tools: toggler at migreringslenke kan vises/kjøres
 */
public function register_tools_settings() : void {
	register_setting(
		'bspfy_settings',
		'bspfy_enable_unicode_tools',
		[
			'type'              => 'boolean',
			'sanitize_callback' => static function( $v ) { return (int) !! $v; },
			'default'           => 0,
		]
	);
}

/**
 * GET-runner for engangsmigrering: normaliserer eksisterende _playlist_tracks til ren UTF-8.
 * Kalles via signert URL fra Tools & Debug-panelet.
 */
public function maybe_run_unicode_normalizer() : void {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( empty( $_GET['bspfy_fix_unicode_run'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Krev at togglen er på for å kunne kjøre.
	if ( ! get_option( 'bspfy_enable_unicode_tools', 0 ) ) {
		wp_die( esc_html__( 'Unicode tool is disabled. Enable it in Tools & Debug first.', 'betait-spfy-playlist' ), 403 );
	}

	check_admin_referer( 'bspfy_fix_unicode' );

	$ids = get_posts( [
		'post_type'      => 'playlist',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	] );

	$updated = 0; $skipped = 0;

	foreach ( $ids as $post_id ) {
		$raw = get_post_meta( $post_id, '_playlist_tracks', true );
		if ( ! $raw ) { $skipped++; continue; }

		$arr = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $arr ) ) { $skipped++; continue; }

		$arr = $this->fix_mojibake_recursive( $arr );

		update_post_meta($post_id, '_playlist_tracks', wp_json_encode( $arr, JSON_UNESCAPED_UNICODE ));
		clean_post_cache($post_id);
		wp_cache_delete($post_id, 'post_meta');
		$updated++;
	}

	set_transient( 'bspfy_unicode_fix_notice', [
		'updated' => $updated,
		'skipped' => $skipped,
		'ts'      => time(),
	], 60 );

	// Tilbake til settings-siden uten query args.
	wp_safe_redirect( remove_query_arg( [ 'bspfy_fix_unicode_run', '_wpnonce' ] ) );
	exit;
}

/**
 * Enkel heuristikk for å reparere typisk mojibake i strings (Ã…/Ã˜/Ã†, â€¦ etc.)
 */
private function fix_mojibake_recursive( $value ) {
    if ( is_array( $value ) ) {
        foreach ( $value as $k => $v ) {
            $value[ $k ] = $this->fix_mojibake_recursive( $v );
        }
        return $value;
    }
    if ( ! is_string( $value ) || $value === '' ) return $value;

    $orig = $value;

    // 0) Fiks "u00xx" som mangler backslash (f.eks. "Tu00ed" -> "Tí")
    if ( stripos($value, 'u00') !== false ) {
        $candidate = preg_replace_callback('/(?<!\\\\)[uU]00([0-9A-Fa-f]{2})/', function($m){
            // trygt: la JSON gjøre dekodingen riktig
            $json = '"\\u00' . strtolower($m[1]) . '"';
            $chr  = json_decode($json);
            return is_string($chr) ? $chr : $m[0];
        }, $value);
        if ( $this->looks_fixed($orig, $candidate) ) {
            $value = $candidate;
            $orig  = $value; // fortsett med videre heuristikk på “bedre” base
        }
    }

    // Rask exit: ingen typiske mojibake-markører igjen
    if (
        strpos($value, 'Ã') === false &&
        strpos($value, 'â') === false &&
        strpos($value, 'Â') === false &&
        strpos($value, '�') === false
    ) {
        return $value;
    }

    // A) utf8_encode/utf8_decode – ofte riktig for "Ã¸/Ã…/Ã†"
    $a = @utf8_encode( @utf8_decode( $value ) );
    if ( $this->looks_fixed( $orig, $a ) ) return $a;

    // B) Windows-1252 -> UTF-8
    if ( function_exists( 'mb_convert_encoding' ) ) {
        $b = @mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
        if ( $this->looks_fixed( $orig, $b ) ) return $b;
    }

    // C) iconv fallback
    if ( function_exists( 'iconv' ) ) {
        $c = @iconv( 'Windows-1252', 'UTF-8//IGNORE', $value );
        if ( $this->looks_fixed( $orig, $c ) ) return $c;
    }

    // D) Målrettet erstatning (skand. + typografiske)
    $map = array(
        'Ã…'=>'Å','Ã†'=>'Æ','Ã˜'=>'Ø','Ã¥'=>'å','Ã¦'=>'æ','Ã¸'=>'ø',
        'Ã‰'=>'É','Ã©'=>'é','Ã¨'=>'è','Ã«'=>'ë','Ã¼'=>'ü','Ã¤'=>'ä','Ã¶'=>'ö',
        'Ã¡'=>'á','Ã '=>'à','Ã­'=>'í','Ã³'=>'ó','Ãº'=>'ú','Ã±'=>'ñ','Ãº'=>'ú','Ã¢'=>'â',
        'â€“'=>'–','â€”'=>'—','â€˜'=>'‘','â€™'=>'’','â€œ'=>'“','â€'=>'”','â€¦'=>'…',
        'Â '=>' ','Â·'=>'·','Â©'=>'©'
    );
    $d = strtr( $value, $map );
    if ( $this->looks_fixed( $orig, $d ) ) return $d;

    return $value;
}

private function looks_fixed( $before, $after ) {
    if ( ! is_string( $after ) || $after === '' ) return false;
    $bad = array('Ã','â','Â','�','u00'); // ← ta med "u00"
    $score = function( $s ) use ( $bad ) { $c = 0; foreach ( $bad as $b ) { $c += substr_count( $s, $b ); } return $c; };
    return $score( $after ) < $score( $before );
}


/**
 * Viser admin notice etter migrering.
 */
public function maybe_show_unicode_notice() : void {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$data = get_transient( 'bspfy_unicode_fix_notice' );
	if ( ! $data ) return;

	delete_transient( 'bspfy_unicode_fix_notice' );

	$updated = (int) ( $data['updated'] ?? 0 );
	$skipped = (int) ( $data['skipped'] ?? 0 );

	echo '<div class="notice notice-success is-dismissible"><p>';
	printf(
		esc_html__( 'Unicode normalization completed. Updated: %d, Skipped: %d.', 'betait-spfy-playlist' ),
		$updated,
		$skipped
	);
	echo '</p></div>';
}

}
