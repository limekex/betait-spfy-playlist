
<?php
/**
 * Template Name: Playlist Template: Player 1 
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

    <!-- BeTA iT Spotify Player HTML -->
<div id="bspfy-player-container">
  <div id="bspfy-player">
    <div id="bspfy-player-track">
      <div id="bspfy-album-name"></div>
      <div id="bspfy-track-name"></div>
      <div id="bspfy-track-time">
        <div id="bspfy-current-time"></div>
        <div id="bspfy-track-length"></div>
      </div>
      <div id="bspfy-seek-bar-container">
        <div id="bspfy-seek-time"></div>
        <div id="bspfy-s-hover"></div>
        <div id="bspfy-seek-bar"></div>
      </div>
    </div>
    <div id="bspfy-player-content">
      <div id="bspfy-album-art">
        <img src="<?php echo esc_url($default_cover); ?>" class="bspfy-active" id="bspfy-default-artwork" />
        <div id="bspfy-buffer-box">Buffering...</div>
      </div>
      <div id="bspfy-player-controls">
        <div class="bspfy-control">
          <div class="bspfy-button" id="bspfy-play-previous">
            <i class="fas fa-backward"></i>
          </div>
        </div>
        <div class="bspfy-control">
          <div class="bspfy-button" id="bspfy-play-pause-button">
            <i class="fas fa-play"></i>
          </div>
        </div>
        <div class="bspfy-control">
          <div class="bspfy-button" id="bspfy-play-next">
            <i class="fas fa-forward"></i>
          </div>
        </div>
        <div class="bspfy-control bspfy-device-selector">
          <div class="bspfy-button" id="bspfy-device-dropdown">
            <i class="fas fa-volume-up"></i>
            <select id="bspfy-device-list"></select>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>