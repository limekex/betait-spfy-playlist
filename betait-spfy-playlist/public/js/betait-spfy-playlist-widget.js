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
     * Player initialization promise (to prevent race conditions)
     */
    playerInitPromise: null,

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
          isPlaying: false,
          isInitializing: false // Track if player is being initialized
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
        self.checkAuthAndPlay(widgetId, () => self.togglePlayPause(widgetId));
      });

      // Previous button
      $widget.find('.bspfy-btn-prev').on('click', function () {
        self.checkAuthAndPlay(widgetId, () => self.playPrevious(widgetId));
      });

      // Next button
      $widget.find('.bspfy-btn-next').on('click', function () {
        self.checkAuthAndPlay(widgetId, () => self.playNext(widgetId));
      });

      // Volume control
      $widget.find('.bspfy-volume-slider').on('input', function () {
        self.setVolume($(this).val() / 100);
      });

      // Track play button clicks
      $widget.find('.bspfy-track-play-btn').on('click', function (e) {
        e.stopPropagation(); // Don't trigger row click
        const trackIndex = parseInt($(this).data('track-index'), 10);
        self.checkAuthAndPlay(widgetId, () => self.playTrack(widgetId, trackIndex));
      });

      // Track item row clicks (excluding buttons and menus)
      $widget.find('.bspfy-track-item').on('click', function (e) {
        // Don't trigger if clicking buttons or menus
        if ($(e.target).closest('.bspfy-track-play-btn, .bspfy-track-more, .bspfy-track-more-menu').length) {
          return;
        }
        
        const trackIndex = parseInt($(this).data('track-index'), 10);
        self.checkAuthAndPlay(widgetId, () => self.playTrack(widgetId, trackIndex));
      });

      // Meatball menu toggle
      $widget.find('.bspfy-track-more').on('click', function (e) {
        e.stopPropagation();
        const $btn = $(this);
        const $menu = $btn.siblings('.bspfy-track-more-menu');
        const isExpanded = $btn.attr('aria-expanded') === 'true';

        // Close all other menus first
        $widget.find('.bspfy-track-more').attr('aria-expanded', 'false');
        $widget.find('.bspfy-track-more-menu').attr('hidden', '');

        if (!isExpanded) {
          $btn.attr('aria-expanded', 'true');
          $menu.removeAttr('hidden');
        }
      });

      // Close menus when clicking outside
      $(document).on('click', function (e) {
        if (!$(e.target).closest('.bspfy-track-item').length) {
          $widget.find('.bspfy-track-more').attr('aria-expanded', 'false');
          $widget.find('.bspfy-track-more-menu').attr('hidden', '');
        }
      });
    },

    /**
     * Check authentication before playing
     */
    checkAuthAndPlay: async function (widgetId, playCallback) {
      const self = this;

      try {
        // Show loader
        self.showLoader(widgetId);
        
        // Check if bspfyAuth is available
        if (!window.bspfyAuth || !window.bspfyAuth.ensureAccessToken) {
          console.error('bspfyAuth not available');
          self.hideLoader(widgetId);
          self.showAuthRequired(widgetId);
          return;
        }

        // Try to get access token
        const token = await window.bspfyAuth.ensureAccessToken();
        if (token) {
          // User is authenticated, proceed with playback
          try {
            // Await the callback if it's async
            await playCallback();
          } catch (error) {
            console.error('Playback callback failed:', error);
            throw error;
          } finally {
            // Always hide loader after callback completes (success or error)
            self.hideLoader(widgetId);
          }
        } else {
          // Not authenticated, show auth dialog
          self.hideLoader(widgetId);
          self.showAuthRequired(widgetId);
        }
      } catch (error) {
        console.error('Auth check failed:', error);
        self.hideLoader(widgetId);
        if (error.message === 'not-authenticated') {
          self.showAuthRequired(widgetId);
        } else {
          console.error('Unexpected error:', error);
        }
      }
    },

    /**
     * Show authentication required dialog
     */
    showAuthRequired: function (widgetId) {
      const self = this;
      
      if (confirm('You need to authenticate with Spotify to play music. Would you like to sign in now?')) {
        self.showLoader(widgetId);
        if (window.bspfyAuth && window.bspfyAuth.startAuthPopup) {
          window.bspfyAuth.startAuthPopup()
            .then(() => {
              // Successfully authenticated, try to initialize player
              self.spotifyPlayer = null;
              self.playerInitPromise = null;
              return self.ensurePlayer();
            })
            .then(() => {
              self.hideLoader(widgetId);
            })
            .catch((error) => {
              console.error('Authentication failed:', error);
              self.hideLoader(widgetId);
              alert('Authentication was cancelled or failed. Please try again.');
            });
        } else {
          self.hideLoader(widgetId);
          alert('Authentication system is not available. Please refresh the page.');
        }
      }
    },

    /**
     * Show loading indicator
     */
    showLoader: function (widgetId) {
      const instance = this.instances[widgetId];
      if (!instance) return;
      
      const $loader = instance.$widget.find('.bspfy-widget-loader');
      $loader.attr('aria-busy', 'true').fadeIn(200);
    },

    /**
     * Hide loading indicator
     */
    hideLoader: function (widgetId) {
      const instance = this.instances[widgetId];
      if (!instance) return;
      
      const $loader = instance.$widget.find('.bspfy-widget-loader');
      $loader.attr('aria-busy', 'false').fadeOut(200);
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

      // If already initializing, wait for that to complete
      if (self.playerInitPromise) {
        return self.playerInitPromise;
      }

      // Start initialization
      self.playerInitPromise = (async () => {
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
          self.playerInitPromise = null; // Reset so it can be retried
          
          // If not authenticated, prompt user
          if (error.message === 'not-authenticated') {
            self.promptAuth();
          }
          throw error;
        }
      })();

      return self.playerInitPromise;
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
     * Wait for player device to be ready
     * The 'ready' event must fire before we can use device_id
     */
    waitForDeviceReady: function (player, timeout = 5000) {
      return new Promise((resolve, reject) => {
        // Check if already ready
        if (player.device_id) {
          resolve(player.device_id);
          return;
        }

        // Set up timeout
        const timeoutId = setTimeout(() => {
          reject(new Error('Timeout waiting for device to be ready'));
        }, timeout);

        // Wait for ready event
        const readyListener = ({ device_id }) => {
          clearTimeout(timeoutId);
          player.device_id = device_id;
          resolve(device_id);
        };

        // Add listener (will be called if player becomes ready)
        player.addListener('ready', readyListener);
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
        // Prevent multiple simultaneous initializations
        if (instance.isInitializing) {
          console.log('Player is already initializing...');
          return;
        }
        
        const player = await self.ensurePlayer();
        
        // Get current player state
        const state = await player.getCurrentState();
        
        if (state && !state.paused) {
          // Music is playing, pause it
          await player.pause();
          instance.isPlaying = false;
          self.updatePlayButton(widgetId, false);
          self.hideLoader(widgetId);
        } else {
          // Music is paused or not started, play the track
          // Always call playTrack to ensure proper initialization
          await self.playTrack(widgetId, instance.currentIndex);
        }
      } catch (error) {
        console.error('Playback error:', error);
        self.hideLoader(widgetId);
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
        instance.isInitializing = true;
        const player = await self.ensurePlayer();
        
        // Wait for device to be ready before attempting playback
        await self.waitForDeviceReady(player);
        
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
          const response = await fetch(`https://api.spotify.com/v1/me/player/play?device_id=${player.device_id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
              uris: [track.uri]
            })
          });

          if (!response.ok) {
            throw new Error('Failed to start playback: ' + response.statusText);
          }

          instance.isPlaying = true;
          instance.isInitializing = false;
          self.hideLoader(widgetId);
        } else {
          throw new Error('Device ID not available after waiting');
        }
      } catch (error) {
        console.error('Failed to play track:', error);
        // Reset UI state on error
        instance.isPlaying = false;
        instance.isInitializing = false;
        self.updatePlayButton(widgetId, false);
        self.hideLoader(widgetId);
        
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

      // Handle track end - check if track has changed to next
      if (state.paused && state.track_window.next_tracks.length === 0 && 
          state.duration > 0 && state.position >= state.duration - 1000) {
        // Track ended, play next
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
        // Reinitialize player without reloading page
        this.spotifyPlayer = null;
        this.playerInitPromise = null;
        await this.ensurePlayer();
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
