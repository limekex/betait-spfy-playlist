(function( $ ) {
    'use strict';

    let spotifyPlayer;
    let deviceId;

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
        
        async function playTrack(trackUri) {
            const token = localStorage.getItem('spotifyAccessToken');
        
            if (!deviceId) {
                alert('Spotify Player is not ready yet.');
                return;
            }
        
            try {
                const response = await fetch(`https://api.spotify.com/v1/me/player/play?device_id=${deviceId}`, {
                    method: 'PUT',
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ uris: [trackUri] })
                });
        
                if (response.ok) {
                    console.log('Playing track:', trackUri);
                } else {
                    const error = await response.json();
                    console.error('Error playing track:', error);
                }
            } catch (error) {
                console.error('Error playing track:', error);
            }
        }
                
        
        const authButton = document.getElementById('spotify-auth-button');
        const authStatus = document.getElementById('spotify-auth-status');

        if (authButton) {
            authButton.addEventListener('click', function() {
                logDebug('Spotify Auth button clicked.');
                authenticateWithSpotify();
            });
        }

        function authenticateWithSpotify() {
            const clientId = window.bspfyDebug?.client_id;
            const redirectUri = window.bspfySettings?.redirectUri || window.location.origin + '/spotify-auth-redirect/';
            const scopes = 'streaming user-read-email user-read-private';
        
            if (!clientId) {
                alert('Spotify client ID is not set. Please configure it in the plugin settings.');
                return;
            }
        
            const authUrl = `https://accounts.spotify.com/authorize?response_type=token&client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}&scope=${encodeURIComponent(scopes)}`;
            logDebug('Redirecting to Spotify Auth URL:', authUrl);
            window.location.href = authUrl;
        }
        
            // Check if we are authenticated
            async function checkSpotifyAuthentication() {
                const accessToken = localStorage.getItem('spotifyAccessToken');
            
                if (!accessToken) {
                    logDebug('No access token found. Prompting authentication.');
                    promptAuthentication();
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
                    document.querySelector('.oauth-container').innerHTML = `
                        <div id="spotify-auth-status" class="bspfy-authenticated">
                            Authenticated as ${spotifyName}
                        </div>`;
                    logDebug('Spotify user authenticated as:', spotifyName);
            
                    // Optionally save the username via AJAX
                    saveSpotifyUserName(spotifyName);
                } catch (error) {
                    logDebug('Token validation failed. Prompting reauthentication.', error);
                    promptAuthentication();
                }
            }

            function promptAuthentication() {
                const authButton = document.getElementById('spotify-auth-button');
                if (authButton) {
                    authButton.click();
                } else {
                    authenticateWithSpotify();
                }
            }
            function saveSpotifyUserName(spotifyName) {
                fetch(ajaxurl, {
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
                        

                    // Extract OAuth token
                    if (window.location.pathname === '/spotify-auth-redirect/') {
                        const hash = window.location.hash.substring(1);
                        const params = new URLSearchParams(hash);
                        const accessToken = params.get('access_token');
                    
                        if (accessToken) {
                            localStorage.setItem('spotifyAccessToken', accessToken);
                            logDebug('Spotify access token stored locally.');
                    
                            const redirectBackUrl = localStorage.getItem('spotifyRedirectBack');
                            if (redirectBackUrl) {
                                localStorage.removeItem('spotifyRedirectBack');
                                window.location.href = redirectBackUrl;
                            } else {
                                window.location.href = '/';
                            }
                        } else {
                            alert('Failed to authenticate with Spotify. Please try again.');
                            window.location.href = '/';
                        }
                    }
                    

        // Play preview function
        function playPreview(url) {
            const accessToken = localStorage.getItem('spotifyAccessToken');
            if (!accessToken) {
                alert('You need to authenticate with Spotify to preview tracks.');
                authenticateWithSpotify();
                return;
            }

            const audio = document.getElementById('track-preview-audio');
            if (audio) {
                audio.src = url;
                audio.play();
            } else {
                const newAudio = document.createElement('audio');
                newAudio.id = 'track-preview-audio';
                newAudio.src = url;
                newAudio.controls = true;
                document.body.appendChild(newAudio);
                newAudio.play();
            }
        }

        // Add track to playlist
        function addTrackToPlaylist(track) {
            const playlistTracksInput = document.getElementById('playlist_tracks');
            const currentTracks = JSON.parse(playlistTracksInput.value || '[]');
            currentTracks.push(track);
            playlistTracksInput.value = JSON.stringify(currentTracks);

            const playlistList = document.getElementById('playlist_tracks_list');
            const trackItem = document.createElement('li');
            trackItem.classList.add('bspfy-track-item');
            trackItem.innerHTML = `
                <div class="bspfy-track-details">
                    <span class="bspfy-track-artist">${track.artists[0].name}</span>
                    <span class="bspfy-track-album">(${track.album.name})</span>
                    <span class="bspfy-track-name">- ${track.name}</span>
                </div>
                <button type="button" class="bspfy-remove-button" data-track-id="${track.id}">Remove</button>
            `;
            playlistList.appendChild(trackItem);

            trackItem.querySelector('.bspfy-remove-button').addEventListener('click', function () {
                const trackId = this.getAttribute('data-track-id');
                const updatedTracks = currentTracks.filter(t => t.id !== trackId);
                playlistTracksInput.value = JSON.stringify(updatedTracks);
                trackItem.remove();
            });
        }

        // Example usage (attach this to buttons or actions)
        document.getElementById('previewButton').addEventListener('click', () => {
            playPreview('https://p.scdn.co/mp3-preview/example'); // Example track
        });
    });
})( jQuery );
