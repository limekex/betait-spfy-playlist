(function ($) {
  'use strict';

  /**
   * Widget Mini-Player Controller
   * Handles playback for widget/shortcode instances
   */
  const BspfyWidgetPlayer = {
    /**
     * Player instances by widget ID
     */
    instances: {},

    /**
     * Global Spotify player reference
     */
    spotifyPlayer: null,

    /**
     * Currently active widget ID
     */
    activeWidgetId: null,

    /**
     * Initialize all widget players on the page
     */
    init: function () {
      const self = this;

      // Wait for DOM ready
      $(document).ready(function () {
        self.initializeWidgets();
      });
    },

    /**
     * Initialize all widget instances
     */
    initializeWidgets: function () {
      const self = this;
      $('.bspfy-widget-playlist').each(function () {
        const $widget = $(this);
        const widgetId = $widget.data('widget-id');
        const playlistId = $widget.data('playlist-id');

        if (!widgetId) return;

        // Parse track data from embedded JSON
        const $tracksData = $widget.find('.bspfy-widget-tracks-data[data-widget-id="' + widgetId + '"]');
        let tracks = [];
        try {
          tracks = JSON.parse($tracksData.text() || '[]');
        } catch (e) {
          console.error('Failed to parse track data:', e);
          return;
        }

        // Store instance data
        self.instances[widgetId] = {
          $widget: $widget,
          playlistId: playlistId,
          tracks: tracks,
          currentIndex: 0,
          isPlaying: false
        };

        // Bind events
        self.bindEvents(widgetId);
      });
    },

    /**
     * Bind events for a widget instance
     */
    bindEvents: function (widgetId) {
      const self = this;
      const instance = self.instances[widgetId];
      if (!instance) return;

      const $widget = instance.$widget;

      // Play/Pause button
      $widget.find('.bspfy-btn-play').on('click', function () {
        self.togglePlayPause(widgetId);
      });

      // Previous button
      $widget.find('.bspfy-btn-prev').on('click', function () {
        self.playPrevious(widgetId);
      });

      // Next button
      $widget.find('.bspfy-btn-next').on('click', function () {
        self.playNext(widgetId);
      });

      // Volume control
      $widget.find('.bspfy-volume-slider').on('input', function () {
        self.setVolume($(this).val() / 100);
      });

      // Track item clicks
      $widget.find('.bspfy-track-play-btn').on('click', function () {
        const trackIndex = parseInt($(this).data('track-index'), 10);
        self.playTrack(widgetId, trackIndex);
      });

      // Track item row clicks
      $widget.find('.bspfy-track-item').on('click', function (e) {
        // Don't trigger if clicking the button directly
        if ($(e.target).closest('.bspfy-track-play-btn').length) return;
        
        const trackIndex = parseInt($(this).data('track-index'), 10);
        self.playTrack(widgetId, trackIndex);
      });
    },

    /**
     * Ensure Spotify player is initialized
     */
    ensurePlayer: async function () {
      const self = this;

      // Check if player already exists
      if (self.spotifyPlayer && self.spotifyPlayer._options) {
        return self.spotifyPlayer;
      }

      // Check for existing global player
      if (window.spotifyPlayer && window.spotifyPlayer._options) {
        self.spotifyPlayer = window.spotifyPlayer;
        return self.spotifyPlayer;
      }

      // Need to initialize player
      try {
        // Get access token
        const token = await window.bspfyAuth.ensureAccessToken();
        
        // Wait for SDK to be ready
        await self.waitForSpotifySDK();

        // Get player config
        const playerName = window.bspfyPublic?.player_name || 'BeTA iT Web Player';
        const defaultVolume = window.bspfyPublic?.default_volume || 0.5;

        // Create player
        const player = new window.Spotify.Player({
          name: playerName,
          getOAuthToken: cb => {
            window.bspfyAuth.ensureAccessToken()
              .then(token => cb(token))
              .catch(err => {
                console.error('Failed to get token:', err);
                cb('');
              });
          },
          volume: defaultVolume
        });

        // Set up event listeners
        player.addListener('ready', ({ device_id }) => {
          console.log('Spotify player ready with Device ID', device_id);
          player.device_id = device_id;
        });

        player.addListener('not_ready', ({ device_id }) => {
          console.log('Device ID has gone offline', device_id);
        });

        player.addListener('player_state_changed', state => {
          if (state) {
            self.onPlayerStateChanged(state);
          }
        });

        // Connect player
        const connected = await player.connect();
        if (!connected) {
          throw new Error('Failed to connect Spotify player');
        }

        self.spotifyPlayer = player;
        window.spotifyPlayer = player;

        return player;
      } catch (error) {
        console.error('Failed to initialize player:', error);
        
        // If not authenticated, prompt user
        if (error.message === 'not-authenticated') {
          self.promptAuth();
        }
        throw error;
      }
    },

    /**
     * Wait for Spotify SDK to be ready
     */
    waitForSpotifySDK: function () {
      return new Promise((resolve, reject) => {
        if (window.Spotify && window.Spotify.Player) {
          resolve();
          return;
        }

        let attempts = 0;
        const maxAttempts = 50;
        const checkInterval = setInterval(() => {
          attempts++;
          if (window.Spotify && window.Spotify.Player) {
            clearInterval(checkInterval);
            resolve();
          } else if (attempts >= maxAttempts) {
            clearInterval(checkInterval);
            reject(new Error('Spotify SDK failed to load'));
          }
        }, 100);
      });
    },

    /**
     * Toggle play/pause for a widget
     */
    togglePlayPause: async function (widgetId) {
      const self = this;
      const instance = self.instances[widgetId];
      if (!instance) return;

      try {
        const player = await self.ensurePlayer();
        
        if (instance.isPlaying) {
          // Pause
          await player.pause();
          instance.isPlaying = false;
          self.updatePlayButton(widgetId, false);
        } else {
          // Play current track or start from beginning
          await self.playTrack(widgetId, instance.currentIndex);
        }
      } catch (error) {
        console.error('Playback error:', error);
        if (error.message === 'not-authenticated') {
          self.promptAuth();
        }
      }
    },

    /**
     * Play a specific track
     */
    playTrack: async function (widgetId, trackIndex) {
      const self = this;
      const instance = self.instances[widgetId];
      if (!instance || !instance.tracks[trackIndex]) return;

      try {
        const player = await self.ensurePlayer();
        const track = instance.tracks[trackIndex];
        const token = await window.bspfyAuth.ensureAccessToken();

        // Set active widget
        self.activeWidgetId = widgetId;
        instance.currentIndex = trackIndex;

        // Update UI immediately
        self.updateNowPlaying(widgetId, track);
        self.updatePlayButton(widgetId, true);
        self.updateTrackList(widgetId, trackIndex);

        // Start playback via Spotify API
        if (player.device_id) {
          await fetch(`https://api.spotify.com/v1/me/player/play?device_id=${player.device_id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              uris: [track.uri]
            })
          });

          instance.isPlaying = true;
        }
      } catch (error) {
        console.error('Failed to play track:', error);
        if (error.message === 'not-authenticated') {
          self.promptAuth();
        }
      }
    },

    /**
     * Play next track
     */
    playNext: async function (widgetId) {
      const self = this;
      const instance = self.instances[widgetId];
      if (!instance) return;

      const nextIndex = (instance.currentIndex + 1) % instance.tracks.length;
      await self.playTrack(widgetId, nextIndex);
    },

    /**
     * Play previous track
     */
    playPrevious: async function (widgetId) {
      const self = this;
      const instance = self.instances[widgetId];
      if (!instance) return;

      const prevIndex = instance.currentIndex === 0 ? instance.tracks.length - 1 : instance.currentIndex - 1;
      await self.playTrack(widgetId, prevIndex);
    },

    /**
     * Set player volume
     */
    setVolume: async function (volume) {
      const self = this;
      try {
        const player = await self.ensurePlayer();
        await player.setVolume(volume);
      } catch (error) {
        console.error('Failed to set volume:', error);
      }
    },

    /**
     * Update now playing display
     */
    updateNowPlaying: function (widgetId, track) {
      const instance = this.instances[widgetId];
      if (!instance) return;

      const $widget = instance.$widget;
      const artistNames = track.artists.map(a => a.name).join(', ');

      $widget.find(`#${widgetId}-artwork`).attr('src', track.album_image);
      $widget.find(`#${widgetId}-track-name`).text(track.name);
      $widget.find(`#${widgetId}-artist-name`).text(artistNames);
    },

    /**
     * Update play button state
     */
    updatePlayButton: function (widgetId, isPlaying) {
      const instance = this.instances[widgetId];
      if (!instance) return;

      const $btn = instance.$widget.find('.bspfy-btn-play');
      const $icon = $btn.find('i');

      if (isPlaying) {
        $btn.addClass('playing').attr('aria-label', 'Pause');
        $icon.removeClass('fa-play').addClass('fa-pause');
      } else {
        $btn.removeClass('playing').attr('aria-label', 'Play');
        $icon.removeClass('fa-pause').addClass('fa-play');
      }
    },

    /**
     * Update track list highlighting
     */
    updateTrackList: function (widgetId, currentIndex) {
      const instance = this.instances[widgetId];
      if (!instance) return;

      const $widget = instance.$widget;
      $widget.find('.bspfy-track-item').removeClass('playing');
      $widget.find(`.bspfy-track-item[data-track-index="${currentIndex}"]`).addClass('playing');
    },

    /**
     * Handle player state changes
     */
    onPlayerStateChanged: function (state) {
      const self = this;
      
      // Only update the active widget
      if (!self.activeWidgetId) return;
      
      const instance = self.instances[self.activeWidgetId];
      if (!instance) return;

      const isPlaying = !state.paused;
      instance.isPlaying = isPlaying;
      self.updatePlayButton(self.activeWidgetId, isPlaying);

      // Handle track end - play next
      if (state.position === 0 && state.paused && state.track_window.previous_tracks.length > 0) {
        self.playNext(self.activeWidgetId);
      }
    },

    /**
     * Prompt user to authenticate
     */
    promptAuth: async function () {
      if (!window.bspfyAuth || !window.bspfyAuth.startAuthPopup) {
        alert('Please authenticate with Spotify to use the player.');
        return;
      }

      try {
        await window.bspfyAuth.startAuthPopup();
        // Reload to initialize player with new auth
        window.location.reload();
      } catch (error) {
        console.error('Authentication failed:', error);
      }
    }
  };

  // Initialize on page load
  BspfyWidgetPlayer.init();

  // Expose globally if needed
  window.BspfyWidgetPlayer = BspfyWidgetPlayer;

})(jQuery);
