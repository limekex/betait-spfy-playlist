<?php

/**
 * Fired during plugin activation
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_Activator {

    /**
     * Code to execute during plugin activation.
     *
     * @since 1.0.0
     */
    public static function activate() {

        // 1) Defaults (kun hvis de ikke finnes fra før)
        self::ensure_default_options();

        // 2) Evt. custom rewrite (valgfritt – REST-callbacken trenger ikke dette)
        add_rewrite_rule(
            '^spotify-auth-redirect$',
            'index.php?spotify_auth_redirect=1',
            'top'
        );

        // 3) Flush rewrites én gang ved aktivering
        flush_rewrite_rules();
    }

    /**
     * Ensure plugin defaults exist on first activation.
     */
    private static function ensure_default_options() {
        // Admin UI defaults
        add_option('bspfy_player_name',      'BeTA iT Web Player'); // device name i SDK
        add_option('bspfy_default_volume',   0.5);                  // 0–1
        add_option('bspfy_player_theme',     'default');
        add_option('bspfy_playlist_theme',   'default');
        add_option('bspfy_debug',            0);

        // Security options (Punkt 4)
        add_option('bspfy_require_premium',  1); // default: krever Premium
        add_option('bspfy_strict_samesite',  0); // default: Lax for OAuth-redirects

        // API creds tomme som default
        add_option('bspfy_client_id',        '');
        add_option('bspfy_client_secret',    '');
    }
}
