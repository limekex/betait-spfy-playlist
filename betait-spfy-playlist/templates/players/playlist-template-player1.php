
<?php
/**
 * Template Name: Playlist Template: Player 1 
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} 

//$default_cover = plugin_dir_url(__FILE__) . '../../assets/default_cover.jpg';
$default_cover = plugin_dir_url(dirname(__FILE__)) . '../assets/default_cover.jpg';

?>

    <!-- BeTA iT Spotify Player HTML -->
    <div id="bspfy-pl1-player-container">
  <div id="bspfy-pl1-player">
    <div id="bspfy-pl1-player-track">
      <div id="bspfy-pl1-album-name" class="bspfy-one-line bspfy-scroll"></div>
      <div id="bspfy-pl1-track-name" class="bspfy-one-line bspfy-scroll"></div>
      <div id="bspfy-pl1-track-time">
        <div id="bspfy-pl1-current-time">00:00</div>
        <div id="bspfy-pl1-track-length">00:00</div>
      </div>
      <div id="bspfy-pl1-seek-bar-container">
        <div id="bspfy-pl1-seek-time"></div>
        <div id="bspfy-pl1-s-hover"></div>
        <div id="bspfy-pl1-seek-bar"></div>
      </div>
    </div>
    <div id="bspfy-pl1-player-content">
      <div id="bspfy-pl1-album-art">
        <img src="<?php echo esc_url($default_cover); ?>" class="bspfy-pl1-active" id="bspfy-pl1-_1" />
        <div id="bspfy-pl1-buffer-box">Buffering ...</div>
      </div>
      <div id="bspfy-pl1-player-controls">
        <div class="bspfy-pl1-control">
          <div class="bspfy-pl1-button" id="bspfy-pl1-play-previous">
            <i class="fas fa-backward"></i>
          </div>
        </div>
        <div class="bspfy-pl1-control">
          <div class="bspfy-pl1-button" id="bspfy-pl1-play-pause-button">
            <i class="fas fa-play"></i>
          </div>
        </div>
        <div class="bspfy-pl1-control">
          <div class="bspfy-pl1-button" id="bspfy-pl1-play-next">
            <i class="fas fa-forward"></i>
          </div>
        </div>
      </div>
    </div>