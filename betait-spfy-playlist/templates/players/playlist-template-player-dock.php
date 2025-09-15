
<?php
/**
 * Template Name: Playlist Template: Player Dock 
 * Template Post Type: playlist
 */

// players/player-dock.php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
 <div class="bspfy-dock-spacer" aria-hidden="true"></div>

  <div id="bspfy-dock" class="bspfy-dock bspfy-dock-container" role="region" aria-label="Player">

<div id="bspfy-dock" class="bspfy-dock" role="region" aria-label="<?php esc_attr_e('Player', 'betait-spfy-playlist'); ?>">
  <!-- Progress / scrub på topp som “kant” -->
  <div id="bspfy-pl1-seek-bar-container" class="bspfy-dock-progress" aria-label="<?php esc_attr_e('Seek bar', 'betait-spfy-playlist'); ?>">
    <div id="bspfy-pl1-s-hover"></div>
    <div id="bspfy-pl1-seek-bar"></div>
    <div id="bspfy-pl1-seek-time">00:00</div>
  </div>

  <div class="bspfy-dock-inner">
    <!-- Venstre: cover -->
    <!-- <div class="bspfy-dock-left">
      <img id="bspfy-now-playing-cover" class="bspfy-dock-cover" alt="" loading="lazy" decoding="async" />
       JS’en din refererte også til denne ID’en, ha den med (kan skjules) 
      <img id="bspfy-pl1-_1" class="bspfy-dock-cover-round" alt="" loading="lazy" decoding="async" />
    </div> -->
    <!-- Kontroller -->
    <div id="bspfy-pl1-player-controls" class="bspfy-dock-ctrl">
      <button id="bspfy-pl1-play-previous" class="bspfy-dock-btn" aria-label="<?php esc_attr_e('Previous', 'betait-spfy-playlist'); ?>">
        <i class="fa-solid fa-backward-step"></i>
      </button>
      <button id="bspfy-pl1-play-pause-button" class="bspfy-dock-btn bspfy-dock-btn-primary" aria-label="<?php esc_attr_e('Play/Pause', 'betait-spfy-playlist'); ?>">
        <i class="fa-solid fa-play"></i>
      </button>
      <button id="bspfy-pl1-play-next" class="bspfy-dock-btn" aria-label="<?php esc_attr_e('Next', 'betait-spfy-playlist'); ?>">
        <i class="fa-solid fa-forward-step"></i>
      </button>
    </div>
    <!-- Midt: tittel/artist + tider -->
    <div id="bspfy-pl1-player-track" class="bspfy-dock-center">
      <div class="bspfy-dock-titles">
        <div id="bspfy-pl1-track-name" class="bspfy-one-line">—</div>
        <div id="bspfy-pl1-album-name" class="bspfy-one-line bspfy-dock-album">—</div>
      </div>
      <div class="bspfy-dock-times">
        <span id="bspfy-pl1-current-time">0:00</span>
        <span aria-hidden="true"> / </span>
        <span id="bspfy-pl1-track-length">0:00</span>
      </div>
    </div>



    <!-- Høyre: mini device/vol monteres her (samme JS-init) -->
    <div id="bspfy-pl1-player" class="bspfy-dock-right"><!-- bspfyMini init monterer inn her -->
       <!-- Device -->
<div class="bspfy-dock-ctl" data-popover-root>
  <button id="bspfy-dock-deviceBtn"
          class="bspfy-dock-icon"
          aria-haspopup="menu"
          aria-expanded="false"
          aria-controls="bspfy-dock-deviceMenu"
          title="Velg avspillingsenhet">
    <i class="fa-solid fa-headphones"></i>
  </button>

  <div id="bspfy-dock-deviceMenu" class="bspfy-dock-popover" role="menu" hidden>
    <!-- Vi bruker samme markup som mini-UI for å gjenbruke CSS -->
    <div class="bspfy-mini-devices" role="listbox" aria-label="Tilgjengelige enheter">
      <!-- fylles av JS -->
    </div>
  </div>
</div>

<!-- Volume -->
<div class="bspfy-dock-ctl" data-popover-root>
  <button id="bspfy-dock-volumeBtn"
          class="bspfy-dock-icon"
          aria-haspopup="true"
          aria-expanded="false"
          aria-controls="bspfy-dock-volumePopover"
          title="Volum">
    <i class="fa-solid fa-volume-high"></i>
  </button>

  <div id="bspfy-dock-volumePopover" class="bspfy-dock-popover bspfy-dock-volume" hidden>
    <input id="bspfy-dock-volumeRange" type="range" min="0" max="1" step="0.01" value="0.5"
           aria-label="Volum">
  </div>
</div>
    </div>
   

  </div>
</div>
</div>