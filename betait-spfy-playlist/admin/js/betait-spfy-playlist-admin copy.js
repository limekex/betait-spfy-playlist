(function( $ ) {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Retrieve debugging status from localized script
        const debugEnabled = window.bspfyDebug || false;

        function logDebug(message, data = null) {
            if (debugEnabled) {
                console.log(`[BeTA iT - Spfy Playlist Debug] ${message}`);
                if (data) {
                    console.log(data);
                }
            }
        }

        const searchButton = document.getElementById('search_tracks_button');
        const searchInput = document.getElementById('playlist_tracks_search');
        const searchResults = document.getElementById('track_search_results');
        const playlistTracksList = document.getElementById('playlist_tracks_list');
        const playlistTracksField = document.getElementById('playlist_tracks');

        let playlistTracks = JSON.parse(playlistTracksField.value || '[]');
        logDebug('Initialized playlistTracks:', playlistTracks);

        searchButton.addEventListener('click', async function () {
            const query = searchInput.value.trim();
            if (!query) {
                alert('Please enter a track or artist name.');
                logDebug('Search initiated without a query.');
                return;
            }

            searchResults.innerHTML = '<p>Searching...</p>';
            logDebug('Search initiated with query:', query);

            try {
                const response = await fetch(ajaxurl + '?action=search_spotify_tracks&query=' + encodeURIComponent(query));
                const data = await response.json();
                logDebug('Search response received:', data);

                if (!data.success) {
                    const errorMessage = data.data?.message || 'An unknown error occurred.';
                    searchResults.innerHTML = `<p>Error: ${errorMessage}</p>`;
                    logDebug('Error in response from server:', data.data?.response || 'No additional details.');
                    return;
                }

                const tracks = data.data.tracks?.items || [];
                logDebug('Tracks retrieved:', tracks);

                if (!tracks.length) {
                    searchResults.innerHTML = '<p>No tracks found.</p>';
                    logDebug('No tracks found in response.');
                    return;
                }

                searchResults.innerHTML = '';
                tracks.forEach(track => {
                    const trackElement = document.createElement('div');
                    trackElement.classList.add('track-result');
                    trackElement.innerHTML = `
                        <img src="${track.album.images[0]?.url || ''}" alt="${track.album.name}">
                        <div class="track-details">
                            <span><strong>${track.artists[0].name}</strong></span>
                            <span>${track.album.name}</span>
                            <span>${track.name}</span>
                        </div>
                        <div class="track-actions">
                            <button class="preview-button" data-preview="${track.preview_url}">Preview</button>
                            <button class="add-track-button" data-track='${JSON.stringify(track)}'>Add</button>
                        </div>
                    `;
                    searchResults.appendChild(trackElement);
                });

                document.querySelectorAll('.preview-button').forEach(button => {
                    button.addEventListener('click', function () {
                        const previewUrl = this.getAttribute('data-preview');
                        if (previewUrl) {
                            const audio = new Audio(previewUrl);
                            audio.play();
                            logDebug('Preview initiated for URL:', previewUrl);
                        } else {
                            alert('No preview available for this track.');
                            logDebug('No preview available for this track.');
                        }
                    });
                });

                document.querySelectorAll('.add-track-button').forEach(button => {
                    button.addEventListener('click', function () {
                        const track = JSON.parse(this.getAttribute('data-track'));
                        playlistTracks.push({
                            id: track.id,
                            name: track.name,
                            artist: track.artists[0].name,
                            album: track.album.name
                        });
                        logDebug('Track added to playlist:', track);
                        updatePlaylistTracks();
                    });
                });
            } catch (error) {
                searchResults.innerHTML = '<p>An error occurred while searching for tracks.</p>';
                console.error('Spotify Search Error:', error);
                logDebug('Unexpected error during search:', error);
            }
        });

        function updatePlaylistTracks() {
            logDebug('Updating playlistTracks:', playlistTracks);
            playlistTracksList.innerHTML = '';
            playlistTracks.forEach(track => {
                const li = document.createElement('li');
                li.innerHTML = `
                    <span>${track.artist} (${track.album}) - ${track.name}</span>
                    <button class="remove-track-button" data-track-id="${track.id}">Remove</button>
                `;
                playlistTracksList.appendChild(li);
            });
            playlistTracksField.value = JSON.stringify(playlistTracks);

            document.querySelectorAll('.remove-track-button').forEach(button => {
                button.addEventListener('click', function () {
                    const trackId = this.getAttribute('data-track-id');
                    playlistTracks = playlistTracks.filter(track => track.id !== trackId);
                    logDebug('Track removed from playlist:', trackId);
                    updatePlaylistTracks();
                });
            });
        }
    });

})( jQuery );
