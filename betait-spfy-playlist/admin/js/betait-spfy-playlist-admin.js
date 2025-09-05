(function ($) {
  'use strict';

  /** ----------------------------------------------------------------
   *  PKCE-auth klient (WP REST: /wp-json/bspfy/v1/oauth/*)
   *  ---------------------------------------------------------------- */
  window.bspfyAuth = (function () {
    const base = `${window.location.origin}/wp-json/bspfy/v1/oauth`;

    async function fetchJSON(url, opts = {}) {
      const res = await fetch(url, {
        credentials: 'include', // viktig for cookies
        cache: 'no-store',      // unngå caching av token
        ...opts
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw Object.assign(new Error(json.error || res.statusText), { status: res.status, json });
      return json;
    }

    async function ensureAccessToken() {
      // Prøv kun å hente token – åpne popup KUN hvis vi etterhvert MÅ
      try {
        const data = await fetchJSON(`${base}/token`);
        if (data.authenticated && data.access_token) return data.access_token;
        throw new Error('not-authenticated');
      } catch (e) {
        // Kalleren kan velge å starte popup eksplisitt (f.eks. ved play/søk)
        throw e;
      }
    }

    function startAuthPopup() {
      return new Promise(async (resolve, reject) => {
        const redirectBack = window.location.href;
        let auth;
        try {
          auth = await fetchJSON(`${base}/start`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ redirectBack })
          });
        } catch (e) { return reject(e); }

        const w = 520, h = 680;
        const left = window.screenX + (window.outerWidth - w) / 2;
        const top = window.screenY + (window.outerHeight - h) / 2;
        const popup = window.open(auth.authorizeUrl, 'bspfy-auth', `width=${w},height=${h},left=${left},top=${top}`);

        const handler = (ev) => {
          if (ev.origin !== window.location.origin) return;
          if (ev.data && ev.data.type === 'bspfy-auth' && ev.data.success) {
            window.removeEventListener('message', handler);
            try { popup && popup.close(); } catch (e) { /* noop */ }
            resolve(true);
          }
        };
        window.addEventListener('message', handler);

        // fallback om popup blokkeres / lukkes
        const checkClosed = setInterval(() => {
          try {
            if (!popup || popup.closed) {
              clearInterval(checkClosed);
              reject(new Error('popup-closed'));
            }
          } catch (_) { /* noop */ }
        }, 1000);
      });
    }

    return { ensureAccessToken, startAuthPopup, fetchJSON };
  })();


  /** ----------------------------------------------------------------
   *  Debug helpers
   *  ---------------------------------------------------------------- */
  const debugEnabled = window.bspfyDebug?.debug || false;
  const ajaxUrl = window.bspfyDebug?.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php';
  function logDebug(message, data = null) {
    if (debugEnabled) {
      console.log(`[BeTA iT - Spfy Playlist Debug] ${message}`);
      if (data) console.log(data);
    }
  }


  /** ----------------------------------------------------------------
   *  Spotify Web Playback SDK i admin (valgfritt – kun for “Play” i metabox)
   *  ---------------------------------------------------------------- */
  let spotifyPlayer;
  let deviceId;
  let currentTrackUri = null;

  async function initializeSpotifyPlayer() {
    return new Promise(async (resolve, reject) => {
      try {
        if (!window.Spotify) {
          console.error('Spotify Web Playback SDK not loaded.');
          return reject('Spotify SDK not loaded.');
        }
        // Skaff token (kan kaste “not-authenticated” her – håndteres ved play)
        const initialToken = await window.bspfyAuth.ensureAccessToken();

        spotifyPlayer = new Spotify.Player({
          name: 'BeTA iT Web Player (Admin)',
          getOAuthToken: async cb => {
            try {
              const t = await window.bspfyAuth.ensureAccessToken();
              cb(t);
            } catch (e) {
              console.error('Failed to refresh token for SDK', e);
            }
          },
          volume: 0.5
        });

        spotifyPlayer.addListener('ready', ({ device_id }) => {
          deviceId = device_id;
          logDebug('Spotify Player ready', device_id);
          resolve();
        });

        spotifyPlayer.addListener('not_ready', ({ device_id }) => {
          console.error('Spotify Player not ready with Device ID:', device_id);
        });

        spotifyPlayer.addListener('player_state_changed', state => {
          if (!state) return;
          logDebug('player_state_changed (admin)', state);
        });

        spotifyPlayer.connect();
      } catch (err) {
        console.error('initializeSpotifyPlayer failed', err);
        reject(err);
      }
    });
  }

  async function playTrack(trackUri) {
    try {
      let token;
      try {
        token = await window.bspfyAuth.ensureAccessToken();
      } catch (e) {
        // Start popup KUN når bruker forsøker å spille
        await window.bspfyAuth.startAuthPopup();
        token = await window.bspfyAuth.ensureAccessToken();
      }

      if (!spotifyPlayer || !deviceId) {
        await initializeSpotifyPlayer();
      }

      if (currentTrackUri === trackUri) {
        // Toggle play/pause
        spotifyPlayer.togglePlay();
        return;
      }

      currentTrackUri = trackUri;
      const res = await fetch(`https://api.spotify.com/v1/me/player/play?device_id=${encodeURIComponent(deviceId)}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ uris: [trackUri] }),
      });

      if (!res.ok) {
        const errTxt = await res.text().catch(() => '');
        console.error('Error playing track:', res.status, errTxt);
        alert('Could not play the track. Please try again.');
      } else {
        logDebug('Track is now playing', trackUri);
      }
    } catch (e) {
      console.error('playTrack failed', e);
    }
  }


  /** ----------------------------------------------------------------
   *  DOM Ready – Admin UI (søke + metabox)
   *  ---------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    const clientId = window.bspfyDebug?.client_id || '';
    if (!clientId) console.error('Spotify Client ID is missing. Please configure it in the plugin settings.');
    logDebug('Admin JS loaded, client_id:', clientId);

    // Elements
    const searchButton = document.getElementById('search_tracks_button');
    const searchInput = document.getElementById('playlist_tracks_search');
    const searchResults = document.getElementById('track_search_results');
    const artistFilter = document.getElementById('search_filter_artist');
    const trackFilter = document.getElementById('search_filter_track');
    const albumFilter = document.getElementById('search_filter_album');

    if (!searchButton || !searchInput || !searchResults) {
      logDebug('Missing elements on the page. Ensure IDs are correct.', {
        searchButton, searchInput, searchResults, artistFilter, trackFilter, albumFilter,
      });
    }

    /** ---- Search button ---- */
    if (searchButton) {
      searchButton.addEventListener('click', async function () {
        const query = (searchInput?.value || '').trim();
        if (!query) {
          alert('Please enter a track or artist name.');
          return;
        }

        const filters = [];
        if (artistFilter?.checked) filters.push('artist');
        if (trackFilter?.checked) filters.push('track');
        if (albumFilter?.checked) filters.push('album');

        searchResults.innerHTML = '<p>Searching...</p>';

        try {
          let token;
          try {
            token = await window.bspfyAuth.ensureAccessToken();
          } catch (_) {
            await window.bspfyAuth.startAuthPopup();
            token = await window.bspfyAuth.ensureAccessToken();
          }

          const response = await fetch(`${ajaxUrl}?action=search_spotify_tracks`, {
            method: 'POST',
            headers: {
              'Authorization': `Bearer ${token}`,
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
              query: query,
              type: filters.join(','),
            }),
            credentials: 'include',
            cache: 'no-store'
          });

          const responseData = await response.json();
          logDebug('Spotify API Response (admin search):', responseData);

          if (responseData.success) {
            renderTracks(responseData.data.tracks);
          } else {
            searchResults.innerHTML = `<p>Error: ${responseData.message || 'No tracks found.'}</p>`;
            console.error('Error fetching tracks:', responseData);
          }
        } catch (error) {
          searchResults.innerHTML = '<p>An error occurred while searching for tracks.</p>';
          console.error('Error during Spotify search:', error);
        }
      });
    }

    /** ---- Render search results ---- */
    function renderTracks(tracks) {
      searchResults.innerHTML = '';

      tracks.forEach(track => {
        const trackElement = document.createElement('div');
        trackElement.classList.add('track-result');
        trackElement.innerHTML = `
          <img src="${track.album.images?.[0]?.url || ''}" alt="${track.album.name}">
          <div class="track-details">
            <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${track.artists?.[0]?.name || ''}</div>
            <div class="track-details-album track-details-space"><strong>Album:</strong> ${track.album.name}</div>
            <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ${track.name}</div>
          </div>
          <div class="track-actions">
            <div class="track-actions-preview-button" data-uri="${track.uri}">Play</div>
            <div class="track-actions-add-track-button" data-track='${JSON.stringify(track)}'>Add</div>
          </div>
        `;
        searchResults.appendChild(trackElement);

        // Play
        trackElement.querySelector('.track-actions-preview-button').addEventListener('click', function () {
          const trackUri = this.getAttribute('data-uri');
          if (trackUri) {
            playTrack(trackUri);
            togglePlayButton(this);
          } else {
            alert('No track URI available.');
          }
        });

        // Add
        trackElement.querySelector('.track-actions-add-track-button').addEventListener('click', function () {
          const trackData = JSON.parse(this.getAttribute('data-track'));
          addTrackToPlaylist(trackData);
        });
      });
    }

    /** ---- Add/remove to playlist (admin meta box) ---- */
    function addTrackToPlaylist(track) {
      const playlistTracksInput = document.getElementById('playlist_tracks');
      const currentTracks = JSON.parse(playlistTracksInput.value || '[]');
      currentTracks.push(track);
      playlistTracksInput.value = JSON.stringify(currentTracks);

      const playlistList = document.getElementById('playlist_tracks_list');
      const trackItem = document.createElement('div');
      trackItem.classList.add('bspfy-track-item', 'bspfy-new-track');
      trackItem.innerHTML = `
        <img src="${track.album.images?.[0]?.url || ''}" alt="${track.album.name}">
        <div class="track-details">
          <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${track.artists?.[0]?.name || ''}</div>
          <div class="track-details-album track-details-space"><strong>Album:</strong> ${track.album.name}</div>
          <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ${track.name}</div>
        </div>
        <div class="track-actions">
          <div class="track-actions-preview-button" data-uri="${track.uri}">Play</div>
          <button type="button" class="bspfy-remove-button" data-track-id="${track.id}">Remove</button>
        </div>
      `;
      playlistList.appendChild(trackItem);

      trackItem.querySelector('.track-actions-preview-button').addEventListener('click', function () {
        const trackUri = this.getAttribute('data-uri');
        if (trackUri) {
          playTrack(trackUri);
          togglePlayButton(this);
        } else {
          alert('No track URI available.');
        }
      });

      trackItem.querySelector('.bspfy-remove-button').addEventListener('click', function () {
        const trackId = this.getAttribute('data-track-id');
        const updatedTracks = currentTracks.filter(t => t.id !== trackId);
        playlistTracksInput.value = JSON.stringify(updatedTracks);
        trackItem.remove();
      });
    }

    // Global remove handler (for eksisterende items)
    document.addEventListener('click', function (event) {
      if (event.target.classList.contains('bspfy-remove-button')) {
        const trackId = event.target.getAttribute('data-track-id');
        const trackItem = event.target.closest('.bspfy-track-item');

        if (trackId && trackItem) {
          trackItem.remove();
          const playlistTracksInput = document.getElementById('playlist_tracks');
          const currentTracks = JSON.parse(playlistTracksInput.value || '[]');
          const updatedTracks = currentTracks.filter(track => track.id !== trackId);
          playlistTracksInput.value = JSON.stringify(updatedTracks);
        }
      }
    });

    // Attach play listeners to saved tracks (rendered by PHP)
    function attachPlayListenersToSavedTracks() {
      const savedTrackButtons = document.querySelectorAll('.bspfy-track-item .track-actions-preview-button');
      savedTrackButtons.forEach(button => {
        button.addEventListener('click', function () {
          const trackUri = this.getAttribute('data-uri');
          if (trackUri) {
            playTrack(trackUri);
            togglePlayButton(this);
          } else {
            alert('No track URI available.');
          }
        });
      });
    }
    attachPlayListenersToSavedTracks();


    /** ---- Auth UI i metabox ---- */
    const authContainer = document.querySelector('.oauth-container');

    function showAuthButton() {
      if (!authContainer) return;
      authContainer.innerHTML = `
        <div id="spotify-auth-button" class="bspfy-button">
          <span class="btspfy-button-text">Authenticate with Spotify</span>
          <span class="btspfy-button-icon-divider btspfy-button-icon-divider-right">
            <i class="fa-spotify fab" aria-hidden="true"></i>
          </span>
        </div>
      `;
      document.getElementById('spotify-auth-button')?.addEventListener('click', async () => {
        try {
          await window.bspfyAuth.startAuthPopup();
          await updateAuthStatus();
        } catch (e) { /* noop */ }
      });
    }

    function showAuthenticatedStatus(spotifyName) {
      if (!authContainer) return;
      authContainer.innerHTML = `
        <div id="spotify-auth-status" class="bspfy-authenticated bspfy-button">
          <span class="btspfy-button-text">User: ${spotifyName}</span>
          <span class="btspfy-button-icon-divider btspfy-button-icon-divider-right">
            <i class="fa-spotify fab" aria-hidden="true"></i>
          </span>
        </div>
      `;
    }

    async function updateAuthStatus() {
      try {
        const data = await window.bspfyAuth.fetchJSON(`${window.location.origin}/wp-json/bspfy/v1/oauth/token`);
        if (data.authenticated && data.access_token) {
          const meRes = await fetch('https://api.spotify.com/v1/me', {
            headers: { Authorization: `Bearer ${data.access_token}` }
          });
          const me = meRes.ok ? await meRes.json() : null;
          const name = me?.display_name || 'Authenticated';
          showAuthenticatedStatus(name);
          saveSpotifyUserName(name);
        } else {
          showAuthButton();
        }
      } catch (e) {
        showAuthButton();
      }
    }

    function saveSpotifyUserName(spotifyName) {
      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_spotify_user_name',
          spotify_user_name: spotifyName,
        }),
        credentials: 'include',
        cache: 'no-store'
      })
        .then(r => r.json())
        .then(d => logDebug('Spotify username saved via AJAX:', d))
        .catch(err => logDebug('Error saving Spotify username via AJAX:', err));
    }

    // Init auth status på load (ingen popup)
    updateAuthStatus();
  });


  /** ----------------------------------------------------------------
   *  Små UI helpers
   *  ---------------------------------------------------------------- */
  function togglePlayButton(button) {
    const isPlaying = button.textContent === 'Pause';
    button.textContent = isPlaying ? 'Resume' : 'Pause';
    document.querySelectorAll('.track-actions-preview-button').forEach(btn => {
      if (btn !== button) btn.textContent = 'Play';
    });
  }

})(jQuery);
