(function ($) {
    'use strict';
// Lightweight client for PKCE flow via our WP REST endpoints
window.bspfyAuth = (function() {
  const base = `${window.location.origin}/wp-json/bspfy/v1/oauth`;

  async function fetchJSON(url, opts={}) {
    const res = await fetch(url, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(json.error || res.statusText), {status: res.status, json});
    return json;
  }

  async function ensureAccessToken() {
    try {
      const data = await fetchJSON(`${base}/token`);
      if (data.authenticated && data.access_token) return data.access_token;
      throw new Error('not-authenticated');
    } catch(e) {
      // start auth popup
      await startAuthPopup();
      // retry
      const data2 = await fetchJSON(`${base}/token`);
      if (data2.authenticated && data2.access_token) return data2.access_token;
      throw new Error('auth-failed');
    }
  }

  function startAuthPopup() {
    return new Promise(async (resolve, reject) => {
      const redirectBack = window.location.href;
      let auth;
      try {
        auth = await fetchJSON(`${base}/start`, {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ redirectBack })
        });
      } catch(e) { return reject(e); }

      const w = 520, h = 680;
      const left = window.screenX + (window.outerWidth - w)/2;
      const top  = window.screenY + (window.outerHeight - h)/2;
      const popup = window.open(auth.authorizeUrl, 'bspfy-auth', `width=${w},height=${h},left=${left},top=${top}`);

      const handler = (ev) => {
        if (ev.origin !== window.location.origin) return;
        if (ev.data && ev.data.type === 'bspfy-auth' && ev.data.success) {
          window.removeEventListener('message', handler);
          try { popup && popup.close(); } catch(e){}
          resolve(true);
        }
      };
      window.addEventListener('message', handler);

      // safety: timeout if popup blocked/closed
      setTimeout(() => {
        try { if (popup && popup.closed) reject(new Error('popup-closed')); } catch(e){}
      }, 15000);
    });
  }

  return { ensureAccessToken, startAuthPopup };
})();


    // Spotify Player setup
    let spotifyPlayer;
    let deviceId;
    let currentTrackUri = null;

    window.onSpotifyWebPlaybackSDKReady = () => {
        const token = localStorage.getItem('spotifyAccessToken');
        spotifyPlayer = new Spotify.Player({
            name: 'BeTA iT Web Player',
            getOAuthToken: cb => { cb(token); },
            volume: 0.5
        });

        // Ready
        spotifyPlayer.addListener('ready', ({ device_id }) => {
            deviceId = device_id;
            console.log('Ready with Device ID', deviceId);
        });

        // Connect to the player
        spotifyPlayer.connect();
    };

    // Function to initialize Spotify Web Playback SDK
    async function initializeSpotifyPlayer(accessToken) {
        if (!window.Spotify) {
            alert('Spotify Web Playback SDK not loaded.');
            return;
        }

        spotifyPlayer = new Spotify.Player({
            name: 'BeTA iT Spotify Player',
            getOAuthToken: cb => cb(accessToken),
        });

        spotifyPlayer.addListener('ready', ({ device_id }) => {
            console.log('Player ready with device ID:', device_id);
        });

        spotifyPlayer.addListener('not_ready', ({ device_id }) => {
            console.error('Player not ready with device ID:', device_id);
        });

        await spotifyPlayer.connect();
    }

    // Function to play/pause a track
    async function playTrack(trackUri) {
        const accessToken = localStorage.getItem('spotifyAccessToken');

        if (!accessToken) {
            alert('You need to authenticate with Spotify to play this track.');
            authenticateWithSpotify();
            return;
        }

        if (!spotifyPlayer) {
            await initializeSpotifyPlayer(accessToken);
        }

        if (currentTrackUri === trackUri) {
            spotifyPlayer.togglePlay();
        } else {
            currentTrackUri = trackUri;
            const response = await fetch(`https://api.spotify.com/v1/me/player/play?device_id=${deviceId}`, {
                method: 'PUT',
                headers: {
                    'Authorization': `Bearer ${accessToken}`,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ uris: [trackUri] }),
            }).catch(error => console.error('Error playing track:', error));
        }
    }

    // Function to toggle Play/Pause button
    function togglePlayButton(button) {
        const isPlaying = button.textContent === 'Pause';
        button.textContent = isPlaying ? 'Resume' : 'Pause';

        // Reset other play buttons
        document.querySelectorAll('.track-actions-preview-button').forEach(btn => {
            if (btn !== button) btn.textContent = 'Play';
        });
    }

    // DOMContentLoaded event
    document.addEventListener('DOMContentLoaded', function () {
        const debugEnabled = window.bspfyDebug?.debug || false;
        const ajaxUrl = window.bspfyDebug?.ajaxurl || '';
        const clientId = window.bspfyDebug?.client_id || '';

        if (!clientId) {
            console.error('Spotify Client ID is missing. Please configure it in the plugin settings.');
            return;
        }

        function logDebug(message, data = null) {
            if (debugEnabled) {
                console.log(`[BeTA iT - Spfy Playlist Debug] ${message}`);
                if (data) {
                    console.log(data);
                }
            }
        }

        logDebug('JavaScript initialized with client_id:', clientId);

        // Elements
        const searchButton = document.getElementById('search_tracks_button');
        const searchInput = document.getElementById('playlist_tracks_search');
        const searchResults = document.getElementById('track_search_results');
        const artistFilter = document.getElementById('search_filter_artist');
        const trackFilter = document.getElementById('search_filter_track');
        const albumFilter = document.getElementById('search_filter_album');

        if (!searchButton || !searchInput || !searchResults) {
            logDebug('Missing elements on the page. Ensure IDs are correct.', {
                searchButton,
                searchInput,
                searchResults,
                artistFilter,
                trackFilter,
                albumFilter,
            });
            return;
        }

        // Search Button Event Listener
        searchButton.addEventListener('click', async function () {
            const query = searchInput.value.trim();
            if (!query) {
                alert('Please enter a track or artist name.');
                return;
            }
        
            const filters = [];
            if (artistFilter.checked) filters.push('artist');
            if (trackFilter.checked) filters.push('track');
            if (albumFilter.checked) filters.push('album');
        
            const accessToken = localStorage.getItem('spotifyAccessToken');
            if (!accessToken) {
                alert('You must authenticate with Spotify first.');
                return;
            }
        
            searchResults.innerHTML = '<p>Searching...</p>';
        
            try {
                const response = await fetch(`${ajaxUrl}?action=search_spotify_tracks`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${accessToken}`,
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        query: query,
                        type: filters.join(','),
                    }),
                });
        
                // Log the raw response for debugging
                const responseData = await response.json();
                console.log('Spotify API Response:', responseData);
        
                if (responseData.success) {
                    renderTracks(responseData.data.tracks);
                } else {
                    // Display the full error message from Spotify
                    searchResults.innerHTML = `<p>Error: ${responseData.message || 'No tracks found.'}</p>`;
                    console.error('Error fetching tracks:', responseData);
                }
            } catch (error) {
                searchResults.innerHTML = '<p>An error occurred while searching for tracks.</p>';
                console.error('Error during Spotify search:', error);
            }
        });

        // Function to Render Tracks
        function renderTracks(tracks) {
            searchResults.innerHTML = '';

            tracks.forEach(track => {
                const trackElement = document.createElement('div');
                trackElement.classList.add('track-result');
                trackElement.innerHTML = `
                    <img src="${track.album.images[0]?.url || ''}" alt="${track.album.name}">
                    <div class="track-details">
                        <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${track.artists[0].name}</div>
                        <div class="track-details-album track-details-space"><strong>Album:</strong> ${track.album.name}</div>
                        <div class="track-details-tracktitle track-details-space"><strong>Track:</strong> ${track.name}</div>
                    </div>
                    <div class="track-actions">
                        <div class="track-actions-preview-button" data-uri="${track.uri}">Play</div>
                        <div class="track-actions-add-track-button" data-track='${JSON.stringify(track)}'>Add</div>
                    </div>
                `;
                searchResults.appendChild(trackElement);

                // Play button event listener
                trackElement.querySelector('.track-actions-preview-button').addEventListener('click', function () {
                    const trackUri = this.getAttribute('data-uri');
                    if (trackUri) {
                        playTrack(trackUri);
                        togglePlayButton(this);
                    } else {
                        alert('No track URI available.');
                    }
                });

                // Add button event listener
                trackElement.querySelector('.track-actions-add-track-button').addEventListener('click', function () {
                    const trackData = JSON.parse(this.getAttribute('data-track'));
                    addTrackToPlaylist(trackData);
                });
            });
        }

        // Function to Add Track to Playlist
        function addTrackToPlaylist(track) {
            const playlistTracksInput = document.getElementById('playlist_tracks');
            const currentTracks = JSON.parse(playlistTracksInput.value || '[]');
            currentTracks.push(track);
            playlistTracksInput.value = JSON.stringify(currentTracks);

            const playlistList = document.getElementById('playlist_tracks_list');
            const trackItem = document.createElement('div');
            trackItem.classList.add('bspfy-track-item', 'bspfy-new-track');
            trackItem.innerHTML = `
            <img src="${track.album.images[0]?.url || ''}" alt="${track.album.name}">
            <div class="track-details">
            <div class="track-details-artist track-details-space"><strong>Artist:</strong> ${track.artists[0].name}</div>
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
        document.addEventListener('click', function (event) {
            if (event.target.classList.contains('bspfy-remove-button')) {
                const trackId = event.target.getAttribute('data-track-id');
                const trackItem = event.target.closest('.bspfy-track-item');
                
                if (trackId && trackItem) {
                    // Remove the track from the list.
                    trackItem.remove();
        
                    // Update the hidden input field.
                    const playlistTracksInput = document.getElementById('playlist_tracks');
                    const currentTracks = JSON.parse(playlistTracksInput.value || '[]');
                    const updatedTracks = currentTracks.filter(track => track.id !== trackId);
                    playlistTracksInput.value = JSON.stringify(updatedTracks);
                }
            }
        });
                    
        // Attach play button event listeners to saved tracks
        
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
    
        // Call the function to attach listeners
        attachPlayListenersToSavedTracks();            
    });

    const debugEnabled = window.bspfyDebug?.debug || false;
    const ajaxUrl = window.bspfyDebug?.ajaxurl || '';

    function logDebug(message, data = null) {
        if (debugEnabled) {
            console.log(`[BeTA iT - Spfy Playlist Debug] ${message}`);
            if (data) {
                console.log(data);
            }
        }
    }
    const authButton = document.getElementById('spotify-auth-button');
    const authContainer = document.querySelector('.oauth-container');

    if (authButton) {
        authButton.addEventListener('click', function () {
            logDebug('Spotify Auth button clicked.');
            authenticateWithSpotify();
        });
    }

    async function checkSpotifyAuthentication() {
        const accessToken = localStorage.getItem('spotifyAccessToken');

        if (!accessToken) {
            logDebug('No access token found. Showing auth button.');
            showAuthButton();
            return;
        }

        try {
            const response = await fetch('https://api.spotify.com/v1/me', {
                headers: { Authorization: `Bearer ${accessToken}` },
            });

            if (!response.ok) {
                throw new Error('Token is invalid or expired.');
            }

            const data = await response.json();
            const spotifyName = data.display_name || 'Unknown User';

            logDebug('Spotify user authenticated as:', spotifyName);
            showAuthenticatedStatus(spotifyName);

            // Optionally save the username via AJAX
            saveSpotifyUserName(spotifyName);
        } catch (error) {
            logDebug('Token validation failed. Prompting reauthentication.', error);
            showAuthButton();
        }
    }

    function authenticateWithSpotify() {
        const clientId = window.bspfyDebug?.client_id;
        const redirectUri = window.bspfySettings?.redirectUri || window.location.origin + '/spotify-auth-redirect/';
        const scopes = 'streaming user-read-email user-read-private user-read-playback-state user-modify-playback-state';

        if (!clientId) {
            alert('Spotify client ID is not set. Please configure it in the plugin settings.');
            return;
        }

        const authUrl = `https://accounts.spotify.com/authorize?response_type=token&client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}&scope=${encodeURIComponent(scopes)}`;
        logDebug('Redirecting to Spotify Auth URL:', authUrl);
        window.location.href = authUrl;
    }

    function showAuthButton() {
        authContainer.innerHTML = `
            <div id="spotify-auth-button" class="bspfy-button">
                <span class="btspfy-button-text">Authenticate with Spotify</span>
                <span class="btspfy-button-icon-divider btspfy-button-icon-divider-right">
                    <i class="fa-spotify fab" aria-hidden="true"></i>
                </span>
            </div>
        `;

        const newAuthButton = document.getElementById('spotify-auth-button');
        newAuthButton.addEventListener('click', authenticateWithSpotify);
    }

    function showAuthenticatedStatus(spotifyName) {
        authContainer.innerHTML = `
            <div id="spotify-auth-status" class="bspfy-authenticated bspfy-button">
            <span class="btspfy-button-text">User: ${spotifyName}</span><span class="btspfy-button-icon-divider btspfy-button-icon-divider-right"><i class="fa-spotify fab" aria-hidden="true"></i></span>
            </div>
        `;
    }

    function saveSpotifyUserName(spotifyName) {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_spotify_user_name',
                spotify_user_name: spotifyName,
            }),
        })
            .then(response => response.json())
            .then(data => {
                logDebug('Spotify username saved via AJAX:', data);
            })
            .catch(error => {
                logDebug('Error saving Spotify username via AJAX:', error);
            });
    }

    // Run authentication check on page load
    checkSpotifyAuthentication();
})(jQuery);
