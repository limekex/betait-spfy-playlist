(function( $ ) {
	'use strict';


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
                    window.location.href = redirectBackUrl; // Redirect to original page
                } else {
                    window.location.href = '/?error=no page saved'; // Fallback to home if no original page was saved
                }
            } else {
                alert('Failed to authenticate with Spotify. Please try again.');
                window.location.href = '/?error=fail to autheticate'; // Redirect to home in case of failure
            }
            return; // Stop further execution
        }


})( jQuery );
