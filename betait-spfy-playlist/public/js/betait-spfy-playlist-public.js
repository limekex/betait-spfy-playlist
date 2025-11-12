(function ($) {
  'use strict';

  /** --------------------------------------------------------------
   *  PKCE-auth klient (WP REST: /wp-json/bspfy/v1/oauth/*)
   *  -------------------------------------------------------------- */

  // Hindre AnthemError hvis SDK prøver å kalle denne:
  window.onSpotifyWebPlaybackSDKReady = window.onSpotifyWebPlaybackSDKReady || function () { /* no-op */ };

  window.bspfyAuth = (function () {
    const base = `${window.location.origin}/wp-json/bspfy/v1/oauth`;

    async function fetchJSON(url, opts = {}) {
      const res = await fetch(url, { credentials: 'include', cache: 'no-store', ...opts });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw Object.assign(new Error(json.error || res.statusText), { status: res.status, json });
      return json;
    }

    async function ensureAccessToken() {
      const data = await fetchJSON(`${base}/token`);
      if (data.authenticated && data.access_token) return data.access_token;
      throw new Error('not-authenticated');
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
        const popup = window.open(auth.authorizeUrl, 'bspfy-auth',
          `width=${w},height=${h},left=${left},top=${top}`);

        const handler = (ev) => {
          if (ev.origin !== window.location.origin) return;
          if (ev.data && ev.data.type === 'bspfy-auth' && ev.data.success) {
            window.removeEventListener('message', handler);
            try { popup && popup.close(); } catch {}
            resolve(true);
          }
        };
        window.addEventListener('message', handler);

        const checkClosed = setInterval(() => {
          try {
            if (!popup || popup.closed) {
              clearInterval(checkClosed);
              reject(new Error('popup-closed'));
            }
          } catch {}
        }, 1000);
      });
    }

    return { ensureAccessToken, startAuthPopup, fetchJSON };
  })();

/** --------------------------------------------------------------
 *  Global Spotify fetch: single-flight token + concurrency gate
 *  -------------------------------------------------------------- */
let __bspfyTokPromise = null;
async function ensureTokenSingleflight() {
  if (!__bspfyTokPromise) {
    __bspfyTokPromise = window.bspfyAuth.ensureAccessToken()
      .finally(() => { __bspfyTokPromise = null; });
  }
  return __bspfyTokPromise;
}

// Maks samtidige Spotify-kall fra klient
const BSPFY_GATE_MAX = 4;
const __bspfyGateQueue = [];
async function gated(fn) {
  while (__bspfyGateQueue.length >= BSPFY_GATE_MAX) await __bspfyGateQueue[0];
  let done; const p = new Promise(r => (done = r));
  __bspfyGateQueue.push(p);
  try { return await fn(); }
  finally { done(); __bspfyGateQueue.shift(); }
}

// Jittered backoff (for 429)
const backoff = (ms) => new Promise(r => setTimeout(r, ms + Math.random()*ms));

