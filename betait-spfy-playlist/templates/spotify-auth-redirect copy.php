<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to log debug information to debug.log if debugging is enabled.
private function log_debug( $message ) {
    if ( get_option( 'bspfy_debug', 0 ) ) {
        error_log( '[BeTA iT - Spfy Playlist oAuth Debug] - Loaded ' . $message );
    }
}

// Check for the access token in the URL.
if ( ! isset( $_GET['access_token'] ) ) {
    $this->log_debug( 'No access_token found. Redirecting to home.' );
    wp_redirect( home_url() );
    exit;
}

// Log the entire query string for debugging purposes.
$this->log_debug( 'Full query string: ' . print_r( $_GET, true ) );

// Log and sanitize the token.
$accessToken = sanitize_text_field( $_GET['access_token'] );
$this->log_debug( 'Access token: ' . $accessToken );

// Optionally store the access token in the database (e.g., user meta).
$current_user_id = get_current_user_id();
if ( $current_user_id ) {
    update_user_meta( $current_user_id, 'spotify_access_token', $accessToken );
    $this->log_debug( 'Access token saved to user meta for user ID: ' . $current_user_id );
} else {
    $this->log_debug( 'No user logged in. Access token not saved to user meta.' );
}

// Output a script to store the token in localStorage and redirect.
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

    const accessToken = "<?php echo esc_js( $accessToken ); ?>";
    logDebug('Access token retrieved:', { accessToken });

    localStorage.setItem('spotifyAccessToken', accessToken);
    logDebug('Access token saved to localStorage.');

    const redirectUrl = localStorage.getItem('spotifyRedirectBack') || '/';
    logDebug('Redirecting to URL:', { redirectUrl });

    // Delay redirect for visibility.
    setTimeout(() => {
        window.location.href = redirectUrl;
    }, 3000); // 3-second delay for debugging
</script>
