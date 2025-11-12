# Save to Spotify Feature

## Overview

The "Save to Spotify" feature allows visitors to save curated playlists from your WordPress site to their own Spotify accounts with a single click. The feature includes customizable branding, automatic OAuth scope management, and seamless integration with the existing plugin infrastructure.

## Features

- **One-Click Save**: Visitors can save playlists with a single button click
- **OAuth Integration**: Automatic authentication with proper scope management
- **Customizable Branding**: Configure title/description templates with placeholders
- **Cover Image Upload**: Optionally upload the playlist's featured image as the Spotify playlist cover
- **Idempotent Operations**: Subsequent saves update existing playlists instead of creating duplicates
- **Batch Processing**: Efficiently handles playlists with 100+ tracks
- **Rate Limiting**: Automatic retry with exponential backoff for Spotify API rate limits
- **Accessibility**: Full ARIA support and keyboard navigation
- **Progress Indication**: Visual feedback with overlay and inline messages

## Configuration

### Admin Settings

Navigate to **Spfy Playlists → Settings → General Settings** to configure:

1. **Enable "Save to Spotify"**: Toggle the feature on/off
2. **Default Visibility**: Choose "Public" or "Private" for saved playlists
3. **Playlist Title Template**: Customize the title with placeholders:
   - `{{playlistTitle}}` – Original playlist title
   - `{{siteName}}` – Your site name
   - Example: `{{playlistTitle}} – {{siteName}}`
4. **Playlist Description Template**: Customize the description with placeholders:
   - `{{playlistTitle}}` – Original playlist title
   - `{{siteName}}` – Your site name
   - `{{playlistExcerpt}}` – Playlist excerpt (first 20 words)
5. **Use Cover Image**: Enable to upload the featured image as the playlist cover
6. **Button Label**: Customize the button text (default: "Save to Spotify")

### Required Spotify Scopes

The feature automatically requests the following scopes based on the configuration:

- `playlist-modify-public` – For public playlists
- `playlist-modify-private` – For private playlists
- `ugc-image-upload` – When cover image upload is enabled

## Usage

### Template Integration

The save button is automatically added to the playlist template footer. To add it elsewhere in your template:

```php
<?php
if ( function_exists( 'bspfy_render_save_button' ) ) {
    bspfy_render_save_button();
}
?>
```

### Shortcode

Use the shortcode in posts, pages, or widgets:

```
[bspfy_save_playlist id="123" label="Save to Spotify" visibility="public" use_cover="true"]
```

**Parameters:**
- `id` (optional): Playlist post ID. Defaults to current post.
- `label` (optional): Button text. Defaults to setting or "Save to Spotify".
- `visibility` (optional): "public" or "private". Defaults to setting.
- `use_cover` (optional): "true" or "false". Defaults to setting.

### Gutenberg Block

Add the "Save Playlist" block from the block inserter:

1. Click the `+` button to add a new block
2. Search for "Save Playlist"
3. Configure the block settings in the sidebar

**Block Settings:**
- Playlist ID
- Button Label
- Visibility (Public/Private)
- Use Cover Image

## Technical Details

### API Endpoints

#### POST `/wp-json/bspfy/v1/playlist/save`

Save a playlist to the authenticated user's Spotify account.

**Request Body:**
```json
{
  "post_id": 123,
  "visibility": "public",
  "title": "My Playlist – Site Name",
  "description": "Curated playlist description",
  "use_cover": true
}
```

**Success Response (200):**
```json
{
  "success": true,
  "spotify_playlist_id": "6Abc123...",
  "spotify_playlist_url": "https://open.spotify.com/playlist/6Abc123...",
  "added": 47,
  "skipped": 0
}
```

**Error Responses:**
- `401`: Not authenticated or missing scope
- `404`: Playlist not found or empty
- `429`: Rate limited (includes `retry_after` in response)
- `500`: Spotify API error

#### GET `/wp-json/bspfy/v1/playlist/preview?id=123`

Get preview data for a playlist (for UI prefill).

**Success Response (200):**
```json
{
  "success": true,
  "title": "My Playlist – Site Name",
  "description": "Curated by...",
  "track_count": 47,
  "has_cover": true
}
```

### Playlist Mapping

The plugin stores a mapping between WordPress playlist posts and Spotify playlists in user meta:

- **Meta Key**: `bspfy_user_pl_{post_id}`
- **Meta Value**: Spotify playlist ID

This enables idempotent operations – subsequent saves will update the existing playlist instead of creating a new one.

### Rate Limiting

The plugin handles Spotify's rate limiting (HTTP 429) automatically:

1. Read the `Retry-After` header
2. Wait the specified time (with 2-second minimum)
3. Retry the request once
4. Return a graceful error if still rate limited

### Cover Image Processing

When cover image upload is enabled:

1. Retrieves the playlist's featured image
2. Converts to JPEG format
3. Resizes to 640x640 pixels
4. Compresses to fit within Spotify's 256KB limit
5. Uploads to Spotify using base64 encoding

If the upload fails, the playlist is still created without a cover image.

## User Experience

### Flow

1. User clicks "Save to Spotify" button
2. If not authenticated:
   - OAuth popup opens
   - User authorizes the required scopes
   - Popup closes automatically on success
3. Overlay shows progress: "Creating your playlist..."
4. On success:
   - Success message displays
   - "Open in Spotify" link appears
   - Track count shows (e.g., "47 tracks added, 3 already existed")
5. On error:
   - Inline error message displays
   - User can retry if needed

### Offline Handling

If the user is offline:
- Overlay briefly shows
- Inline message: "You're offline. Please reconnect."
- No stuck overlays

### Error Messages

- **Not authenticated**: "Authentication failed. Please try again."
- **Empty playlist**: "This playlist is empty."
- **Playlist not found**: "Playlist not found."
- **Rate limited**: "Too many requests. Please try again later."
- **Cover upload failed**: Continues without cover (logged in debug mode)

## Development

### Filters

**`bspfy_oauth_scopes`**  
Modify the OAuth scopes requested:
```php
add_filter( 'bspfy_oauth_scopes', function( $scopes, $context ) {
    if ( $context === 'save_playlist_with_image' ) {
        // Add custom scope
        $scopes[] = 'playlist-read-private';
    }
    return $scopes;
}, 10, 2 );
```

**`bspfy_should_enqueue_public`**  
Control when public assets are loaded:
```php
add_filter( 'bspfy_should_enqueue_public', function( $should ) {
    // Force enable on custom page
    if ( is_page( 'playlists' ) ) {
        return true;
    }
    return $should;
} );
```

### Debug Mode

Enable debug mode in `wp-config.php`:
```php
define( 'BSPFY_DEBUG', true );
```

Or via Settings → Tools & Debug → Enable debugging.

Debug logs will include:
- OAuth token exchange (with tokens masked)
- Spotify API requests/responses
- Cover image processing
- Rate limiting events

## Troubleshooting

### Button Not Appearing

1. Check that the feature is enabled in Settings
2. Verify you're viewing a `playlist` post type
3. Ensure the template includes `bspfy_render_save_button()`
4. Check browser console for JavaScript errors

### Authentication Fails

1. Verify Spotify App credentials are correct
2. Check that redirect URI is properly configured in Spotify Dashboard
3. Enable debug mode to see detailed OAuth errors
4. Ensure cookies are not blocked

### Playlist Not Saving

1. Check debug logs for API errors
2. Verify the playlist has tracks (`_playlist_tracks` meta)
3. Ensure user has granted the required scopes
4. Check for Spotify API outages

### Cover Image Not Uploading

1. Verify the playlist has a featured image
2. Check image is accessible (not broken link)
3. Ensure image can be converted to JPEG
4. Check debug logs for compression errors

If the cover upload fails, the playlist will still be created successfully.

## Performance

- **Batch Processing**: Tracks are added in batches of 100 to minimize API calls
- **Caching**: OAuth tokens are cached and reused
- **Idempotency**: Duplicate saves update existing playlists
- **Single Flight**: Auth requests are deduplicated to prevent race conditions

## Security

- **No Secrets in Browser**: All API credentials stay server-side
- **PKCE Flow**: Secure authorization without client secret exposure
- **httpOnly Cookies**: Auth tokens stored securely
- **Nonce Verification**: All REST requests validated
- **Input Sanitization**: All user input sanitized server-side
- **Scope Validation**: Only requested scopes are granted

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- JavaScript required
- Cookies required (for authentication)
- Popups must be allowed (for OAuth)

## License

This feature is part of the BeTA iT – Spotify Playlist plugin and is licensed under GPL v2 or later.
