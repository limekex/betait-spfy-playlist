
<?php
/**
 * Template Name: Playlist Template: Footer
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} 

// Spotify Attribution Footer
echo '<div class="bspfy-spotify-attribution">';
echo '<span>' . __( 'BeTA Spfy Playlist is powered by', 'betait-spfy-playlist' ) . '</span>';
echo '<img src="' . plugin_dir_url( __FILE__ ) . '../assets/Spotify_Full_Logo_RGB_Green.png" alt="Spotify Logo" class="bspfy-spotify-logo">';
echo '</div>';
?>

