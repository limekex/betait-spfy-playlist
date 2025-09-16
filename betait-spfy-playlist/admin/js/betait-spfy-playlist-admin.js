(function ($) {
  'use strict';

  // Avoid AnthemError from SDK: define global callback early
  window.__spotifySDKReady = false;
  window.onSpotifyWebPlaybackSDKReady = function () {
    window.__spotifySDKReady = true;
  };

  /** ----------------------------------------------------------------
   *  Helpers: debug, encoding, DOM-safety
   *  ---------------------------------------------------------------- */
  const debugEnabled = window.bspfyDebug?.debug || false;
  const ajaxUrl = window.bspfyDebug?.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php';
  const ajaxNonce = window.bspfyDebug?.ajax_nonce || ''; // <-- from PHP (wp_localize_script)

  function redactBearer(str) {
    try {
      const s = String(str);
      return s.replace(/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [REDACTED]');
    } catch (e) {
      return str;
    }
  }

  function logDebug(message, data = null) {
    if (!debugEnabled) return;
    try {
      console.log(`[BeTA iT - Spfy Playlist Debug] ${message}`);
      if (data != null) {
        if (typeof data === 'string') console.log(redactBearer(data));
        else console.log(data);
      }
    } catch (_) {}
  }

  function decodeUnicodeEscapes(s) {
    if (typeof s !== 'string' || s.indexOf('\\u') === -1) return s;
    try {
      // Safely decode "\uXXXX" sequences into real Unicode
      return JSON.parse('"' + s.replace(/"/g, '\\"') + '"');
    } catch (e) {
      return s;
    }
  }
  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  function safeText(s) {
    return escapeHtml(decodeUnicodeEscapes(s));
  }

  /** ----------------------------------------------------------------
   *  PKCE-auth client (WP REST: /wp-json/bspfy/v1/oauth/*)
   *  ---------------------------------------------------------------- */
  window.bspfyAuth = (function () {
    const base = `${(window.bspfyDebug?.rest_root || (window.location.origin + '/wp-json')).replace(/\/$/, '')}/bspfy/v1/oauth`;
    let inflight = null; // single-flight for token

    async function fetchJSON(url, opts = {}) {
      const headers = Object.assign(
        {},
        (opts.headers || {}),
        (window.bspfyDebug?.rest_nonce ? { 'X-WP-Nonce': window.bspfyDebug.rest_nonce } : {})
      );

      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        ...opts,
        headers
      });
      const text = await res.text();
      let json = {};
      try { json = JSON.parse(text); } catch (_){}

      if (!res.ok) {
        const msg = json?.message || json?.error || res.statusText || 'Request failed';
        const err = new Error(msg);
        err.status = res.status;
        err.body = json;
        throw err;
      }
      return json;
    }

// ensureAccessToken
async function ensureAccessToken({ interactive = false } = {}) {
  // Single-flight: share the same promise across parallel calls
  if (inflight) return inflight;
  inflight = (async () => {
    try {
      const data = await fetchJSON(`${base}/token`);
      if (data.authenticated && data.access_token) return data.access_token;
      if (!interactive) throw new Error('not-authenticated');
    } catch (e) {
      if (!interactive) throw e;
    }

    // Interactive round (popup) if needed
    const ov = window.bspfyOverlay; ov && ov.show({ title: 'Connecting to Spotify…', reason: 'auth' });
    try {
      await startAuthPopup();
      const data2 = await fetchJSON(`${base}/token`);
      if (data2.authenticated && data2.access_token) { ov && ov.hide(); return data2.access_token; }
      throw new Error('auth-failed-after-popup');
    } finally {
      ov && ov.hide();
    }
  })().finally(() => { inflight = null; });
  return inflight;
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
        const top  = window.screenY + (window.outerHeight - h) / 2;
        const popup = window.open(auth.authorizeUrl, 'bspfy-auth', `width=${w},height=${h},left=${left},top=${top}`);

        if (!popup) return reject(new Error('POPUP_BLOCKED'));

        let closedCheck = null;
        const handler = (ev) => {
          if (ev.origin !== window.location.origin) return;
          if (ev.data && ev.data.type === 'bspfy-auth' && ev.data.success) {
            window.removeEventListener('message', handler);
            if (closedCheck) clearInterval(closedCheck);
            try { popup.close(); } catch (_) {}
            resolve(true);
          }
        };
        window.addEventListener('message', handler);

        // Fallback if popup is closed manually
        closedCheck = setInterval(() => {
          try {
            if (!popup || popup.closed) {
              clearInterval(closedCheck);
              window.removeEventListener('message', handler);
              reject(new Error('popup-closed'));
            }
          } catch (_) {}
        }, 1000);
      });
    }

    return { ensureAccessToken, startAuthPopup, fetchJSON };
  })();


  /** ----------------------------------------------------------------
   *  Playlist hidden JSON helpers
   *  ---------------------------------------------------------------- */
  function getPlaylistInput() {
    return document.getElementById('playlist_tracks');
  }
  function getPlaylistArray() {
    try { return JSON.parse(getPlaylistInput()?.value || '[]'); }
    catch (_) { return []; }
  }
  function setPlaylistArray(arr) {
    const el = getPlaylistInput();
    if (el) el.value = JSON.stringify(arr);
  }
  function isTrackInPlaylist(id) {
    return getPlaylistArray().some(t => t.id === id);
  }

  function markAddBtnAsAdded(btn) {
    btn.classList.add('is-added');
    const ic = btn.querySelector('i');
    if (ic) { ic.classList.remove('fa-plus'); ic.classList.add('fa-check'); }
    btn.setAttribute('aria-label', 'Remove track');
  }
  function markAddBtnAsNotAdded(btn) {
    btn.classList.remove('is-added');
    const ic = btn.querySelector('i');
    if (ic) { ic.classList.remove('fa-check'); ic.classList.add('fa-plus'); }
    btn.setAttribute('aria-label', 'Add track');
  }

  function syncAddBtnState(btn, trackId) {
    if (isTrackInPlaylist(trackId)) markAddBtnAsAdded(btn);
    else markAddBtnAsNotAdded(btn);
  }


  /** ----------------------------------------------------------------
   *  Spotify Web Playback SDK in admin (preview button in metabox)
   *  ---------------------------------------------------------------- */
  let spotifyPlayer;
  let deviceId;
  let initPromise = null; // race-guard
  let currentTrackUri = null;
  let isPlaying = false;

// initializeSpotifyPlayer
async function initializeSpotifyPlayer() {
  if (initPromise) return initPromise;
  const ov = window.bspfyOverlay; ov && ov.show({ title: 'Preparing Web Player…', reason: 'sdk-init' });
  initPromise = (async () => {
    // Wait for SDK global to exist and ready flag to be true
    const t0 = Date.now();
    while (
      !window.Spotify ||
      (typeof window.__spotifySDKReady !== 'undefined' && !window.__spotifySDKReady)
    ) {
      if (Date.now() - t0 > 10000) throw new Error('Spotify SDK load timeout');
      await new Promise(r => setTimeout(r, 100));
    }

    // Ensure we have a token (may trigger popup)
    await window.bspfyAuth.ensureAccessToken({ interactive: true });

    spotifyPlayer = new Spotify.Player({
      name: 'BeTA iT Web Player (Admin)',
      getOAuthToken: async cb => {
        try {
          const t = await window.bspfyAuth.ensureAccessToken({ interactive: false });
          cb(t);
        } catch (e) {
          try {
            const t2 = await window.bspfyAuth.ensureAccessToken({ interactive: true });
            cb(t2);
          } catch (e2) {
            console.error('Failed to refresh token for SDK', e2);
          }
        }
      },
      volume: 0.5
    });

    spotifyPlayer.addListener('ready', ({ device_id }) => {
      deviceId = device_id;
      logDebug('Spotify Player ready', device_id);
    });

    spotifyPlayer.addListener('not_ready', ({ device_id }) => {
      console.warn('Spotify Player not ready with Device ID:', device_id);
    });

    spotifyPlayer.addListener('player_state_changed', state => {
      if (!state) return;
      logDebug('player_state_changed (admin)', state);
      isPlaying = !state.paused;
      currentTrackUri = state?.track_window?.current_track?.uri || currentTrackUri;
      syncPlayIcons();
    });

    await spotifyPlayer.connect();

    // Wait for deviceId from 'ready'
    const t1 = Date.now();
    while (!deviceId) {
      if (Date.now() - t1 > 8000) throw new Error('Device init timeout');
      await new Promise(r => setTimeout(r, 100));
    }
    ov && ov.hide();
  })().finally(() => { ov && ov.hide(); /* keep initPromise to prevent double-init */ });
  return initPromise;
}


  function setPlayIcon(btn, playing) {
    const i = btn.querySelector('i');
    if (!i) return;
    i.classList.toggle('fa-play', !playing);
    i.classList.toggle('fa-pause', playing);
    btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
  }
  function syncPlayIcons() {
    document.querySelectorAll('.track-actions-preview-button').forEach(btn => {
      const uri = btn.getAttribute('data-uri') || '';
      setPlayIcon(btn, isPlaying && currentTrackUri && uri === currentTrackUri);
    });
  }

  async function playTrack(trackUri) {
    try {
      const token = await window.bspfyAuth.ensureAccessToken({ interactive: true });
      if (!spotifyPlayer || !deviceId) {
        await initializeSpotifyPlayer();
      }

      if (currentTrackUri === trackUri) {
        await spotifyPlayer.togglePlay();
        return;
      }

      currentTrackUri = trackUri;
      isPlaying = true;
      syncPlayIcons();

      const doPlay = async (bearer) => {
        return fetch(`https://api.spotify.com/v1/me/player/play?device_id=${encodeURIComponent(deviceId)}`, {
          method: 'PUT',
          headers: {
            'Authorization': `Bearer ${bearer}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ uris: [trackUri] }),
        });
      };

      let res = await doPlay(token);

      // Simple retry logic
      if (res.status === 401) {
        const t2 = await window.bspfyAuth.ensureAccessToken({ interactive: true });
        res = await doPlay(t2);
      } else if (res.status === 403) {
        // Possibly NO_PREMIUM or device not active
        if (window.bspfyOverlay?.fail) {
          window.bspfyOverlay.fail({
            title: 'Playback blocked',
            message: 'Requires Spotify Premium or an active device.',
            reason: 'playback-403'
          });
        } else {
          alert('Playback not allowed (e.g. requires Spotify Premium or active device).');
        }
      }

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
   *  DOM Ready – Admin UI (search + metabox)
   *  ---------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
  const clientId = window.bspfyDebug?.client_id || '';
  if (!clientId) console.error('Spotify Client ID is missing. Please configure it in the plugin settings.');
  logDebug('Admin JS loaded, client_id:', clientId);

  // Elements
  const searchButton  = document.getElementById('search_tracks_button');
  const searchInput   = document.getElementById('playlist_tracks_search');
  const searchResults = document.getElementById('track_search_results');
  const artistFilter  = document.getElementById('search_filter_artist');
  const trackFilter   = document.getElementById('search_filter_track');
  const albumFilter   = document.getElementById('search_filter_album');
  const limitInput    = document.getElementById('search_limit'); // optional, 1–50
  const healthBtn = document.getElementById('bspfy-check-health');
  const healthOut = document.getElementById('bspfy-health-output');
  async function toJson(x){ try { return JSON.stringify(x, null, 2); } catch { return String(x); } }

  if (!searchButton || !searchInput || !searchResults) {
    logDebug('Missing elements on the page. Ensure IDs are correct.', {
      searchButton, searchInput, searchResults, artistFilter, trackFilter, albumFilter, limitInput,
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
      if (trackFilter?.checked)  filters.push('track');
      if (albumFilter?.checked)  filters.push('album');

      const limit = Math.max(1, Math.min(50, parseInt(limitInput?.value || '20', 10) || 20));

      searchResults.innerHTML = '<p>Searching...</p>';

      // Overlay alias must be declared outside try/finally
      const ov = window.bspfyOverlay; 
      ov && ov.show({ title: 'Searching Spotify…', reason: 'admin-search' });

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
            limit: String(limit),
          }),
          credentials: 'include',
          cache: 'no-store'
        });

        const responseData = await response.json().catch(() => ({}));
        logDebug('Spotify API Response (admin search):', responseData);

        // Improved error handling based on HTTP status
        if (!response.ok) {
          const msg = responseData?.data?.message || responseData?.message || `Search failed (${response.status}).`;
          searchResults.innerHTML = `<p>Error: ${escapeHtml(msg)}</p>`;
          return;
        }

        const tracks = responseData?.data?.tracks || responseData?.tracks;
        if (responseData.success && Array.isArray(tracks)) {
          renderTracks(tracks);
        } else {
          searchResults.innerHTML = `<p>${escapeHtml(responseData?.message || 'No tracks found.')}</p>`;
        }
      } catch (error) {
        searchResults.innerHTML = '<p>An error occurred while searching for tracks.</p>';
        console.error('Error during Spotify search:', error);
      } finally {
        ov && ov.hide();
      }
    });

    // Enter in search field triggers search (prevents post save)
    if (searchInput && searchButton) {
      ['keydown','keypress'].forEach(ev => {
        searchInput.addEventListener(ev, function(e){
          const key = e.key || e.keyCode;
          if (key === 'Enter' || key === 13) {
            e.preventDefault();
            e.stopPropagation();
            searchButton.click();
          }
        });
      });
    }
  }

  /** ---- Render search results ---- */
  function renderTracks(tracks) {
    searchResults.innerHTML = '';

    const playlistInput = document.getElementById('playlist_tracks');
    const getList = () => {
      try { return JSON.parse(playlistInput?.value || '[]'); }
      catch (_) { return []; }
    };
    const setList = (arr) => { if (playlistInput) playlistInput.value = JSON.stringify(arr); };
    const inList = (id) => getList().some(t => t.id === id);

    const markAdded = (btn) => {
      btn.classList.add('is-added');
      const ic = btn.querySelector('i');
      if (ic) { ic.classList.remove('fa-plus'); ic.classList.add('fa-check'); }
      btn.setAttribute('aria-label', 'Remove track');
    };
    const markNotAdded = (btn) => {
      btn.classList.remove('is-added');
      const ic = btn.querySelector('i');
      if (ic) { ic.classList.remove('fa-check'); ic.classList.add('fa-plus'); }
      btn.setAttribute('aria-label', 'Add track');
    };

    tracks.forEach(track => {
      const tArtist = safeText(track?.artists?.[0]?.name || '');
      const tAlbum  = safeText(track?.album?.name || '');
      const tName   = safeText(track?.name || '');
      const tImg    = track?.album?.images?.[0]?.url || '';

      const trackElement = document.createElement('div');
      trackElement.classList.add('track-result');
      trackElement.setAttribute('data-track-id', track.id);

      trackElement.innerHTML = `
        <img src="${escapeHtml(tImg)}" alt="${tAlbum}">
        <div class="track-details">
          <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${tArtist}</div>
          <div class="track-details-album track-details-space"><strong>Album:</strong> ${tAlbum}</div>
          <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ${tName}</div>
        </div>
        <div class="track-actions">
          <button type="button" class="bspfy-icon-btn track-actions-preview-button" data-uri="${escapeHtml(track.uri || '')}" aria-label="Play/Pause preview">
            <i class="fa-solid fa-play" aria-hidden="true"></i>
          </button>
          <button
            type="button"
            class="bspfy-icon-btn track-actions-add-track-button"
            data-track-id="${escapeHtml(track.id || '')}"
            aria-label="Add track">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
          </button>
        </div>
      `;
      searchResults.appendChild(trackElement);

      // Init state of add-button
      const addBtn = trackElement.querySelector('.track-actions-add-track-button');
      if (addBtn) (inList(track.id) ? markAdded(addBtn) : markNotAdded(addBtn));

      // Preview
      trackElement.querySelector('.track-actions-preview-button')?.addEventListener('click', function () {
        const trackUri = this.getAttribute('data-uri');
        if (trackUri) { playTrack(trackUri); }
      });

      // Toggle add/remove
      addBtn?.addEventListener('click', function () {
        const tid = track.id;

        if (inList(tid)) {
          const updated = getList().filter(t => t.id !== tid);
          setList(updated);
          const row = document.querySelector(`#playlist_tracks_list .bspfy-track-item[data-track-id="${CSS.escape(tid)}"]`);
          if (row) row.remove();
          markNotAdded(this);
        } else {
          addTrackToPlaylist(track);
          markAdded(this);
        }
      });
    });
  }

  function removeTrackFromPlaylist(trackId) {
    const updated = getPlaylistArray().filter(t => t.id !== trackId);
    setPlaylistArray(updated);

    const row = document.querySelector(`#playlist_tracks_list .bspfy-track-item[data-track-id="${CSS.escape(trackId)}"]`);
    if (row) row.remove();

    const addBtn = document.querySelector(`.track-actions-add-track-button[data-track-id="${CSS.escape(trackId)}"]`);
    if (addBtn) markAddBtnAsNotAdded(addBtn);
  }

  /** ---- Add/remove to playlist (admin meta box) ---- */
  function showInlineNotice(msg) {
    let n = document.getElementById('bspfy-inline-notice');
    if (!n) {
      n = document.createElement('div');
      n.id = 'bspfy-inline-notice';
      n.style.margin = '8px 0';
      n.style.color = '#d63638';
      searchResults.parentNode.insertBefore(n, searchResults);
    }
    n.textContent = msg;
    setTimeout(() => { if (n) n.textContent = ''; }, 2500);
  }
  function syncAllSearchAddButtons() {
    document.querySelectorAll('.track-actions-add-track-button').forEach(btn => {
      const tId = btn.getAttribute('data-track-id');
      if (tId) syncAddBtnState(btn, tId);
    });
  }

  function addTrackToPlaylist(track) {
    const playlistTracksInput = document.getElementById('playlist_tracks');
    const currentTracks = JSON.parse(playlistTracksInput.value || '[]');

    if (currentTracks.some(t => t.id === track.id)) {
      showInlineNotice('Already in list');
      return;
    }

    currentTracks.push(track);
    playlistTracksInput.value = JSON.stringify(currentTracks);

    const tArtist = safeText(track?.artists?.[0]?.name || '');
    const tAlbum  = safeText(track?.album?.name || '');
    const tName   = safeText(track?.name || '');
    const tImg    = track?.album?.images?.[0]?.url || '';

    const playlistList = document.getElementById('playlist_tracks_list');
    const trackItem = document.createElement('div');
    trackItem.classList.add('bspfy-track-item', 'bspfy-new-track');
    trackItem.setAttribute('data-track-id', track.id);
    trackItem.innerHTML = `
      <img src="${escapeHtml(tImg)}" alt="${tAlbum}">
      <div class="track-details">
        <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${tArtist}</div>
        <div class="track-details-album track-details-space"><strong>Album:</strong> ${tAlbum}</div>
        <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ${tName}</div>
      </div>
      <div class="track-actions">
        <button type="button" class="bspfy-icon-btn track-actions-preview-button" data-uri="${escapeHtml(track.uri || '')}" aria-label="Play/Pause preview">
          <i class="fa-solid fa-play" aria-hidden="true"></i>
        </button>
        <button type="button" class="bspfy-icon-btn bspfy-remove-button" data-track-id="${escapeHtml(track.id || '')}" aria-label="Remove track">
          <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
        </button>
      </div>
    `;
    playlistList.appendChild(trackItem);

    trackItem.querySelector('.track-actions-preview-button')?.addEventListener('click', function () {
      const trackUri = this.getAttribute('data-uri');
      if (trackUri) { playTrack(trackUri); }
    });

    document.addEventListener('click', function (event) {
      const btn = event.target.closest('.bspfy-remove-button');
      if (!btn) return;
      const trackId = btn.getAttribute('data-track-id');
      if (trackId) removeTrackFromPlaylist(trackId);
    });

    syncAllSearchAddButtons();
  }

  // Global remove handler (existing items)
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
        } else {
          alert('No track URI available.');
        }
      });
    });
  }
  attachPlayListenersToSavedTracks();


  /** ---- Auth UI in metabox ---- */
  const authContainer = document.querySelector('.oauth-container');

  function showAuthButton() {
    if (!authContainer) return;
    authContainer.innerHTML = `
      <div id="spotify-auth-button" class="bspfy-button">
        <span class="btspfy-button-text">Authenticate with Spotify</span>
        <span class="btspfy-button-icon-divider btspfy-button-icon-divider-right">
          <i class="fa-brands fa-spotify" aria-hidden="true"></i>
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
    const name = safeText(spotifyName || 'Authenticated');
    authContainer.innerHTML = `
      <div id="spotify-auth-status" class="bspfy-authenticated bspfy-button">
        <span class="btspfy-button-text">User: ${name}</span>
        <span class="btspfy-button-icon-divider btspfy-button-icon-divider-right">
          <i class="fa-brands fa-spotify" aria-hidden="true"></i>
        </span>
      </div>
    `;
  }

  // updateAuthStatus
  async function updateAuthStatus() {
    const ov = window.bspfyOverlay; ov && ov.show({ title: 'Checking Spotify status…', reason: 'auth-status' });
    try {
      const data = await window.bspfyAuth.fetchJSON(
        `${(window.bspfyDebug?.rest_root || (window.location.origin + '/wp-json')).replace(/\/$/, '')}/bspfy/v1/oauth/token`
      );
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
    } finally {
      ov && ov.hide();
    }
  }

  function saveSpotifyUserName(spotifyName) {
    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'save_spotify_user_name',
        spotify_user_name: spotifyName,
        nonce: window.bspfyDebug?.ajax_nonce || '', // required by server
      }),
      credentials: 'include',
      cache: 'no-store'
    })
      .then(r => r.json().catch(() => ({})))
      .then(d => logDebug('Spotify username saved via AJAX:', d))
      .catch(err => logDebug('Error saving Spotify username via AJAX:', err));
  }

  // Init auth status on load (non-interactive)
  updateAuthStatus();

  
  if (healthBtn && healthOut) {
    healthBtn.addEventListener('click', async () => {
      const ov = window.bspfyOverlay; ov && ov.show({ title: 'Checking OAuth health…', reason: 'health' });
      healthOut.textContent = '';
      try {
        const root = (window.bspfyDebug?.rest_root || (window.location.origin + '/wp-json')).replace(/\/$/, '');
        const res  = await fetch(`${root}/bspfy/v1/oauth/health?dbg=1`, { credentials:'include', cache:'no-store' });
        const json = await res.json().catch(() => ({}));
        healthOut.textContent = await toJson(json);
      } catch (e) {
        healthOut.textContent = 'Error contacting health endpoint.';
      } finally {
        ov && ov.hide();
      }
    });
  }

});



  /** ----------------------------------------------------------------
   *  Small UI helpers
   *  ---------------------------------------------------------------- */
  function activateTab(key) {
    $('.bspfy-tab').removeClass('is-active').filter('[data-tab="'+key+'"]').addClass('is-active');
    $('.bspfy-tabpanel').removeClass('is-active');
    $('#bspfy-tab-' + key).addClass('is-active');
    if (history.replaceState) history.replaceState(null, '', '#'+key);
  }

  $('.bspfy-tab').on('click', function(){
    activateTab($(this).data('tab'));
  });

  // Init from hash
  var fromHash = (location.hash||'').replace('#','');
  if (fromHash && $('#bspfy-tab-'+fromHash).length) activateTab(fromHash);
  else activateTab('general');

  // Volume sync range <-> number
  var $r = $('#bspfy_default_volume_range');
  var $n = $('#bspfy_default_volume');

  function clamp(v){ v = parseFloat(v||0); if (isNaN(v)) v=0; return Math.max(0, Math.min(100, v)); }

  $r.on('input change', function(){
    $n.val( clamp(this.value) );
  });

  $n.on('input change', function(){
    $n.val( clamp(this.value) );
    $r.val( clamp(this.value) );
  });

})(jQuery);
