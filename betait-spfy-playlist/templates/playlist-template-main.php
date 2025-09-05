<?php
/**
 * Template Name: Playlist Template part: Main
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get playlist tracks from the custom field.
$tracks = get_post_meta( get_the_ID(), '_playlist_tracks', true );
$tracks = json_decode( $tracks, true ); // Decode JSON data to an array.

if ( $tracks && is_array( $tracks ) ) {
    echo '<div class="bspfy-playlist-grid">';
    
    foreach ( $tracks as $track ) {
        $track_name = esc_html( $track['name'] ?? __( 'Unknown Track', 'betait-spfy-playlist' ) );
        $album_name = esc_html( $track['album']['name'] ?? __( 'Unknown Album', 'betait-spfy-playlist' ) );
        $artist_name = esc_html( $track['artists'][0]['name'] ?? __( 'Unknown Artist', 'betait-spfy-playlist' ) );
        $track_url = esc_url( $track['external_urls']['spotify'] ?? '#' );
        $album_url = esc_url( $track['album']['external_urls']['spotify'] ?? '#' );
        $artist_url = esc_url( $track['artists'][0]['external_urls']['spotify'] ?? '#' );
        $track_uri = esc_attr( $track['uri'] ?? '' );
        $track_cover_url = esc_attr( $track['album']['images'][0]['url'] ?? '' );

        echo '<div class="bspfy-track-item" data-album-cover="' . esc_url($track['album']['images'][0]['url'] ?? '') . '" style="background: url(' . $track_cover_url . '); background-size: cover; background-blend-mode: color-burn;">';
        echo '<div class="track-info">';
        echo '<strong><span class="bspfy-track-title bspfy-one-line bspfy-scroll">' . $track_name . '</span></strong>';
        echo '<p><span class="bspfy-track-album bspfy-one-line bspfy-scroll">' . $album_name . '</span><span class="bspfy-track-artist bspfy-one-line bspfy-scroll"> - ' . $artist_name . '</span></p>';
        echo '</div>';

        echo '<div class="track-actions">';
        echo '<a href="' . $artist_url . '" target="_blank" class="bspfy-action-icon"><i class="fas fa-user" title="View Artist"></i></a>';
        echo '<a href="' . $album_url . '" target="_blank" class="bspfy-action-icon"><i class="fas fa-compact-disc" title="View Album"></i></a>';
        echo '<a href="' . $track_url . '" target="_blank" class="bspfy-action-icon"><i class="fas fa-music" title="View Track"></i></a>';
        echo '<div class="bspfy-play-icon" data-uri="' . $track_uri . '"><i class="fas fa-play" title="Play"></i></div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
} else {
    echo '<p>' . __( 'No tracks found in this playlist.', 'betait-spfy-playlist' ) . '</p>';
}
?>