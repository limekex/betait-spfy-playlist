(function ($) {
    'use strict';

    let spotifyPlayer;
    let deviceId;
    let currentTrackUri = null;

    // Vent til Spotify SDK er klar
    window.onSpotifyWebPlaybackSDKReady = function () {
        console.log('Spotify Web Playback SDK is ready');
        initializeSpotifyPlayer();
    };

    async function initializeSpotifyPlayer() {
        const token = localStorage.getItem('spotifyAccessToken');

        if (!token) {
            console.error('No access token found. Please authenticate first.');
            return;
        }

        if (!window.Spotify) {
            console.error('Spotify SDK is not loaded. Please check your HTML.');
            return;
        }

        spotifyPlayer = new Spotify.Player({
            name: 'BeTA iT Web Player',
            getOAuthToken: cb => { cb(token); },
            volume: 0.5
        });

        spotifyPlayer.addListener('ready', ({ device_id }) => {
            deviceId = device_id;
            console.log('Spotify Player ready with Device ID:', deviceId);
        });

        spotifyPlayer.addListener('not_ready', ({ device_id }) => {
            console.error('Spotify Player not ready with Device ID:', device_id);
        });

        spotifyPlayer.addListener('player_state_changed', state => {
            if (!state) return;

            const isPlaying = !state.paused;
            currentTrackUri = state.track_window.current_track.uri;

            updateNowPlayingUI(state.track_window.current_track);
            updatePlayIcon(currentTrackUri, isPlaying);
        });

        await spotifyPlayer.connect();
    }

    // Update UI with the current playing track
    function updateNowPlayingUI(track) {
        if (!track) return;

        $('#bspfy-pl1-album-name').text(track.album.name);
        $('#bspfy-pl1-track-name').text(track.name);
        $('#bspfy-pl1-album-art img').attr('src', track.album.images[0]?.url || '');

        const bgArtworkUrl = track.album.images[0]?.url || '';
        $('#bspfy-pl1-player-bg-artwork').css({
            'background-image': `url(${bgArtworkUrl})`
        });
    }

    // Play or pause the track
    function playPause() {
        if (spotifyPlayer) {
            spotifyPlayer.togglePlay();
        }
    }

    // Select next or previous track
    function selectTrack(direction) {
        if (direction === -1) {
            spotifyPlayer.previousTrack();
        } else if (direction === 1) {
            spotifyPlayer.nextTrack();
        }
    }

    // Update the current time of the track
    function updateCurrTime() {
        spotifyPlayer.getCurrentState().then(state => {
            if (!state) return;

            const currentTime = state.position / 1000;
            const duration = state.duration / 1000;

            const curMinutes = Math.floor(currentTime / 60);
            const curSeconds = Math.floor(currentTime % 60);
            const durMinutes = Math.floor(duration / 60);
            const durSeconds = Math.floor(duration % 60);

            $('#bspfy-pl1-current-time').text(`${curMinutes}:${curSeconds}`);
            $('#bspfy-pl1-track-length').text(`${durMinutes}:${durSeconds}`);

            const playProgress = (currentTime / duration) * 100;
            $('#bspfy-pl1-seek-bar').width(`${playProgress}%`);
        }).catch(error => {
            console.error('Error updating current time:', error);
        });
    }

    // Initialize the player
    $(function () {
        $('#bspfy-pl1-play-pause-button').on('click', playPause);
        $('#bspfy-pl1-play-previous').on('click', function () {
            selectTrack(-1);
        });
        $('#bspfy-pl1-play-next').on('click', function () {
            selectTrack(1);
        });

        setInterval(() => {
            if (spotifyPlayer) {
                spotifyPlayer.getCurrentState().then(state => {
                    if (state) {
                        const currentTime = state.position / 1000;
                        const duration = state.duration / 1000;
                        const playProgress = (currentTime / duration) * 100;

                        $('#bspfy-pl1-current-time').text(formatTime(currentTime));
                        $('#bspfy-pl1-track-length').text(formatTime(duration));
                        $('#bspfy-pl1-seek-bar').width(`${playProgress}%`);
                    }
                });
            }
        }, 1000);
    });
    // Seek to a specific position in the track
    function showHover(event) {
        const sArea = $('#bspfy-pl1-seek-bar-container');
        const sHover = $('#bspfy-pl1-s-hover');
        const seekTime = $('#bspfy-pl1-seek-time');
        const seekBarPos = sArea.offset();
        const seekT = event.clientX - seekBarPos.left;

        spotifyPlayer.getCurrentState().then(state => {
            if (state && state.duration) {
                const seekLoc = state.duration * (seekT / sArea.outerWidth());
                sHover.width(seekT);
                seekTime.css({ left: seekT, 'margin-left': '-21px' }).fadeIn(0);
                seekTime.text(formatTime(seekLoc / 1000));
            }
        });
    }

    // Play from the clicked position
    function playFromClickedPos() {
        spotifyPlayer.getCurrentState().then(state => {
            if (state && state.duration) {
                const seekLoc = state.duration * (seekT / sArea.outerWidth());
                spotifyPlayer.seek(seekLoc);
            }
        });
    }

    // Format time in MM:SS format
    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins < 10 ? '0' : ''}${mins}:${secs < 10 ? '0' : ''}${secs}`;
    }

})(jQuery);
