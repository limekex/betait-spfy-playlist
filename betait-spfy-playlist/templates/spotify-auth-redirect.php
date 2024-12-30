<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to log debug information to debug.log if debugging is enabled.
function log_debug( $message ) {
    if ( get_option( 'bspfy_debug', 0 ) ) {
        error_log( '[BeTA iT - Spfy Playlist oAuth Debug] - ' . $message );
    }
}

// Log that the redirect template was loaded.
log_debug( 'Spotify Auth Redirect template loaded.' );
?>
<script>
    const debugEnabled = window.bspfyDebug?.debug || false;

    function logDebug(message, data = null) {
        if (debugEnabled) {
            console.log(`[BeTA iT - Spfy Playlist oauth Debug] ${message}`);
            if (data) {
                console.log(data);
            }
        }
    }

    // Extract access token from the URL fragment.
    const hash = window.location.hash.substring(1);
    const params = new URLSearchParams(hash);
    const accessToken = params.get('access_token');

    if (accessToken) {
        // Log and store the access token in localStorage.
        logDebug('Access token retrieved:', { accessToken });
        localStorage.setItem('spotifyAccessToken', accessToken);

        // Send the token to the server (optional, for storing in user meta).
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'save_spotify_access_token',
                access_token: accessToken,
            }),
        })
            .then(response => response.json())
            .then(data => {
                logDebug('Access token saved to the server:', data);
            })
            .catch(error => {
                logDebug('Error saving access token to the server:', error);
            });

        // Redirect to the original page.
        const redirectUrl = localStorage.getItem('spotifyRedirectBack') || '/';
        logDebug('Redirecting to URL:', { redirectUrl });
        localStorage.removeItem('spotifyRedirectBack');
        setTimeout(() => {
            window.location.href = redirectUrl;
        }, 1000); // Delay for visibility
    } else {
        // Log error and redirect to home if no token is found.
        logDebug('No access token found in URL fragment.');
        window.location.href = '/?err=No access token found in URL fragment';
    }
</script>
