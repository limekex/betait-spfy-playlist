<?php
/**
 * Template Name: Playlist Template part: Header
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div class="bspfy-header-title"><h2><?php echo get_the_title(); ?></h2>
        <?php
        // Get the current post's description meta data.
        $playlist_description = get_post_meta( get_the_ID(), '_playlist_description', true );

        if ( ! empty( $playlist_description ) ) {
            echo '<div class="bspfy-playlist-description">';
            echo wpautop( wp_kses_post( $playlist_description ) );
            echo '</div>';
        }
        ?></div>
        <div class="bspfy-header-image">
            <?php the_post_thumbnail(); ?>
        </div>