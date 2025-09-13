=== BSPFY – Spotify Playlist Plugin ===
Contributors: limekex
Donate link: https://betait.no/
Tags: spotify, player, audio, music, playlists, oauth, pkce, web-playback-sdk
Requires at least: 6.2
Tested up to: 6.6
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Play Spotify tracks and playlists in WordPress via Web Playback SDK with secure OAuth (PKCE), httpOnly cookies, and role checks.

== Description ==

BSPFY lets logged-in WordPress users play Spotify tracks and playlists directly in the browser using the Spotify Web API and Web Playback SDK.  
It uses OAuth 2.0 PKCE with httpOnly cookies for secure token handling, supports role/membership gating, and provides clear UI error handling (401/403/429, Premium required).

**Highlights**
- OAuth 2.0 **PKCE** via WP REST endpoints under `/wp-json/bspfy/v1/oauth/*`
- **httpOnly** cookie storage (configurable SameSite/Secure)
- **Web Playback SDK** (requires Spotify Premium for end users)
- Role/membership gate via `bspfy_can_play` filter
- Uniform error overlays + silent refresh and exponential backoff
- Accessibility-minded UI (ARIA, focus states, live regions)

== Installation ==

1. Upload the `betait-spfy-playlist` folder to `/wp-content/plugins/`.
2. Activate **BSPFY – Spotify Playlist Plugin** in **Plugins**.
3. Create a Spotify app and add the redirect URI:  
   `https://yourdomain.com/wp-json/bspfy/v1/oauth/callback`
4. (Optional) In `wp-config.php` set:
   ```php
   define('BSPFY_DEBUG', true);
   define('BSPFY_STRICT_SAMESITE', true);
   define('BSPFY_REQUIRE_PREMIUM', true);
5. Place/connect the UI where needed (theme or admin). The client helper is exposed as `window.bspfyAuth`.
6. Ensure site is served over **HTTPS** (recommended for `Secure` cookies and Spotify auth).

== Frequently Asked Questions ==

= Do users need Spotify Premium? =
Yes. Web Playback SDK requires a Spotify Premium account for the end user.

= Playback doesn’t start on first click—why? =
Most browsers require a **user gesture** for audio. Use the Play button to satisfy autoplay policy.

= Where are tokens stored? =
Short-lived tokens are handled via **httpOnly cookies** (not localStorage). Refresh is performed server-side via REST.

== Screenshots ==

1. Dock-style player with cover, transport controls, and title/album.
2. Volume and device popovers (toggle on same button; ESC and outside click close).

== Changelog ==

= 2.0.0 =
* OAuth 2.0 PKCE, httpOnly cookies, and Web Playback SDK integration.
* Role/membership gate, uniform error overlays, and silent refresh/backoff.
* Stabilized DOM/markup; improved next-track reliability; accessibility updates.

= 1.x =
* Early prototype with minimal playback logic.

== Upgrade Notice ==

= 2.0.0 =
Major update with PKCE, secure cookies, improved reliability, and UI error handling. Review settings and Premium requirement.

= 1.0 =
Initial public release.

== Arbitrary section ==

= Security & Privacy =
- Uses server-side refresh and httpOnly cookies to reduce token exposure.
- Avoids localStorage for sensitive auth data.
- Recommend HTTPS + `Secure` cookies in production.

== A brief Markdown Example ==

Quick config in `wp-config.php`:

```php
define('BSPFY_DEBUG', true);           // Verbose masked logs
define('BSPFY_STRICT_SAMESITE', true); // Stricter cookie policy (use HTTPS)
define('BSPFY_REQUIRE_PREMIUM', true); // Gate UI unless user is Premium

Add role gating via filter:
add_filter('bspfy_can_play', function ($allowed, $wp_user) {
    return in_array('member', (array) $wp_user->roles, true) ? true : current_user_can('read');
}, 10, 2);
