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
     * @since    1.0.0
     */
    public static function activate() {

        // Add the custom rewrite rule
        add_rewrite_rule(
            '^spotify-auth-redirect$', // Custom endpoint
            'index.php?spotify_auth_redirect=1', // Query variable for handling redirection
            'top' // Priority of the rule
        );

        // Flush rewrite rules to ensure the new rule is registered immediately
        flush_rewrite_rules();
    }
    add_filter('query_vars', function ($vars) {
        $vars[] = 'spotify_auth_redirect';
        return $vars;
    });
    
    add_action('template_redirect', function () {
        if (get_query_var('spotify_auth_redirect') == 1) {
            include plugin_dir_path(__FILE__) . 'templates/spotify-auth-redirect.php';
            exit;
        }
    });

}
