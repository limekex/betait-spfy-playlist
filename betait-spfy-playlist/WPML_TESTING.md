# WPML Translation Testing Guide

## Prerequisites

1. WordPress installation with WPML installed and activated:
   - WPML Multilingual CMS
   - WPML Translation Management (recommended)
   - WPML String Translation (optional)
2. At least two languages configured in WPML (e.g., English and Norwegian)
3. BeTA iT – Spotify Playlist Plugin installed and activated
4. Spotify API credentials configured

## Test Scenario 1: Basic Translation

### Step 1: Create a Playlist in Default Language
1. Go to **Playlists → Add New**
2. Enter title: "Summer Hits 2024"
3. Add a description: "The best summer tracks to enjoy the sun"
4. Search and add 3-5 Spotify tracks
5. Assign a genre (e.g., "Pop")
6. **Publish** the playlist

### Step 2: Create Translation
1. In the post editor, find the **WPML language switcher** (usually in the sidebar)
2. Click the "+" icon next to your secondary language (e.g., Norwegian)
3. Choose to translate manually or use the translation editor
4. In the translation:
   - Translate the **title**: "Sommerhits 2024"
   - Translate the **description**: "De beste sommerlåtene for å nyte solen"
   - The **track list should remain the same** (automatically copied)
5. **Publish** the translation

### Expected Results
✅ Both language versions exist  
✅ Title is translated  
✅ Description is translated  
✅ Both versions have the **same Spotify tracks**  
✅ Both versions have the **same track order**  
✅ Genre term can be translated separately

## Test Scenario 2: Verify Metadata Preservation

### Step 1: Check Metadata in Original
1. Edit the original playlist
2. Note the following:
   - Number of tracks
   - Track order
   - First and last track names
   - Custom cover image (if set)
   - Export settings (if configured)

### Step 2: Check Metadata in Translation
1. Edit the translated playlist
2. Verify that:
   - **Track count is identical**
   - **Track order is identical**
   - **Track URIs are identical** (same Spotify references)
   - **Custom cover image is the same** (copied)
   - **Export settings are the same** (copied)

### Expected Results
✅ `_playlist_tracks` metadata is identical in both versions  
✅ `_playlist_spotify_image_id` is identical  
✅ `_playlist_spotify_title_template` is identical  
✅ `_playlist_spotify_description_template` is identical  
✅ `_playlist_spotify_use_cover` is identical

## Test Scenario 3: Taxonomy Translation

### Step 1: Translate Genre Terms
1. Go to **WPML → Taxonomy Translation**
2. Find the "Genre" taxonomy
3. Translate genre terms (e.g., "Pop" → "Pop", "Rock" → "Rock", "Jazz" → "Jazz")
4. Some genres might have localized names, translate those

### Step 2: Verify in Frontend
1. Visit the playlist page in the default language
2. Check that the genre is displayed
3. Switch to the translated language
4. Verify the genre term is translated (if you provided a translation)

### Expected Results
✅ Genre taxonomy terms are translatable  
✅ Genre assignments are preserved across translations

## Test Scenario 4: Frontend Display

### Step 1: View Original Playlist
1. Visit the original playlist page on the frontend
2. Note:
   - Title display
   - Description display
   - Track list display
   - Genre display
   - Play functionality

### Step 2: View Translated Playlist
1. Switch language using WPML language switcher
2. Visit the translated playlist page
3. Verify:
   - **Title is in the translated language**
   - **Description is in the translated language**
   - **Track list is identical** (same tracks, same order)
   - **Play functionality works the same**

### Expected Results
✅ Content is properly localized  
✅ Spotify integration works in both languages  
✅ Same tracks are playable in both versions

## Test Scenario 5: Editing Tracks

### Step 1: Modify Tracks in Original
1. Edit the original playlist
2. Add a new track
3. Remove an existing track
4. Reorder tracks
5. **Save** the playlist

### Step 2: Check Translation
1. Edit the translated playlist
2. Verify changes are **NOT automatically synchronized**
3. Manually update the translated playlist if needed, or
4. Use WPML to update the translation from the original

### Expected Results
⚠️ Track changes in original **do not** automatically update translations  
ℹ️ This is expected behavior - you need to manually sync or re-translate

## Test Scenario 6: WPML Configuration Validation

### Step 1: Check wpml-config.xml
1. Navigate to `/wp-content/plugins/betait-spfy-playlist/`
2. Verify `wpml-config.xml` exists
3. Open the file and verify it contains:
   - `<custom-type translate="1">playlist</custom-type>`
   - `<taxonomy translate="1">genre</taxonomy>`
   - Custom field configurations for translate and copy actions

### Step 2: WPML Settings Check
1. Go to **WPML → Settings**
2. Find **Post Types** section
3. Verify "Playlist" is set to "Translatable"
4. Find **Taxonomies** section
5. Verify "Genre" is set to "Translatable"
6. Go to **WPML → Settings → Custom Fields Translation**
7. Verify the following fields appear:
   - `_playlist_description` (should be translatable)
   - `_playlist_tracks` (should be copy)
   - Other Spotify metadata fields (should be copy)

### Expected Results
✅ wpml-config.xml exists and is properly formatted  
✅ WPML recognizes the plugin's configuration  
✅ Custom fields are configured correctly

## Common Issues and Solutions

### Issue: Tracks are missing in translation
**Cause:** The `_playlist_tracks` field is not being copied  
**Solution:** 
- Check that `wpml-config.xml` exists and is valid
- Clear WPML cache: **WPML → Support → Troubleshooting → Clear cache**
- Re-save the original post and recreate the translation

### Issue: Description is not translatable
**Cause:** The `_playlist_description` field is not recognized by WPML  
**Solution:**
- Verify `wpml-config.xml` includes: `<custom-field action="translate">_playlist_description</custom-field>`
- Update WPML to the latest version
- Go to **WPML → Settings → Custom Fields Translation** and ensure the field is visible

### Issue: Genre terms are not translating
**Cause:** Genre taxonomy needs to be translated separately  
**Solution:**
- Go to **WPML → Taxonomy Translation**
- Translate each genre term individually
- The translations will then appear in translated playlists

## Success Criteria

After completing all tests, you should be able to:

1. ✅ Create playlists in multiple languages
2. ✅ Translate playlist titles and descriptions
3. ✅ Keep Spotify track references consistent across languages
4. ✅ Translate genre taxonomy terms
5. ✅ Display localized content on the frontend
6. ✅ Play the same Spotify tracks in all language versions
7. ✅ Maintain all Spotify metadata across translations
8. ✅ Export playlists to Spotify from any language version

## Notes

- The primary goal of this WPML integration is to allow content localization (titles, descriptions) while keeping Spotify references intact
- All language versions of a playlist point to the same Spotify tracks
- Users can provide context-appropriate descriptions in each language while maintaining the same musical content
- This approach is ideal for multilingual sites where the music selection is universal but the presentation needs localization
