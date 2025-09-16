# Changelog

All notable changes to **BSPFY – Spotify Playlist Plugin** are documented in this file.  
This project follows **Semantic Versioning** and a simplified *Keep a Changelog* style.

## [Unreleased]
### Added
- Admin “Health Check” (scopes, Premium status, autoplay policy, cookie checks) — planned.
- Optional server‑side token refresh (cron) — under consideration.
- Device/queue ownership policy — under consideration.

## [2.5.0]
### Added
- Overlay preloader on loads towards Spotify API
- Tools & Debug-tab in settings with “Normalize now” (nonce-protection) & oAuth Health status

### Fix
- Fix: Normalize legacy mojibake (Ã/â/Â/�) og wrongful u00xx artifacts in saved _playlist_tracks.

## [2.0.0] - 2025-09-13
### Added
- OAuth 2.0 **PKCE** flow via `/wp-json/bspfy/v1/oauth/*`.
- **httpOnly** cookie token storage with SameSite/Secure flags.
- **Web Playback SDK** integration with on‑demand init (requires user gesture & visible container).
- Role/membership gating via `bspfy_can_play` filter.
- Uniform UI error overlays for **401/403/429/NO_PREMIUM** with silent refresh & exponential backoff.
- Dock‑style player UI with **volume/device popovers** (toggle on same button, ESC & outside click).
- Accessibility: ARIA roles/labels, focus handling, live regions.
- Debug logging via `BSPFY_DEBUG` (masked tokens) + "Send diagnostics".

### Changed
- Stabilized DOM/markup; CSS Grid with mobile fallback.
- Improved **Next track** reliability (race‑guards & debounces around `player_state_changed`).

### Fixed
- **Enter** in focused search inputs now triggers search (avoids accidental WP post save).
- Mitigations for Chrome **CORS/SameSite** edge cases with recommended cookie settings.

### Removed
- LocalStorage token handling (migrated to httpOnly cookies).

### Security
- Hardened cookie defaults (SameSite/SECURE on HTTPS).

### Breaking
- Consolidated prefixes to `bspfy_*` (PHP) and `window.bspfy*` (JS).
- Web playback requires **Spotify Premium** (`BSPFY_REQUIRE_PREMIUM`).

## [1.x] - Legacy
- Early prototype using Web API with minimal playback logic.
- Known issues: inconsistent next‑track behavior; limited error handling & a11y.