// Hoved-wrapper for Spotify Web API
async function bspfyFetch(url, opts = {}, attempt = 0) {
  return gated(async () => {
    const token = await ensureTokenSingleflight();
    const headers = { 'Authorization': `Bearer ${token}`, ...(opts.headers||{}) };
    const res = await fetch(url, { ...opts, headers });

    if (res.status === 401 && attempt < 1) {
      await backoff(150);
      return bspfyFetch(url, opts, attempt + 1);
    }
    if (res.status === 429) {
      const ra = Number(res.headers.get('Retry-After') || 1);
      await backoff(ra * 1000);
      return bspfyFetch(url, opts, attempt + 1);
    }
    if (!res.ok) {
      let msg = res.statusText;
      try { const j = await res.json(); msg = j?.error?.message || msg; } catch {}
      const err = new Error(msg); err.status = res.status; throw err;
    }
    try { return await res.json(); } catch { return {}; }
  });
}


    /** --------------------------------------------------------------
   *  MINI UI: volum + device-velger (bspfyMini) – med slide in/out
   *  -------------------------------------------------------------- */

  const bspfyApi = {
  devices: () => bspfyFetch('https://api.spotify.com/v1/me/player/devices'),
  mePlayer: () => bspfyFetch('https://api.spotify.com/v1/me/player'),
  transfer: (deviceId, play = true) =>
    bspfyFetch('https://api.spotify.com/v1/me/player', {
      method: 'PUT',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({ device_ids: [deviceId], play })
    }),
  setRemoteVolume: (deviceId, vol0to1) =>
    bspfyFetch(
      `https://api.spotify.com/v1/me/player/volume?device_id=${encodeURIComponent(deviceId)}&volume_percent=${Math.round(vol0to1*100)}`,
      { method: 'PUT' }
    ),
};


  function bspfyMiniTemplate() {
    return `
      <div class="bspfy-mini" id="bspfy-mini" aria-live="polite" aria-hidden="true">
        <button class="bspfy-mini-speaker" aria-label="Velg avspillingsenhet" title="Velg enhet">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path d="M3 9v6h4l5 4V5L7 9H3z"></path>
            <path d="M16.5 12a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4z"></path>
            <path d="M14 3.23v2.06a7.5 7.5 0 0 1 0 13.42v2.06c5-1.54 8-6.47 8-8.77s-3-7.23-8-8.77z"></path>
          </svg>
          <span class="bspfy-mini-device-label" aria-hidden="true">Denne nettleseren</span>
        </button>

        <input class="bspfy-mini-vol" type="range" min="0" max="1" step="0.01" value="0.5" aria-label="Volum" />

        <div class="bspfy-mini-devices" role="listbox" aria-label="Tilgjengelige enheter" hidden></div>
        <div class="bspfy-mini-toast" hidden></div>
      </div>
    `;
  }

  const bspfyDebounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };

  window.bspfyMini = {
    _player: null,
    _root: null, _list: null, _toastEl: null, _labelEl: null, _volEl: null,
    _localDeviceId: null, _selectedDeviceId: null,
    _hideT: null,
    _pollT: null,
    _volUserTouch: false,
    _localDeviceName: 'BeTA iT Web Player',


  init(player, mountEl, localDeviceId, localDeviceName) {
  this._player = player;
  this._localDeviceId = localDeviceId || null;
  this._selectedDeviceId = localDeviceId || null;
  this._localDeviceName = localDeviceName || this._localDeviceName;

  if (!mountEl) mountEl = document.getElementById('bspfy-pl1-player')
                  || document.getElementById('bspfy-pl1-player-controls')
                  || document.getElementById('bspfy-player')
                  || document.body;

  mountEl.insertAdjacentHTML('beforeend', bspfyMiniTemplate());
  this._root   = mountEl.querySelector('#bspfy-mini');
  this._list   = this._root.querySelector('.bspfy-mini-devices');
  this._toastEl= this._root.querySelector('.bspfy-mini-toast');
  this._labelEl= this._root.querySelector('.bspfy-mini-device-label');
  this._volEl  = this._root.querySelector('.bspfy-mini-vol');

  // Åpne/lukke enhetsmeny
  this._root.querySelector('.bspfy-mini-speaker').addEventListener('click', async () => {
    if (this._list.hidden) { await this._renderDevices(); this._list.hidden = false; }
    else { this._list.hidden = true; }
  });
  document.addEventListener('click', ev => {
    if (!this._root.contains(ev.target)) this._list.hidden = true;
  });

  // Volum (lokalt → SDK, fjern → Web API) m/ debounce + "user-touch" lås
  const setRemoteVol = bspfyDebounce(async (id, v) => {
    try { await bspfyApi.setRemoteVolume(id, v); }
    catch(e){ this._toast(`Kunne ikke sette volum (${e.status||''})`); }
  }, 180);

  let touchT;
  this._volEl.addEventListener('input', async (e) => {
    const v = Number(e.target.value);
    this._volUserTouch = true; clearTimeout(touchT);
    touchT = setTimeout(()=>{ this._volUserTouch = false; }, 400);

    if (this._selectedDeviceId && this._selectedDeviceId !== this._localDeviceId) {
      try { await bspfyApi.setRemoteVolume(this._selectedDeviceId, v); } catch(e){ this._toast(`Kunne ikke sette volum (${e.status||''})`); }
    } else if (this._player) {
      try { await this._player.setVolume(v); } catch {}
    }
  });

  // Start bakgrunnspolling ETTER at DOM-pekers er satt
  this._startPolling();
},


    // Vis ved playing, skjul med liten forsinkelse ved pause/stop
onState(state) {
  if (!this._root) return; // mini-UI ikke montert enda

  const isPlaying = !!state && state.paused === false;

  if (isPlaying) {
    clearTimeout(this._hideT);
    this._root.classList.add('bspfy-visible');
    this._root.setAttribute('aria-hidden', 'false');

    // sync volum når baren vises
    this._player?.getVolume().then(v => {
      if (typeof v === 'number') this._volEl.value = v;
    }).catch(()=>{});

    if (!this._selectedDeviceId && this._localDeviceId) {
      this._selectedDeviceId = this._localDeviceId;
    }
    if (this._labelEl && this._selectedDeviceId === this._localDeviceId) {
      this._labelEl.textContent = this._localDeviceName;
    }
  } else {
    clearTimeout(this._hideT);
    this._hideT = setTimeout(() => {
      this._root.classList.remove('bspfy-visible');
      this._root.setAttribute('aria-hidden', 'true');
    }, 150);
  }
},


async _renderDevices(silent = false) {
  if (!silent) this._list.innerHTML = '<button class="bspfy-mini-devices-row" disabled>Laster enheter…</button>';
  let data;
  try { data = await bspfyApi.devices(); }
  catch (e) {
    if (!silent) this._list.innerHTML = `<button class="bspfy-mini-devices-row bspfy-error" disabled>Kunne ikke hente enheter (${e.status||''})</button>`;
    return;
  }

  const devices = Array.isArray(data?.devices) ? data.devices : [];
  if (!devices.length) {
    if (!silent) this._list.innerHTML = `<button class="bspfy-mini-devices-row" disabled>Ingen enheter funnet. Åpne Spotify-appen.</button>`;
    return;
  }

  this._list.innerHTML = devices.map(d => {
    const isLocal  = d.id === this._localDeviceId;
    const selected = (this._selectedDeviceId || this._localDeviceId) === d.id;
    const active   = d.is_active ? ' (aktiv)' : '';
    const label    = isLocal ? (d.name || this._localDeviceName) : `${d.name || d.type}${active}`;
    return `<button class="bspfy-mini-devices-row${selected ? ' selected':''}" data-id="${d.id}" data-local="${isLocal ? '1':'0'}">
              <span class="bspfy-dot${d.is_active ? ' on':''}"></span>${label}
            </button>`;
  }).join('');

  this._list.querySelectorAll('.bspfy-mini-devices-row').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.currentTarget.getAttribute('data-id');
      const isLocal = e.currentTarget.getAttribute('data-local') === '1';
      try { await bspfyApi.transfer(id, true); }
      catch (err) {
        return this._toast(err.status === 403 ? 'Spotify Premium kreves for å bytte enhet.' : `Kunne ikke bytte enhet (${err.status||''})`);
      }
      this._selectedDeviceId = id;
      this._labelEl.textContent = isLocal ? (this._localDeviceName) : 'Annen enhet';
      this._toast(isLocal ? 'Spiller i denne nettleseren' : 'Overførte avspilling');
      this._list.hidden = true;
      this._list.querySelectorAll('.selected').forEach(x=>x.classList.remove('selected'));
      e.currentTarget.classList.add('selected');
    });
  });
},

