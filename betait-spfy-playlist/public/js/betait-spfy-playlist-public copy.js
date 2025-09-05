(function ($) {
  'use strict';

  /** ----------------------------------------------------------------
   *  PKCE-auth klient (WP REST: /wp-json/bspfy/v1/oauth/*)
   *  ---------------------------------------------------------------- */
  window.bspfyAuth = (function () {
    const base = `${window.location.origin}/wp-json/bspfy/v1/oauth`;

    async function fetchJSON(url, opts = {}) {
      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        ...opts
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw Object.assign(new Error(json.error || res.statusText), { status: res.status, json });
      return json;
    }

    async function ensureAccessToken() {
      try {
        const data = await fetchJSON(`${base}/token`);
        if (data.authenticated && data.access_token) return data.access_token;
        throw new Error('not-authenticated');
      } catch (e) {
        throw e; // kaller avgjør om popup/implicit skal startes
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
   *  Spotify Web Playback SDK – public
   *  ---------------------------------------------------------------- */
  let spotifyPlayer;
  let deviceId;
  let currentTrackUri = null;
  let progressTimer = null;

  async function initializeSpotifyPlayer() {
    return new Promise(async (resolve, reject) => {
      try {
        if (!window.Spotify) {
          console.error('Spotify Web Playback SDK not loaded.');
          return reject('Spotify SDK not loaded.');
        }
        // skaff token – hvis ikke logget inn, kaster vi og håndterer i play
        const initialToken = await window.bspfyAuth.ensureAccessToken();

        spotifyPlayer = new Spotify.Player({
          name: 'BeTA iT Web Player',
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
          console.log('Spotify Player ready with Device ID:', device_id);
          resolve();
        });

        spotifyPlayer.addListener('not_ready', ({ device_id }) => {
          console.error('Spotify Player not ready with Device ID:', device_id);
        });

        spotifyPlayer.addListener('player_state_changed', state => {
          if (!state) return;
          const isPlaying = !state.paused;
          const uri = state.track_window.current_track?.uri || currentTrackUri;

          updatePlayIcon(uri, isPlaying);
          updateNowPlayingCover(uri);
          updateNowPlayingUI(state);

        // start/stop progress timer
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

  /** ----------------------------------------------------------------
   *  UI helpers – ikon, cover, tekst, fremdrift
   *  ---------------------------------------------------------------- */
  function updatePlayIcon(trackUri, isPlaying) {
    // Rad-knapper (ikon ved hvert spor)
    document.querySelectorAll('.bspfy-play-icon').forEach(btn => {
      const icon = btn.querySelector('i');
      btn.classList.remove('bspfy-playing');
      if (icon) { icon.classList.remove('fa-pause'); icon.classList.add('fa-play'); }
      if (btn.getAttribute('data-uri') === trackUri && isPlaying) {
        btn.classList.add('bspfy-playing');
        if (icon) { icon.classList.remove('fa-play'); icon.classList.add('fa-pause'); }
      }
    });

    // Hoved play/pause i spilleren
    const playerBtnIcon = document.querySelector('#bspfy-pl1-play-pause-button i');
    const trackBlock   = document.getElementById('bspfy-pl1-player-track');
    const albumArt     = document.getElementById('bspfy-pl1-album-art');
    const trackTime    = document.getElementById('bspfy-pl1-track-time');

    if (playerBtnIcon) {
      playerBtnIcon.classList.remove(isPlaying ? 'fa-play' : 'fa-pause');
      playerBtnIcon.classList.add(isPlaying ? 'fa-pause' : 'fa-play');
    }
    [trackBlock, albumArt, trackTime].forEach(el => {
      if (!el) return;
      el.classList.toggle('bspfy-pl1-active', !!isPlaying);
    });
  }

  function updateNowPlayingCover(trackUri) {
    // hent cover fra din grid: .bspfy-track-item data attribute
    const btn = document.querySelector(`.bspfy-play-icon[data-uri="${trackUri}"]`);
    const coverUrl = btn?.closest('.bspfy-track-item')?.getAttribute('data-album-cover') || '';

    const mainCover = document.getElementById('bspfy-now-playing-cover');
    if (mainCover && coverUrl) mainCover.src = coverUrl;

    // sekundær rund cover i spiller HTML
    const roundCover = document.getElementById('bspfy-pl1-_1');
    if (roundCover && coverUrl) roundCover.src = coverUrl;

    // bakgrunn
    if (coverUrl) {
      $('#bspfy-pl1-player-bg-artwork').css('background-image', `url(${coverUrl})`);
    }
  }

  function updateNowPlayingUI(state) {
    if (!state || !state.track_window || !state.track_window.current_track) return;
    const track = state.track_window.current_track;

    $('#bspfy-pl1-album-name').text(track.album?.name || 'Unknown Album');
    $('#bspfy-pl1-track-name').text(track.name || 'Unknown Track');

    const total = (state.duration || 0) / 1000;
    const mm = Math.floor(total / 60);
    const ss = Math.floor(total % 60);
    $('#bspfy-pl1-track-length').text(`${mm}:${ss < 10 ? '0' : ''}${ss}`);
  }

  function startProgressTimer() {
    stopProgressTimer();
    progressTimer = setInterval(() => {
      if (!spotifyPlayer) return;
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
    }, 1000);
  }
  function stopProgressTimer() {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  /** ----------------------------------------------------------------
   *  Avspilling
   *  ---------------------------------------------------------------- */
async function playTrack(trackUri) {
  try {
    // 1) Skaff token (uten å åpne popup automatisk)
    let token;
    try {
      token = await window.bspfyAuth.ensureAccessToken();
    } catch (_) {
      const wantAuth = confirm('You need to authenticate with Spotify to play in-page.\n\nAuthenticate now? (Cancel = open in Spotify)');
      if (!wantAuth) {
        // Fallback: åpne låten i Spotify
        const openUrl = `https://open.spotify.com/track/${trackUri.split(':').pop()}`;
        window.open(openUrl, '_blank', 'noopener');
        return;
      }
      await window.bspfyAuth.startAuthPopup();
      token = await window.bspfyAuth.ensureAccessToken();
    }

    // 2) Sørg for at SDK/device er klar
    if (!spotifyPlayer || !deviceId) {
      await initializeSpotifyPlayer();
    }

    // 3) Toggle hvis samme spor
    if (currentTrackUri === trackUri) {
      const state = await spotifyPlayer.getCurrentState().catch(() => null);
      if (state && !state.paused) {
        await spotifyPlayer.pause();
      } else {
        await spotifyPlayer.togglePlay();
      }
      return;
    }

    // 4) Start nytt spor (NB: ingen credentials her!)
    currentTrackUri = trackUri;
    const res = await fetch(
      `https://api.spotify.com/v1/me/player/play?device_id=${encodeURIComponent(deviceId)}`,
      {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ uris: [trackUri] }),
      }
    );

    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      console.error('Error playing track:', res.status, txt);
      alert('Could not play the track. Please try again.');
    }
  } catch (e) {
    console.error('playTrack error', e);
  }
}


  /** ----------------------------------------------------------------
   *  Seeking (progress bar)
   *  ---------------------------------------------------------------- */
  (function bindSeeking() {
    const sArea   = $('#bspfy-pl1-seek-bar-container');
    const sHover  = $('#bspfy-pl1-s-hover');
    const seekTip = $('#bspfy-pl1-seek-time');

    let seekBarPos = null;
    sArea.on('mousemove', function (ev) {
      seekBarPos = sArea.offset();
      const x = ev.clientX - seekBarPos.left;
      sHover.width(x);

      if (!spotifyPlayer) return;
      spotifyPlayer.getCurrentState().then(state => {
        if (!state) return;
        const dur = (state.duration || 0) / 1000;
        const loc = dur * (x / sArea.outerWidth());
        const m = Math.floor(loc / 60);
        const s = Math.floor(loc % 60);
        seekTip.text(`${m}:${s < 10 ? '0' : ''}${s}`);
        seekTip.css({ left: x, 'margin-left': '-21px' }).fadeIn(0);
      });
    });

    sArea.on('mouseout', function () {
      sHover.width(0);
      seekTip.text('00:00').css({ left: '0px', 'margin-left': '0px' }).fadeOut(0);
    });

    sArea.on('click', function (ev) {
      if (!spotifyPlayer) return;
      const off = sArea.offset();
      const x = ev.clientX - off.left;
      spotifyPlayer.getCurrentState().then(state => {
        if (!state) return;
        const durMs = state.duration || 0;
        const posMs = durMs * (x / sArea.outerWidth());
        spotifyPlayer.seek(posMs);
      });
    });
  })();


  /** ----------------------------------------------------------------
   *  DOM Ready – bind klikk på spille-ikoner og hovedkontroller
   *  ---------------------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    // Rad-ikoner
    document.querySelectorAll('.bspfy-play-icon').forEach(btn => {
      btn.addEventListener('click', () => {
        const uri = btn.getAttribute('data-uri');
        if (uri) playTrack(uri);
      });
    });

    // Hoved kontroller
    document.getElementById('bspfy-pl1-play-pause-button')?.addEventListener('click', async () => {
      if (!currentTrackUri) {
        // spille første i lista hvis ingen valgt
        const firstBtn = document.querySelector('.bspfy-play-icon[data-uri]');
        const uri = firstBtn?.getAttribute('data-uri');
        if (uri) playTrack(uri);
        return;
      }
      playTrack(currentTrackUri);
    });

    document.getElementById('bspfy-pl1-play-next')?.addEventListener('click', async () => {
      // “Neste”: finn neste .bspfy-play-icon etter gjeldende
      const all = [...document.querySelectorAll('.bspfy-play-icon[data-uri]')];
      const idx = all.findIndex(b => b.getAttribute('data-uri') === currentTrackUri);
      const next = idx >= 0 ? all[(idx + 1) % all.length] : all[0];
      const uri = next?.getAttribute('data-uri');
      if (uri) playTrack(uri);
    });

    document.getElementById('bspfy-pl1-play-previous')?.addEventListener('click', async () => {
      const all = [...document.querySelectorAll('.bspfy-play-icon[data-uri]')];
      const idx = all.findIndex(b => b.getAttribute('data-uri') === currentTrackUri);
      const prev = idx > 0 ? all[idx - 1] : all[all.length - 1];
      const uri = prev?.getAttribute('data-uri');
      if (uri) playTrack(uri);
    });

    // (Valgfritt) “Authenticate”-knapp i front-end hvis du har en
    document.getElementById('bspfy-auth-button')?.addEventListener('click', async () => {
      try {
        await window.bspfyAuth.startAuthPopup();
        alert('Authenticated with Spotify.');
      } catch (e) {
        // noop
      }
    });

    // Read-only status: IKKE åpne popup automatisk
    (async function readOnlyStatus() {
      try {
        const data = await window.bspfyAuth.fetchJSON(`${window.location.origin}/wp-json/bspfy/v1/oauth/token`);
        if (data.authenticated && data.access_token) {
          // optionally, update a small badge
         console.log('Public authenticated');
        }
      } catch (_) { /* ignore */ }
    })();
  });

})(jQuery);
