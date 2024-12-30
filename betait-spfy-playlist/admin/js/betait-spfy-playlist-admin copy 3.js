(function( $ ) {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
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

        logDebug('JavaScript initialized.');

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
                albumFilter
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
        
            searchResults.innerHTML = '<p>Searching...</p>';
        
            try {
                const response = await fetch(`${ajaxUrl}?action=search_spotify_tracks&query=${encodeURIComponent(query)}&type=${filters.join(',')}`);
                const data = await response.json();
        
                if (data.success) {
                    renderTracks(data.data.tracks);
                } else {
                    searchResults.innerHTML = `<p>${data.data.message || 'No tracks found in the Spotify response.'}</p>`;
                }
            } catch (error) {
                searchResults.innerHTML = '<p>An error occurred while searching for tracks.</p>';
            }
        });
		

        function renderTracks(tracks) {
            searchResults.innerHTML = '';
        
            // To keep track of added tracks
            const addedTracks = new Set();
        
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
                        <div class="track-actions-preview-button" data-preview="${track.preview_url}">Preview</div>
                        <div class="track-actions-add-track-button" data-track='${JSON.stringify(track)}'>Add</div>
                    </div>
                `;
                searchResults.appendChild(trackElement);
        
                // Add event listener for preview
                trackElement.querySelector('.track-actions-preview-button').addEventListener('click', function () {
                    const previewUrl = this.getAttribute('data-preview');
                    if (previewUrl) {
                        playPreview(previewUrl);
                    } else {
                        alert('No preview available for this track.');
                    }
                });
        
                // Add event listener for add
                trackElement.querySelector('.track-actions-add-track-button').addEventListener('click', function () {
                    const trackData = JSON.parse(this.getAttribute('data-track'));
                    if (!addedTracks.has(trackData.id)) {
                        addedTracks.add(trackData.id);
                        addTrackToPlaylist(trackData);
                    } else {
                        alert('Track is already added.');
                    }
                });
            });
        }
        
        // Play preview function
        function playPreview(url) {
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
        
            // Update the UI
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
        
            // Add remove functionality
            trackItem.querySelector('.bspfy-remove-button').addEventListener('click', function () {
                const trackId = this.getAttribute('data-track-id');
                const updatedTracks = currentTracks.filter(t => t.id !== trackId);
                playlistTracksInput.value = JSON.stringify(updatedTracks);
                trackItem.remove();
            });
        }
        
    });
})( jQuery );