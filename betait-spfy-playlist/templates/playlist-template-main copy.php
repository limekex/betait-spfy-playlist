<?php
/**
 * Template Name: Playlist Template part: Main
 * Template Post Type: playlist
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$tracks = get_post_meta( get_the_ID(), '_playlist_tracks', true );
$tracks = json_decode( $tracks, true );
$theme  = get_option( 'bspfy_playlist_theme', 'card' );
$is_list = ( $theme === 'list' );

if ( $tracks && is_array( $tracks ) ) :
  echo '<div class="bspfy-playlist-container">';

  // Viktig: wrapperen har ALLTID .bspfy-playlist-grid (JS krok),
  // og vi legger på en modifikator-klasse for styling
  echo '<div class="bspfy-playlist-grid ' . ( $is_list ? 'is-list' : 'is-card' ) . '">';

  foreach ( $tracks as $track ) :
    $track_name      = esc_html( $track['name'] ?? __( 'Unknown Track', 'betait-spfy-playlist' ) );
    $album_name      = esc_html( $track['album']['name'] ?? __( 'Unknown Album', 'betait-spfy-playlist' ) );
    $artist_name     = esc_html( $track['artists'][0]['name'] ?? __( 'Unknown Artist', 'betait-spfy-playlist' ) );
    $track_url       = esc_url( $track['external_urls']['spotify'] ?? '#' );
    $album_url       = esc_url( $track['album']['external_urls']['spotify'] ?? '#' );
    $artist_url      = esc_url( $track['artists'][0]['external_urls']['spotify'] ?? '#' );
    $track_uri       = esc_attr( $track['uri'] ?? '' );
    $track_cover_url = esc_url( $track['album']['images'][0]['url'] ?? '' );

    if ( $is_list ) : ?>
      <!-- LIST-TEMA: rad med thumb, tittel/artist, kebab-meny -->
      <div class="bspfy-track-item bspfy-list-item"
           data-uri="<?php echo $track_uri; ?>"
           data-album-cover="<?php echo $track_cover_url; ?>">

        <div class="bspfy-list-left">
          <!-- Play-ikon beholdes også i list for kompatibilitet med JS -->
          <button type="button" class="bspfy-play-icon" data-uri="<?php echo $track_uri; ?>" aria-label="<?php esc_attr_e('Play','betait-spfy-playlist'); ?>">
            <i class="fas fa-play" aria-hidden="true"></i>
          </button>
          <img class="bspfy-list-thumb"
               src="<?php echo $track_cover_url; ?>"
               loading="lazy" decoding="async" alt="">
        </div>

        <div class="bspfy-list-center">
          <div class="bspfy-list-title bspfy-one-line bspfy-scroll"><?php echo $track_name; ?></div>
          <div class="bspfy-list-meta bspfy-one-line bspfy-scroll">
            <a href="<?php echo $artist_url; ?>" target="_blank" rel="noopener"><?php echo $artist_name; ?></a>
          </div>
        </div>

        <div class="bspfy-list-right">
          <button type="button" class="bspfy-more" aria-haspopup="menu" aria-expanded="false" aria-label="<?php esc_attr_e('More','betait-spfy-playlist'); ?>">
            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
          </button>
          <div class="bspfy-more-menu" role="menu" hidden>
            <a role="menuitem" class="bspfy-action-link" href="<?php echo $artist_url; ?>" target="_blank" rel="noopener"><?php esc_html_e('View artist','betait-spfy-playlist'); ?></a>
            <a role="menuitem" class="bspfy-action-link" href="<?php echo $album_url; ?>"  target="_blank" rel="noopener"><?php esc_html_e('View album','betait-spfy-playlist'); ?></a>
            <a role="menuitem" class="bspfy-action-link" href="<?php echo $track_url; ?>"  target="_blank" rel="noopener"><?php esc_html_e('Open in Spotify','betait-spfy-playlist'); ?></a>
          </div>
        </div>
      </div>
    <?php else : ?>
      <!-- CARD-TEMA: dagens kort, men sørg for .bspfy-track-item + data-uri -->
      <div class="bspfy-track-item bspfy-track-griditem"
           data-uri="<?php echo $track_uri; ?>"
           data-album-cover="<?php echo $track_cover_url; ?>"
           style="background-image:url('<?php echo $track_cover_url; ?>'); background-size:cover; background-blend-mode:color-burn;">
        <div class="track-info">
          <strong><span class="bspfy-track-title bspfy-one-line bspfy-scroll"><?php echo $track_name; ?></span></strong>
          <p>
            <span class="bspfy-track-album bspfy-one-line bspfy-scroll"><?php echo $album_name; ?></span>
            <span class="bspfy-track-artist bspfy-one-line bspfy-scroll"> - <?php echo $artist_name; ?></span>
          </p>
        </div>
        <div class="track-actions">
          <a href="<?php echo $artist_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener">
            <i class="fas fa-user" title="<?php esc_attr_e('View Artist','betait-spfy-playlist'); ?>"></i>
          </a>
          <a href="<?php echo $album_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener">
            <i class="fas fa-compact-disc" title="<?php esc_attr_e('View Album','betait-spfy-playlist'); ?>"></i>
          </a>
          <a href="<?php echo $track_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener">
            <i class="fas fa-music" title="<?php esc_attr_e('View Track','betait-spfy-playlist'); ?>"></i>
          </a>
          <button type="button" class="bspfy-play-icon" data-uri="<?php echo $track_uri; ?>" aria-label="<?php esc_attr_e('Play','betait-spfy-playlist'); ?>">
            <i class="fas fa-play" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    <?php
    endif;
  endforeach;

  // 🔧 Viktig: lukk den indre wrapperen
  echo '</div>'; // /.bspfy-playlist-grid (is-list | is-card)
  echo '</div>'; // /.bspfy-playlist-container

else :
  echo '<p>' . __( 'No tracks found in this playlist.', 'betait-spfy-playlist' ) . '</p>';
endif;
