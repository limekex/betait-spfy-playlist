<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://betait.no
 * @since      1.0.0
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Betait_Spfy_Playlist_Admin {

	private $betait_spfy_playlist;
	private $version;

	public function __construct( $betait_spfy_playlist, $version ) {
		$this->betait_spfy_playlist = $betait_spfy_playlist;
		$this->version = $version;

		// --- NEW: Profilseksjon + handlinger
		add_action('show_user_profile', array($this, 'render_spotify_auth_profile_row'));
		add_action('edit_user_profile', array($this, 'render_spotify_auth_profile_row'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'), 10);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'), 10);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_profile_inline_js'), 15);
		add_action('admin_post_bspfy_disconnect_spotify', array($this, 'handle_disconnect_spotify'));
		add_action('admin_notices', array($this, 'maybe_render_disconnect_notice'));
	}

	/**
	 * Admin CSS
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->betait_spfy_playlist, plugin_dir_url( __FILE__ ) . 'css/betait-spfy-playlist-admin.css', array(), $this->version, 'all' );
		wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', array(), '6.5.0', 'all' );
	}

	/**
	 * Admin JS
	 */
	public function enqueue_scripts() {
		$handle = $this->betait_spfy_playlist;

		wp_enqueue_script(
			$handle,
			plugin_dir_url( __FILE__ ) . 'js/betait-spfy-playlist-admin.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		wp_localize_script(
			$handle,
			'bspfyDebug',
			array(
				'debug'   => (bool) get_option( 'bspfy_debug', false ),
				'client_id' => get_option( 'bspfy_client_id', '' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'rest_nonce' => wp_create_nonce('wp_rest'),          
    			'rest_root'  => esc_url_raw( rest_url() ),
				'player_name'    => get_option( 'bspfy_player_name', 'BeTA iT Web Player' ),
				'default_volume' => (float) get_option( 'bspfy_default_volume', 0.5 ), // 0..1
				'require_premium'=> (int) get_option( 'bspfy_require_premium', 1 ), 
			)
		);

		// Last Spotify SDK KUN på redigeringsskjermene for CPT=playlist
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( $screen && in_array( $screen->base, array('post','post-new'), true ) && $screen->post_type === 'playlist' ) {
			wp_enqueue_script( 'spotify-sdk', 'https://sdk.scdn.co/spotify-player.js', array(), null, true );
		}
	}

	/**
	 * Meny
	 */
	public function add_admin_menu() {
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
	 * Hovedside (enkel)
	 */
	public function display_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Spotify Playlists', 'betait-spfy-playlist' ) . '</h1>';
		echo '<form method="post" action="">';
		echo '<input type="text" name="spotify_query" placeholder="' . esc_attr__( 'Search for tracks...', 'betait-spfy-playlist' ) . '" class="bspfy-input">';
		echo '<button type="submit" class="bspfy-button">' . esc_html__( 'Search', 'betait-spfy-playlist' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Settings (inkl. playlist_theme og general player options)
	 */
	public function display_settings_page() {

		// ----- Save -----
		if ( isset( $_POST['bspfy_save_settings'] ) && check_admin_referer( 'bspfy_save_settings_nonce', 'bspfy_save_settings_nonce_field' ) ) {

			// API creds
			update_option( 'bspfy_client_id',     sanitize_text_field( $_POST['bspfy_client_id']     ?? '' ) );
			update_option( 'bspfy_client_secret', sanitize_text_field( $_POST['bspfy_client_secret'] ?? '' ) );
			update_option( 'bspfy_debug',         isset( $_POST['bspfy_debug'] ) ? 1 : 0 );

			// Player
			update_option( 'bspfy_player_name', sanitize_text_field( $_POST['bspfy_player_name'] ?? 'BeTA iT Web Player' ) );

			$vol_percent = isset( $_POST['bspfy_default_volume'] ) ? floatval( $_POST['bspfy_default_volume'] ) : 50;
			$vol_percent = max( 0, min( 100, $vol_percent ) );
			update_option( 'bspfy_default_volume', $vol_percent / 100.0 );

			$player_theme = sanitize_key( $_POST['bspfy_player_theme'] ?? 'default' );
			update_option( 'bspfy_player_theme', $player_theme );

			// Playlist theme (punkt 1): card | list
			$playlist_theme = sanitize_key( $_POST['bspfy_playlist_theme'] ?? 'card' );
			update_option( 'bspfy_playlist_theme', in_array( $playlist_theme, array( 'card', 'list' ), true ) ? $playlist_theme : 'card' );

			// Security
			update_option( 'bspfy_require_premium', isset( $_POST['bspfy_require_premium'] ) ? 1 : 0 );
			update_option( 'bspfy_strict_samesite', isset( $_POST['bspfy_strict_samesite'] ) ? 1 : 0 );

			echo '<div class="updated notice"><p>' . esc_html__( 'Settings saved!', 'betait-spfy-playlist' ) . '</p></div>';
		}

		// ----- Load -----
		$client_id     = get_option( 'bspfy_client_id', '' );
		$client_secret = get_option( 'bspfy_client_secret', '' );
		$debug_enabled = (int) get_option( 'bspfy_debug', 0 );

		$player_name = get_option( 'bspfy_player_name', 'BeTA iT Web Player' );
		$vol_0to1    = (float) get_option( 'bspfy_default_volume', 0.5 );
		$vol_percent = (int) round( $vol_0to1 * 100 );

		$player_themes = apply_filters( 'bspfy_player_themes', array(
			'default' => __( 'Default', 'betait-spfy-playlist' ),
			'dock'    => __( 'Dock', 'betait-spfy-playlist' ),
		) );

		// Kun "card" og "list" som avtalt
		$playlist_themes = apply_filters( 'bspfy_playlist_themes', array(
			'card' => __( 'Card (default)', 'betait-spfy-playlist' ),
			'list' => __( 'List (mobile style)', 'betait-spfy-playlist' ),
		) );

		$player_theme   = get_option( 'bspfy_player_theme', 'default' );
		$playlist_theme = get_option( 'bspfy_playlist_theme', 'card' );

		// Defaults: premium=true, samesite=false
		$require_premium = (int) get_option( 'bspfy_require_premium', 1 );
		$strict_samesite = (int) get_option( 'bspfy_strict_samesite', 0 );

		$redirect_uri = esc_url( home_url( '/wp-json/bspfy/v1/oauth/callback' ) );

		echo '<div class="wrap bspfy-wrap">';
		echo '<h1>' . esc_html__( 'BSPFY Settings', 'betait-spfy-playlist' ) . '</h1>';

		// Tabs
		echo '<div class="bspfy-tabs">';
		echo '  <button type="button" class="bspfy-tab is-active" data-tab="general">' . esc_html__( 'General settings', 'betait-spfy-playlist' ) . '</button>';
		echo '  <button type="button" class="bspfy-tab" data-tab="api">' . esc_html__( 'Spotify API', 'betait-spfy-playlist' ) . '</button>';
		echo '</div>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'bspfy_save_settings_nonce', 'bspfy_save_settings_nonce_field' );

		/* --------- TAB: GENERAL --------- */
		echo '<div class="bspfy-tabpanel is-active" id="bspfy-tab-general" role="region" aria-label="General settings">';

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
		echo '  <th scope="row"><label for="bspfy_default_volume_range">' . esc_html__( 'Standard volume', 'betait-spfy-playlist' ) . '</label></th>';
		echo '  <td>';
		echo '    <div class="bspfy-volume-field">';
		echo '      <input type="range" id="bspfy_default_volume_range" min="0" max="100" step="1" value="' . esc_attr( $vol_percent ) . '"/>';
		echo '      <input type="number" id="bspfy_default_volume" name="bspfy_default_volume" min="0" max="100" step="1" value="' . esc_attr( $vol_percent ) . '" class="small-text" />';
		echo '      <span>%</span>';
		echo '    </div>';
		echo '    <p class="description">' . esc_html__( 'Applied on first SDK init (users may change it later).', 'betait-spfy-playlist' ) . '</p>';
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
		echo '    <p class="description">' . esc_html__( 'Choose a player layout (logic to be implemented).', 'betait-spfy-playlist' ) . '</p>';
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

		/* --------- TAB: API --------- */
		echo '<div class="bspfy-tabpanel" id="bspfy-tab-api" role="region" aria-label="Spotify API">';

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
		echo '  <th scope="row"><label for="bspfy_debug">' . esc_html__( 'Enable Debugging', 'betait-spfy-playlist' ) . '</label></th>';
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

		echo '<p><button type="submit" name="bspfy_save_settings" class="button button-primary">' . esc_html__( 'Save Settings', 'betait-spfy-playlist' ) . '</button></p>';
		echo '</form>';
		echo '</div>'; // /wrap
	}

	/** ----------------------------------------------------------------
 *  Profilseksjon: Spotify-tilkobling (status + knapper)
 *  ---------------------------------------------------------------- */
public function render_spotify_auth_profile_row( WP_User $user ) {
	if ( ! current_user_can('edit_user', $user->ID) ) return;

	$cache   = get_user_meta($user->ID, 'bspfy_access_cache', true);
	if ( ! is_array($cache) ) $cache = array();

	$has_rt  = (bool) get_user_meta($user->ID, 'bspfy_refresh_token', true);

	// Teller som session connected for EGEN profil (httpOnly cookie – kan ikke vise tail)
	$is_self         = ( $user->ID === get_current_user_id() );
	$session_has_rt  = ( $is_self && ! empty($_COOKIE['bspfy_rt']) );

	$exp_ts  = isset($cache['expires_in']) ? intval($cache['expires_in']) : 0;
	$exp_ok  = $exp_ts > time();

	$is_connected = ( (!empty($cache['access_token']) && $exp_ok) || $has_rt || $session_has_rt );
	$status  = $is_connected ? __('Connected', 'betait-spfy-playlist') : __('Not connected', 'betait-spfy-playlist');

	$nonce = wp_create_nonce('bspfy_disconnect_'.$user->ID);

	// Beskrivelsestekst: token type + tid igjen
	$token_type     = isset($cache['token_type']) ? (string) $cache['token_type'] : '';
	$expires_in_txt = '';
	if ( $exp_ok ) {
		$expires_in_txt = sprintf(
			_x('%s left', 'time left', 'betait-spfy-playlist'),
			human_time_diff(time(), $exp_ts)
		);
	}
	$desc_parts = array();
	if ($token_type)     $desc_parts[] = $token_type;
	if ($expires_in_txt) $desc_parts[] = $expires_in_txt;

	// Tail på refresh token når den er lagret på brukeren (ikke mulig fra httpOnly cookie)
	$rt_tail = '';
	if ($has_rt) {
		$rt_full = (string) get_user_meta($user->ID, 'bspfy_refresh_token', true);
		if ($rt_full !== '') $rt_tail = '…' . substr($rt_full, -6);
	}

	echo '<h2>'. esc_html__('Spotify connection', 'betait-spfy-playlist') .'</h2>';
	echo '<div id="bspfy-profile-root" data-user-id="'. esc_attr($user->ID) .'" data-disconnect-nonce="'. esc_attr($nonce) .'"></div>';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr>';
	echo '  <th><label>'. esc_html__('Status', 'betait-spfy-playlist') .'</label></th>';
	echo '  <td>';
	echo '    <strong>'. esc_html($status) .'</strong>';

	if ( !empty($desc_parts) ) {
		echo '  <span class="description"> ('. esc_html(implode(', ', $desc_parts)) .')</span>';
	}

	if ($has_rt) {
		echo '  <div class="description">'. esc_html__('Refresh token:', 'betait-spfy-playlist') .' '. esc_html($rt_tail) .' <em>'. esc_html__('saved on user', 'betait-spfy-playlist') .'</em></div>';
	} elseif ($session_has_rt) {
		echo '  <div class="description">'. esc_html__('Refresh token:', 'betait-spfy-playlist') .' <em>'. esc_html__('present in session (cookie)', 'betait-spfy-playlist') .'</em></div>';
	}

	echo '    <p class="submit" style="margin-top:8px;">';
	echo '      <button type="button" class="button" id="bspfy-auth-test">'. esc_html__('Test connection', 'betait-spfy-playlist') .'</button> ';
	if ($has_rt || $session_has_rt) {
		echo '    <button type="button" class="button" id="bspfy-auth-reconnect">'. esc_html__('Reconnect', 'betait-spfy-playlist') .'</button> ';
		echo '    <button type="button" class="button button-link-delete" id="bspfy-auth-disconnect">'. esc_html__('Disconnect', 'betait-spfy-playlist') .'</button>';
	} else {
		echo '    <button type="button" class="button button-primary" id="bspfy-auth-connect">'. esc_html__('Connect to Spotify', 'betait-spfy-playlist') .'</button>';
	}
	echo '    </p>';

	echo '  </td>';
	echo '</tr>';

	echo '</tbody></table>';
}


/** ----------------------------------------------------------------
 *  Enqueue liten inline-JS kun på profil/rediger-bruker
 *  ---------------------------------------------------------------- */
public function enqueue_profile_inline_js( $hook ) {
    if ( $hook !== 'profile.php' && $hook !== 'user-edit.php' ) return;

    // Bruk admin-post.php, ikke admin-ajax.php, til disconnect-handlingen
    $admin_post_url = esc_url_raw( admin_url('admin-post.php') );

    $inline = <<<JS
document.addEventListener('DOMContentLoaded', function(){
(function(){
    // Hent REST-data fra bspfyDebug, med fallbacks
    var REST_ROOT  = ((window.bspfyDebug && bspfyDebug.rest_root)  || (window.location.origin + '/wp-json')).replace(/\\/\$/, '');
    var REST_NONCE =  (window.bspfyDebug && bspfyDebug.rest_nonce) || '';
    var ADMIN_POST = '{$admin_post_url}'; // <-- riktig endpoint for admin_post_*

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

	    // ... (uendret UI-hjelpefunksjoner)

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

	// Init knapper
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

	// Kun på egen profilside: init-sjekk
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

    wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', array(), '6.5.0', 'all' );
}



/** ----------------------------------------------------------------
 *  POST handler: Koble fra (rydder usermeta)
 *  ---------------------------------------------------------------- */
public function handle_disconnect_spotify() {
	$uid = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
	if ( ! $uid || ! current_user_can('edit_user', $uid) ) {
		wp_die('No permission');
	}
	if ( ! isset($_POST['bspfy_disconnect_nonce']) || ! wp_verify_nonce( $_POST['bspfy_disconnect_nonce'], 'bspfy_disconnect_' . $uid ) ) {
		wp_die('Invalid nonce');
	}

	delete_user_meta($uid, 'bspfy_refresh_token');
	delete_user_meta($uid, 'bspfy_access_cache');

	$redirect = wp_get_referer() ?: admin_url('profile.php');
	wp_safe_redirect( add_query_arg('bspfy_disconnected', '1', $redirect) );
	exit;
}

/** ----------------------------------------------------------------
 *  Liten bekreftelse etter frakobling
 *  ---------------------------------------------------------------- */
public function maybe_render_disconnect_notice() {
	if ( isset($_GET['bspfy_disconnected']) ) {
		echo '<div class="notice notice-success is-dismissible"><p>'. esc_html__('Spotify connection has been disconnected for this user.', 'betait-spfy-playlist') .'</p></div>';
	}
}

}
