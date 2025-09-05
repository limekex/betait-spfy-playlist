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
    return new Promise(async (resolve, reject) => {
      try {
        if (!window.Spotify) {
          console.error('Spotify Web Playback SDK not loaded.');
          return reject('Spotify SDK not loaded.');
        }
        await window.bspfyAuth.ensureAccessToken();

        spotifyPlayer = new Spotify.Player({
          name: 'BeTA iT Web Player',
          getOAuthToken: async cb => {
            try { cb(await window.bspfyAuth.ensureAccessToken()); }
            catch (e) { console.error('Failed to refresh token for SDK', e); }
          },
          volume: 0.5
        });

        // 🔧 Viktig: eksponer også på window for seek-koden
        window.spotifyPlayer = spotifyPlayer;

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

            currentTrackUri = uri;

            updatePlayIcon(uri, isPlaying);
            updateNowPlayingCover(uri);
            updateNowPlayingUI(state);

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
            // 1) Token (uten auto-popup – samme logikk som før)
            let token;
            try {
            token = await window.bspfyAuth.ensureAccessToken();
            } catch (_) {
            const wantAuth = confirm('You need to authenticate with Spotify to play in-page.\n\nAuthenticate now? (Cancel = open in Spotify)');
            if (!wantAuth) {
                const openUrl = `https://open.spotify.com/track/${trackUri.split(':').pop()}`;
                window.open(openUrl, '_blank', 'noopener');
                return;
            }
            await window.bspfyAuth.startAuthPopup();
            token = await window.bspfyAuth.ensureAccessToken();
            }

            // 2) SDK/device
            if (!spotifyPlayer || !deviceId) {
            await initializeSpotifyPlayer();
            }

            // 3) Hvis samme spor: toggl bare
            if (currentTrackUri === trackUri) {
            await spotifyPlayer.togglePlay();
            return;
            }

            // 4) Bygg full kø av URIs + offset til valgt spor
            const uris = getPlaylistUris();
            if (!uris.length) uris.push(trackUri);

            currentTrackUri = trackUri;

            const body = {
            uris,
            offset: { uri: trackUri }, // start på valgt spor
            position_ms: 0
            };

            const res = await fetch(
            `https://api.spotify.com/v1/me/player/play?device_id=${encodeURIComponent(deviceId)}`,
            {
                method: 'PUT',
                headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json',
                },
                body: JSON.stringify(body),
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

    // Rad-ikoner
    document.querySelectorAll('.bspfy-play-icon').forEach(btn => {
      btn.addEventListener('click', () => {
        const uri = btn.getAttribute('data-uri');
        if (uri) playTrack(uri);
      });
    });

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

    // Next / Previous
    // document.getElementById('bspfy-pl1-play-next')?.addEventListener('click', async () => {
    //   const all = [...document.querySelectorAll('.bspfy-play-icon[data-uri]')];
    //   const idx = all.findIndex(b => b.getAttribute('data-uri') === currentTrackUri);
    //   const next = idx >= 0 ? all[(idx + 1) % all.length] : all[0];
    //   const uri = next?.getAttribute('data-uri');
    //   if (uri) playTrack(uri);
    // });

    // document.getElementById('bspfy-pl1-play-previous')?.addEventListener('click', async () => {
    //   const all = [...document.querySelectorAll('.bspfy-play-icon[data-uri]')];
    //   const idx = all.findIndex(b => b.getAttribute('data-uri') === currentTrackUri);
    //   const prev = idx > 0 ? all[idx - 1] : all[all.length - 1];
    //   const uri = prev?.getAttribute('data-uri');
    //   if (uri) playTrack(uri);
    // });
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

})(jQuery);
