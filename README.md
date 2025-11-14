# BSPFY – Spotify Playlist Plugin (v2)

A lightweight, organized foundation for playing Spotify tracks/playlists inside WordPress using the Spotify Web API + Web Playback SDK with secure OAuth 2.0 PKCE.

## Contents

This repo includes:

* `.gitignore` — Files excluded from version control.
* `CHANGELOG.md` — Project changes.
* `README.md` — The file you’re reading.
* A `betait-spfy-playlist` directory — the full, executable WordPress plugin source.

## Features

* Secure **OAuth 2.0 PKCE** via WP REST endpoints under `/wp-json/bspfy/v1/oauth/*`.
* **httpOnly** cookie token storage (configurable SameSite/Secure).
* **Web Playback SDK** for in-browser playback *(Spotify Premium required for end users)*.
* **Save to Spotify** – Allow visitors to save playlists to their own Spotify accounts with customizable branding.
* Role/membership gate via `bspfy_can_play` filter.
* Uniform error handling for 401/403/429 with silent refresh + backoff.
* Debug flag (`BSPFY_DEBUG`) with masked logs.

## Installation

Copy the `betait-spfy-playlist` folder into `wp-content/plugins/` and activate it in **Plugins**.

If you fork/rename the plugin, update identifiers accordingly (examples):
* rename folder `betait-spfy-playlist` → `example-me`
* change function/variable prefixes `bspfy_` → `example_`
* change text domain `betait-spfy-playlist` → `example-me`
* change main class `Betait_Spfy_Playlist` → `Example_Me`

## Quick Setup

1. **Create a Spotify app** and add a redirect URI:  
   `https://yourdomain.com/wp-json/bspfy/v1/oauth/callback`
2. In `wp-config.php`, optionally set:
   ```php
   define('BSPFY_DEBUG', true);
   define('BSPFY_STRICT_SAMESITE', true);
   define('BSPFY_REQUIRE_PREMIUM', true);
3. Place a connect/play UI in your theme/admin as needed; the client helper is available via `window.bspfyAuth`.

## Recommended Tools

### i18n Tools

The plugin uses the `betait-spfy-playlist` text domain for translations. Suggested tools:

* [Poedit](http://www.poedit.net/)
* [makepot](http://i18n.svn.wordpress.org/tools/trunk/)
* [i18n](https://github.com/grappler/i18n)

## License

This plugin is licensed under the GPL v2 or later.

> This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

> This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

A copy of the license is included in the plugin root as `LICENSE`.

## Important Notes

### Licensing

If you include third-party code that isn’t GPL v2 compatible, ensure it’s GPL v3 compatible or adjust accordingly.

### Includes

If you add classes or third-party libraries, use:

* `betait-spfy-playlist/includes` — shared functionality
* `betait-spfy-playlist/admin` — admin-only functionality
* `betait-spfy-playlist/public` — public-facing functionality

Register hooks via the loader class (`Betait_Spfy_Playlist_Loader`).

### What About Other Tools?

Build/update tools (Composer, Grunt, GitHub Updater, etc.) aren’t bundled to keep the core minimal. Add them in your fork as needed.

## Credits

Built on the WordPress Plugin Boilerplate (Tom McFarlin → Devin Vinson) and adapted for Spotify playback.  
For questions or contributions, open an issue in this repository.
