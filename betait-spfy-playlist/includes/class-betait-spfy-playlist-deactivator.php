<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://betait.no
 * @since      1.0.0
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @author     Bjorn-Tore <bt@betait.no>
 */
class Betait_Spfy_Playlist_Deactivator {

    /**
     * Code to execute during plugin deactivation.
     *
     * @since    1.0.0
     */
    public static function deactivate() {

        // Flush rewrite rules to remove any custom rules added by the plugin
        flush_rewrite_rules();
    }

}