// ←← LEGGER TIL POLLING-METODER HER →→
  _startPolling() {
    clearInterval(this._pollT);
    this._pollT = setInterval(()=>{ this._maybePoll(); }, 5000);
  },

  async _maybePoll() {
    if (!this._player || !this._list) return;

    const menuOpen = !this._list.hidden;
    const isRemote = this._selectedDeviceId && this._selectedDeviceId !== this._localDeviceId;

    // sjekk om vi spiller (kan feile i noen tilstander)
    let playing = false;
    try { const st = await this._player.getCurrentState(); playing = !!st && st.paused === false; } catch {}

    if (!(menuOpen || playing || isRemote)) return;

    // 1) Oppdater deviceliste stille
    try {
      const data = await bspfyApi.devices();
      const devices = Array.isArray(data?.devices) ? data.devices : [];
      const local = devices.find(d => d.id === this._localDeviceId);
      if (local && this._labelEl && this._selectedDeviceId === this._localDeviceId) {
        this._labelEl.textContent = local.name || this._localDeviceName;
      }
      if (!this._list.hidden) this._renderDevices(true);
    } catch {}

    // 2) Remote volum sync
    if (isRemote && !this._volUserTouch) {
      try {
        const me = await bspfyApi.mePlayer();
        const dev = me?.device;
        if (dev && dev.id === this._selectedDeviceId && typeof dev.volume_percent === 'number') {
          const v = Math.max(0, Math.min(100, dev.volume_percent)) / 100;
          this._volEl.value = String(v);
        }
      } catch {}
    }
  },

  _toast(msg) {
    if (!this._toastEl) return;
    this._toastEl.textContent = msg;
    this._toastEl.hidden = false;
    clearTimeout(this._toastEl._t);
    this._toastEl._t = setTimeout(()=>{ this._toastEl.hidden = true; }, 1600);
  }
};


  /** --------------------------------------------------------------
   *  Spotify Web Playback SDK – public
   *  -------------------------------------------------------------- */
    let spotifyPlayer;
    let deviceId;
    let currentTrackUri = null;
    let progressTimer = null;
    let isSeeking = false;
    let lastPlayback = null;      // for å detektere "ended"
    let autoAdvanceLock = false;  // hindrer dobbeltrigger


  async function initializeSpotifyPlayer() {
    const playerName = (window.bspfyPublic && bspfyPublic.player_name) || 'BeTA iT Web Player';
    const startVol   = (window.bspfyPublic && typeof bspfyPublic.default_volume === 'number') ? bspfyPublic.default_volume : 0.5;

    return new Promise(async (resolve, reject) => {
      try {
        if (!window.Spotify) {
          console.error('Spotify Web Playback SDK not loaded.');
          return reject('Spotify SDK not loaded.');
        }
        await window.bspfyAuth.ensureAccessToken();

        spotifyPlayer = new Spotify.Player({
          name: playerName,
          getOAuthToken: async cb => {
            try { cb(await window.bspfyAuth.ensureAccessToken()); }
            catch (e) { console.error('Failed to refresh token for SDK', e); }
          },
          volume: startVol
        });

        // 🔧 Viktig: eksponer også på window for seek-koden
        window.spotifyPlayer = spotifyPlayer;

        spotifyPlayer.addListener('ready', ({ device_id }) => {
            deviceId = device_id;
            console.log('Spotify Player ready with Device ID:', device_id);

            try {
              const mount =
                document.getElementById('bspfy-pl1-player') ||
                document.getElementById('bspfy-pl1-player-controls') ||
                document.getElementById('bspfy-player') ||
                document.body;

              const localName = (window.bspfyPublic && bspfyPublic.player_name) || 'BeTA iT Web Player';
              window.bspfyMini?.init(spotifyPlayer, mount, deviceId, localName);
              document.dispatchEvent(new CustomEvent('bspfy:player_ready', { detail: { deviceId: device_id } }));
            } catch (e) { console.warn('bspfyMini init feilet', e); }

            resolve();
          });

        spotifyPlayer.addListener('not_ready', ({ device_id }) => {
          console.error('Spotify Player not ready with Device ID:', device_id);
        });

        spotifyPlayer.addListener('player_state_changed', state => {
            if (!state) return;

            const isPlaying = !state.paused;
            const uri = state.track_window.current_track?.uri || currentTrackUri;

            currentTrackUri = uri;

            updatePlayIcon(uri, isPlaying);
            updateNowPlayingCover(uri);
            updateNowPlayingUI(state);

             window.bspfyMini?.onState(state);
            if (isPlaying) startProgressTimer();
            else           stopProgressTimer();
            });


        spotifyPlayer.connect();
      } catch (err) {
        console.error('initializeSpotifyPlayer failed', err);
        reject(err);
      }
    });
  }

  /** --------------------------------------------------------------
   *  UI helpers – ikon, cover, tekst, fremdrift
   *  -------------------------------------------------------------- */
  
  function getPlaylistUris() {
  const seen = new Set();
  return Array.from(document.querySelectorAll('.bspfy-play-icon[data-uri]'))
    .map(btn => btn.getAttribute('data-uri'))
    .filter(uri => uri && !seen.has(uri) && (seen.add(uri) || true));
}

  
  function updatePlayIcon(trackUri, isPlaying) {
    document.querySelectorAll('.bspfy-play-icon').forEach(btn => {
      const icon = btn.querySelector('i');
      btn.classList.remove('bspfy-playing');
      if (icon) { icon.classList.remove('fa-pause'); icon.classList.add('fa-play'); }
      if (btn.getAttribute('data-uri') === trackUri && isPlaying) {
        btn.classList.add('bspfy-playing');
        if (icon) { icon.classList.remove('fa-play'); icon.classList.add('fa-pause'); }
      }
    });

    const playerBtnIcon = document.querySelector('#bspfy-pl1-play-pause-button i');
    const trackBlock   = document.getElementById('bspfy-pl1-player-track');
    const albumArt     = document.getElementById('bspfy-pl1-album-art');
    const trackTime    = document.getElementById('bspfy-pl1-track-time');

    if (playerBtnIcon) {
      playerBtnIcon.classList.toggle('fa-pause', !!isPlaying);
      playerBtnIcon.classList.toggle('fa-play', !isPlaying);
    }
    [trackBlock, albumArt, trackTime].forEach(el => {
      if (!el) return;
      el.classList.toggle('bspfy-pl1-active', !!isPlaying);
    });
  }

  function updateNowPlayingCover(trackUri) {
    const btn = document.querySelector(`.bspfy-play-icon[data-uri="${trackUri}"]`);
    const coverUrl = btn?.closest('.bspfy-track-item')?.getAttribute('data-album-cover') || '';

    const mainCover  = document.getElementById('bspfy-now-playing-cover');
    const roundCover = document.getElementById('bspfy-pl1-_1');

    if (mainCover && coverUrl)  mainCover.src  = coverUrl;
    if (roundCover && coverUrl) roundCover.src = coverUrl;
    if (coverUrl) $('#bspfy-pl1-player-bg-artwork').css('background-image', `url(${coverUrl})`);
  }

  function updateNowPlayingUI(state) {
    if (!state?.track_window?.current_track) return;
    const track = state.track_window.current_track;

    $('#bspfy-pl1-album-name').text(track.album?.name || 'Unknown Album');
    $('#bspfy-pl1-track-name').text(track.name || 'Unknown Track');
    window.bspfyRefreshScroll(); // <- re-mål og aktiver scroll hvis overflow

    const total = (state.duration || 0) / 1000;
    const mm = Math.floor(total / 60);
    const ss = Math.floor(total % 60);
    $('#bspfy-pl1-track-length').text(`${mm}:${ss < 10 ? '0' : ''}${ss}`);
  }

  function startProgressTimer() {
    stopProgressTimer();
    progressTimer = setInterval(() => {
      if (!spotifyPlayer || isSeeking) return;
      spotifyPlayer.getCurrentState().then(state => {
        if (!state) return;
        const pos = (state.position || 0) / 1000;
        const dur = (state.duration || 0) / 1000;
        const mm = Math.floor(pos / 60);
        const ss = Math.floor(pos % 60);
        $('#bspfy-pl1-current-time').text(`${mm}:${ss < 10 ? '0' : ''}${ss}`);
        const pct = dur ? (pos / dur) * 100 : 0;
        $('#bspfy-pl1-seek-bar').width(`${pct}%`);
      }).catch(() => {});
    }, 750);
  }
  function stopProgressTimer() {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
  }
  function getTrackButtons() {
  return Array.from(document.querySelectorAll('.bspfy-play-icon[data-uri]'));
}
function getNextUri(currentUri) {
  const list = getTrackButtons();
  if (!list.length) return null;
  const idx = list.findIndex(b => b.getAttribute('data-uri') === currentUri);
  const nextBtn = idx >= 0 ? list[(idx + 1) % list.length] : list[0];
  return nextBtn?.getAttribute('data-uri') || null;
}
function getPrevUri(currentUri) {
  const list = getTrackButtons();
  if (!list.length) return null;
  const idx = list.findIndex(b => b.getAttribute('data-uri') === currentUri);
  const prevBtn = idx > 0 ? list[idx - 1] : list[list.length - 1];
  return prevBtn?.getAttribute('data-uri') || null;
}
async function playNextInDom() {
  const nextUri = getNextUri(currentTrackUri);
  if (nextUri) await playTrack(nextUri);
}
async function playPrevInDom() {
  const prevUri = getPrevUri(currentTrackUri);
  if (prevUri) await playTrack(prevUri);
}


  /** --------------------------------------------------------------
   *  Avspilling
   *  -------------------------------------------------------------- */
      async function playTrack(trackUri) {
  try {
    // 1) Sørg for token – og håndter not-authenticated med popup
    try {
      await ensureTokenSingleflight();
    } catch (_) {
      const wantAuth = confirm('You need to authenticate with Spotify to play in-page.\n\nAuthenticate now? (Cancel = open in Spotify)');
      if (!wantAuth) {
        const openUrl = `https://open.spotify.com/track/${trackUri.split(':').pop()}`;
        window.open(openUrl, '_blank', 'noopener');
        return;
      }
      await window.bspfyAuth.startAuthPopup();
      await ensureTokenSingleflight();
    }

    // 2) SDK/device
    if (!spotifyPlayer || !deviceId) {
      await initializeSpotifyPlayer();
    }

    // 3) Samme spor → toggl play/pause
    if (currentTrackUri === trackUri) {
      try { await spotifyPlayer.togglePlay(); } catch {}
      return;
    }

    // 4) Kø av URIs + offset til valgt spor
    const uris = getPlaylistUris();
    if (!uris.length) uris.push(trackUri);
    currentTrackUri = trackUri;

    const body = { uris, offset: { uri: trackUri }, position_ms: 0 };

    // 5) Kall via vår gate + single-flight wrapper
    await bspfyFetch(`https://api.spotify.com/v1/me/player/play?device_id=${encodeURIComponent(deviceId)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

  } catch (e) {
    console.error('playTrack error', e);
    alert('Could not play the track. Please try again.');
  }
}



  /** --------------------------------------------------------------
   *  SEEK / HOVER / SCRUB
   *  -------------------------------------------------------------- */
  function setupSeek() {
    const $sArea    = $('#bspfy-pl1-seek-bar-container');
    if (!$sArea.length) return;

    const $sHover   = $('#bspfy-pl1-s-hover');
    const $seekTime = $('#bspfy-pl1-seek-time');
    const $seekBar  = $('#bspfy-pl1-seek-bar');

    // Barn blokkerer ikke events
    $sHover.css('pointer-events', 'none');
    $seekBar.css('pointer-events', 'none');
    $seekTime.css('pointer-events', 'none');
    $sArea.css('cursor', 'pointer');

    let seekPX = 0;

    function fmt(sec) {
      sec = Math.max(0, Math.floor(sec));
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return `${m}:${s < 10 ? '0' : ''}${s}`;
    }

    function showHover(event) {
      const areaOffset = $sArea.offset();
      const width = $sArea.outerWidth();
      // bruk pageX for robusthet (scroll)
      seekPX = Math.max(0, Math.min(event.pageX - areaOffset.left, width));

      $sHover.width(seekPX);
      $seekTime.css({ left: seekPX, 'margin-left': '-21px', display: 'block' });

      if (!window.spotifyPlayer) return;
      window.spotifyPlayer.getCurrentState().then(state => {
        if (!state) return;
        const durationSec = (state.duration || 0) / 1000;
        const targetSec   = durationSec * (seekPX / width);
        $seekTime.text(fmt(targetSec));
      });
    }

    function hideHover() {
      $sHover.width(0);
      $seekTime.text('00:00').hide();
    }

    async function clickSeek(event) {
      if (!window.spotifyPlayer) return;
      const width = $sArea.outerWidth();
      const areaOffset = $sArea.offset();
      const x = Math.max(0, Math.min(event.pageX - areaOffset.left, width));
      const ratio = width ? (x / width) : 0;

      const state = await window.spotifyPlayer.getCurrentState();
      if (!state) return;
      const posMs = Math.floor((state.duration || 0) * ratio);
      try { await window.spotifyPlayer.seek(posMs); } catch {}
    }

    // Pause progress-timer mens vi drar/klikker
    $sArea.on('mousedown', () => { isSeeking = true; });
    $(document).on('mouseup mouseleave', () => { isSeeking = false; });

    $sArea
      .on('mousemove',  showHover)
      .on('mouseleave', hideHover)
      .on('click',      clickSeek);
  }

// ---- Auto-ellipsis + scroll-on-hover for overflowende tekst ----
(function() {
  function prepareScrollable(el) {
    if (!el) return;
    // Sett default én linje + scroll-container
    el.classList.add('bspfy-one-line', 'bspfy-scroll');

    // Ikke wrap flere ganger
    if (el.querySelector('.bspfy-scroll-inner')) return;

    const inner = document.createElement('span');
    inner.className = 'bspfy-scroll-inner';
    inner.textContent = el.textContent;
    el.textContent = '';
    el.appendChild(inner);

    function needsScroll() {
      return inner.scrollWidth > el.clientWidth + 1;
    }

    function startScroll() {
      if (!needsScroll()) return;
      const delta = inner.scrollWidth - el.clientWidth;
      const duration = Math.max(4, Math.min(15, delta / 30)); // 30px/s, clamp 4–15s
      inner.style.transition = `transform ${duration}s linear`;
      inner.style.transform = `translateX(${-delta}px)`;
    }

    function resetScroll() {
      inner.style.transition = 'transform .35s ease-out';
      inner.style.transform = 'translateX(0)';
    }

    el.addEventListener('mouseenter', startScroll);
    el.addEventListener('mouseleave', resetScroll);
    window.addEventListener('resize', resetScroll);
  }

  // Gjør tilgjengelig slik at du kan trigge etter UI-oppdateringer
  window.bspfyRefreshScroll = function() {
    // Spiller-felter
    prepareScrollable(document.getElementById('bspfy-pl1-album-name'));
    prepareScrollable(document.getElementById('bspfy-pl1-track-name'));

    // Grid (tittel + album – juster selektor ved behov)
    document.querySelectorAll('.bspfy-track-item .track-info > *').forEach(prepareScrollable);
  };
})();

  /** --------------------------------------------------------------
   *  DOM Ready – wiring
   *  -------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    // Bind seek/hover (riktig funksjon)
    setupSeek(); // ⬅️ NB: ikke kall bindSeeking(); den er kommentert ut

    // // Rad-ikoner
    // document.querySelectorAll('.bspfy-play-icon').forEach(btn => {
    //   btn.addEventListener('click', () => {
    //     const uri = btn.getAttribute('data-uri');
    //     if (uri) playTrack(uri);
    //   });
    // });

    // Hoved play/pause
    document.getElementById('bspfy-pl1-play-pause-button')?.addEventListener('click', async () => {
      if (!currentTrackUri) {
        const firstBtn = document.querySelector('.bspfy-play-icon[data-uri]');
        const uri = firstBtn?.getAttribute('data-uri');
        if (uri) playTrack(uri);
        return;
      }
      if (!spotifyPlayer || !deviceId) {
        try { await initializeSpotifyPlayer(); } catch {}
      }
      try { await spotifyPlayer.togglePlay(); } catch {}
    });
// Hele raden klikker for play i LIST-tema (også card hvis du ønsker)
const grid = document.querySelector('.bspfy-playlist-grid');
if (grid) {
  // Play på klikk, men ikke når vi klikker på lenker/meny
  grid.addEventListener('click', (e) => {
    if (
      e.target.closest('.bspfy-more') ||
      e.target.closest('.bspfy-more-menu') ||
      e.target.closest('a')
    ) return;

    const row = e.target.closest('.bspfy-list-item[data-uri], .bspfy-track-item[data-uri]');
    if (!row) return;
    const uri = row.getAttribute('data-uri') ||
                row.querySelector('.bspfy-play-icon')?.getAttribute('data-uri');
    if (uri) playTrack(uri);
  });

  // Enter/Space for tastatur
  grid.addEventListener('keydown', (e) => {
    const row = e.target.closest('.bspfy-list-item[data-uri]');
    if (!row) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const uri = row.getAttribute('data-uri');
      if (uri) playTrack(uri);
    }
  });

  // Kjøttbollemeny toggle
  grid.addEventListener('click', (e) => {
    const btn = e.target.closest('.bspfy-more');
    if (!btn) return;
    const menu = btn.parentElement.querySelector('.bspfy-more-menu');
    const open = menu.hasAttribute('hidden');
    document.querySelectorAll('.bspfy-more-menu').forEach(m => m.setAttribute('hidden',''));
    document.querySelectorAll('.bspfy-more').forEach(b => b.setAttribute('aria-expanded','false'));
    if (open) { menu.removeAttribute('hidden'); btn.setAttribute('aria-expanded','true'); }
  });

  // Klikk utenfor lukker alle menyer
  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('.bspfy-more') && !ev.target.closest('.bspfy-more-menu')) {
      document.querySelectorAll('.bspfy-more-menu').forEach(m => m.setAttribute('hidden',''));
      document.querySelectorAll('.bspfy-more').forEach(b => b.setAttribute('aria-expanded','false'));
    }
  });
}

    document.getElementById('bspfy-pl1-play-next')?.addEventListener('click', async () => {
  try { await spotifyPlayer.nextTrack(); } catch (_) {}
});

document.getElementById('bspfy-pl1-play-previous')?.addEventListener('click', async () => {
  try { await spotifyPlayer.previousTrack(); } catch (_) {}
});

    // Valgfri auth-knapp
    document.getElementById('bspfy-auth-button')?.addEventListener('click', async () => {
      try { await window.bspfyAuth.startAuthPopup(); alert('Authenticated with Spotify.'); } catch {}
    });

    // Les status (ikke åpne popup automatisk)
    (async function readOnlyStatus() {
      try {
        await window.bspfyAuth.fetchJSON(`${window.location.origin}/wp-json/bspfy/v1/oauth/token`);
      } catch {}
    })();
  
    window.bspfyRefreshScroll();
});

// ---- Dock (device + volume) ----
(function() {
  const root     = document.querySelector('.bspfy-dock-container');
  if (!root) return;

  const devBtn   = root.querySelector('#bspfy-dock-deviceBtn');
  const devMenuW = root.querySelector('#bspfy-dock-deviceMenu');
  const devList  = devMenuW?.querySelector('.bspfy-mini-devices');

  const volBtn   = root.querySelector('#bspfy-dock-volumeBtn');
  const volPop   = root.querySelector('#bspfy-dock-volumePopover');
  const volRange = root.querySelector('#bspfy-dock-volumeRange');

  let localId = null;
  let selectedId = null;

  document.addEventListener('bspfy:player_ready', (e) => {
    localId = e.detail.deviceId;
    if (!selectedId) selectedId = localId;
  });

  // --- helpers ---
  const isOpen  = (el) => el && !el.hasAttribute('hidden');
  const openEl  = (el, btn) => { el?.removeAttribute('hidden'); btn?.setAttribute('aria-expanded','true'); };
  const closeEl = (el, btn) => { el?.setAttribute('hidden',''); btn?.setAttribute('aria-expanded','false'); };
  const closeAll = () => { closeEl(devMenuW, devBtn); closeEl(volPop, volBtn); };

  async function renderDevices() {
    if (!devList) return;
    devList.innerHTML = '<button class="bspfy-mini-devices-row" disabled>Laster enheter…</button>';
    try {
      const data = await bspfyApi.devices();
      const devices = Array.isArray(data?.devices) ? data.devices : [];
      if (!devices.length) {
        devList.innerHTML = '<button class="bspfy-mini-devices-row" disabled>Ingen enheter funnet. Åpne Spotify-appen.</button>';
        return;
      }
      devList.innerHTML = devices.map(d => {
        const sel = (selectedId || localId) === d.id;
        return `<button class="bspfy-mini-devices-row${sel?' selected':''}" data-id="${d.id}">
                  <span class="bspfy-dot${d.is_active?' on':''}"></span>${d.name || d.type}
                </button>`;
      }).join('');
    } catch (e) {
      devList.innerHTML = `<button class="bspfy-mini-devices-row bspfy-error" disabled>
        Kunne ikke hente enheter (${e.status||''})
      </button>`;
    }
  }

  // --- toggles ---
  devBtn?.addEventListener('click', async (e) => {
    e.stopPropagation();
    const willOpen = !isOpen(devMenuW);
    closeEl(volPop, volBtn);             // lukk den andre først
    if (willOpen) {
      openEl(devMenuW, devBtn);
      await renderDevices();
    } else {
      closeEl(devMenuW, devBtn);
    }
  });

  devList?.addEventListener('click', async (e) => {
    const row = e.target.closest('.bspfy-mini-devices-row[data-id]');
    if (!row) return;
    try {
      await bspfyApi.transfer(row.getAttribute('data-id'), true);
      selectedId = row.getAttribute('data-id');
      closeAll();
    } catch {}
  });

  volBtn?.addEventListener('click', async (e) => {
    e.stopPropagation();
    const willOpen = !isOpen(volPop);
    closeEl(devMenuW, devBtn);
    if (willOpen) {
      openEl(volPop, volBtn);
      // sync volum når vi åpner
      try {
        if (selectedId && localId && selectedId !== localId) {
          const me = await bspfyApi.mePlayer();
          const v = Math.max(0, Math.min(100, me?.device?.volume_percent ?? 50)) / 100;
          volRange.value = String(v);
        } else if (window.spotifyPlayer) {
          const v = await window.spotifyPlayer.getVolume();
          if (typeof v === 'number') volRange.value = String(v);
        }
      } catch {}
    } else {
      closeEl(volPop, volBtn);
    }
  });

  volRange?.addEventListener('input', async (e) => {
    const v = Number(e.target.value);
    try {
      if (selectedId && localId && selectedId !== localId) {
        await bspfyApi.setRemoteVolume(selectedId, v);
      } else if (window.spotifyPlayer) {
        await window.spotifyPlayer.setVolume(v);
      }
    } catch {}
  });

  // Lukk ved klikk hvor som helst utenfor kontrollene (ikke bare utenfor hele spilleren)
  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('.bspfy-dock-ctl')) closeAll();
  });

  // Lukk på Escape
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeAll();
  });
})();



})(jQuery);