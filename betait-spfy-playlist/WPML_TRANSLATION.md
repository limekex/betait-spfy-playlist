# WPML Translation Support

## Overview

BeTA iT – Spotify Playlist Plugin is fully compatible with WPML (WordPress Multilingual Plugin). This document explains how the plugin handles translations for multilingual WordPress sites.

## What Gets Translated

### Content Fields (Translated)
- **Playlist Title** - The post title is translated using WPML's standard post translation
- **Playlist Description** (`_playlist_description`) - The user-facing description shown on the frontend
- **Genre Taxonomy** - Genre terms can be translated for each language

### Metadata Fields (Copied, Not Translated)
These fields contain Spotify API references and technical data that should remain identical across all language versions:

- **`_playlist_tracks`** - JSON array containing Spotify track IDs, URIs, and metadata
- **`_playlist_spotify_title_template`** - Template for exporting to Spotify
- **`_playlist_spotify_description_template`** - Template for playlist description when exporting
- **`_playlist_spotify_use_cover`** - Boolean flag for custom cover usage
- **`_playlist_spotify_image_id`** - WordPress attachment ID for custom cover image

## Configuration

The plugin includes a `wpml-config.xml` file in the plugin root that automatically configures WPML to handle translations correctly. No additional configuration is required.

### WPML Config Structure

```xml
<wpml-config>
    <custom-types>
        <custom-type translate="1">playlist</custom-type>
    </custom-types>
    
    <taxonomies>
        <taxonomy translate="1">genre</taxonomy>
    </taxonomies>
    
    <custom-fields>
        <!-- Translatable -->
        <custom-field action="translate">_playlist_description</custom-field>
        
        <!-- Copy only (Spotify references) -->
        <custom-field action="copy">_playlist_tracks</custom-field>
        <custom-field action="copy">_playlist_spotify_title_template</custom-field>
        <custom-field action="copy">_playlist_spotify_description_template</custom-field>
        <custom-field action="copy">_playlist_spotify_use_cover</custom-field>
        <custom-field action="copy">_playlist_spotify_image_id</custom-field>
    </custom-fields>
</wpml-config>
```

## How to Translate a Playlist

1. **Create or edit a playlist** in the default language
2. **Add tracks** from Spotify (these will be copied to all translations)
3. **Write the description** in the default language
4. **Assign genres** (these can be translated separately)
5. **Go to WPML > Translation Management** or use the language switcher in the post edit screen
6. **Create a translation** for the target language
7. In the translation editor:
   - Translate the **playlist title**
   - Translate the **playlist description** 
   - The **Spotify tracks remain the same** (automatically copied)
   - The **metadata remains the same** (automatically copied)
8. **Publish the translation**

## Expected Behavior

### When You Translate a Playlist:

✅ **Title** - Translatable (e.g., "Summer Hits" → "Été Hits")  
✅ **Description** - Translatable (e.g., "Best summer songs" → "Meilleures chansons d'été")  
✅ **Genres** - Translatable taxonomy terms  
✅ **Spotify Track References** - Copied identically (same tracks in all languages)  
✅ **Export Settings** - Copied identically (same templates and cover image)

### What Stays the Same Across Languages:

- The actual Spotify track URIs and IDs
- Track metadata (artist names, album names, etc. from Spotify API)
- Custom cover image selection
- Export title/description templates
- All Spotify-related settings

## Testing Your Translation

1. Create a playlist in your default language
2. Add some Spotify tracks to it
3. Write a description
4. Create a translation using WPML
5. Verify in the translation that:
   - The description field is available for translation
   - The track list shows the same tracks (not editable in translation)
   - The Spotify metadata fields are not in the translation editor (automatically copied)

## Troubleshooting

### Problem: Tracks are missing in translated playlist
**Solution:** The tracks are stored in `_playlist_tracks` metadata which is set to "copy" mode. WPML should automatically copy this field when you create a translation. If tracks are missing, check:
- The `wpml-config.xml` file exists in the plugin root
- WPML Translation Management settings include custom fields
- Clear WPML cache and try again

### Problem: Description is not translatable
**Solution:** The `_playlist_description` field should appear in the WPML translation editor. Check:
- The `wpml-config.xml` file is properly formatted
- WPML is updated to the latest version
- The field has a value in the original post

### Problem: Genres are not translating
**Solution:** Make sure:
- The genre taxonomy is registered as translatable in WPML settings
- You've translated the genre terms separately (WPML > Taxonomy Translation)

## WPML Requirements

- **WPML Multilingual CMS** (version 4.0 or higher recommended)
- **WPML Translation Management** (for translating custom fields)
- **WPML String Translation** (optional, for admin interface translations)

## Support

For WPML-specific issues, refer to:
- [WPML Documentation](https://wpml.org/documentation/)
- [Translating Custom Post Types](https://wpml.org/documentation/getting-started-guide/translating-custom-post-types/)
- [Translating Custom Fields](https://wpml.org/documentation/support/wpml-how-to-translate-custom-fields/)

For plugin-specific issues, open an issue in the plugin repository.
