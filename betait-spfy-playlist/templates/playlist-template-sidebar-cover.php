<?php
/**
 * Template Name: Playlist Template part: Sidebar Album Cover
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<!-- THIS SECTION SHOWS THE CURRENT PLAYING TRACKS ALBUM COVER-->
<?php
    // Fallback image path
    $default_cover = plugin_dir_url(__FILE__) . '../assets/default_cover.jpg';
    ?>            
<div id="bspfy-now-playing" class="bspfy-now-playing">
    <img src="<?php echo esc_url($default_cover); ?>" alt="Now Playing" id="bspfy-now-playing-cover">
</div>