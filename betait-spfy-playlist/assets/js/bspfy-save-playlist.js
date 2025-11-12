/* global window, document, bspfyOverlay, bspfyAuth */
(function () {
  'use strict';

  // Wait for DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    const buttons = document.querySelectorAll('.bspfy-save-playlist-btn');
    buttons.forEach(btn => {
      btn.addEventListener('click', handleSaveClick);
    });
  }

  async function handleSaveClick(e) {
    e.preventDefault();
    const btn = e.currentTarget;

    // Get data from button
    const postId = btn.dataset.postId;
    const visibility = btn.dataset.visibility || 'public';
    const title = btn.dataset.title || '';
    const description = btn.dataset.description || '';
    const useCover = btn.dataset.useCover === 'true';

    if (!postId) {
      showError(__('Playlist ID is missing.', 'betait-spfy-playlist'));
      return;
    }

    // Disable button
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    const originalText = btn.textContent;
    btn.textContent = __('Saving…', 'betait-spfy-playlist');

    try {
      // Show overlay
      if (window.bspfyOverlay) {
        window.bspfyOverlay.show({
          title: __('Saving to Spotify', 'betait-spfy-playlist'),
          busyText: __('Creating your playlist…', 'betait-spfy-playlist'),
          reason: 'save-playlist'
        });
      }

      // Ensure we have an access token
      let accessToken;
      try {
        accessToken = await window.bspfyAuth.ensureAccessToken({ interactive: true });
      } catch (e) {
        throw new Error(__('Authentication failed. Please try again.', 'betait-spfy-playlist'));
      }

      // Call the save endpoint
      const restRoot = window.bspfyDebug?.rest_root || (window.location.origin + '/wp-json');
      const wpNonce = window.bspfyDebug?.rest_nonce || window.wpApiSettings?.nonce || '';

      const response = await fetch(`${restRoot}/bspfy/v1/playlist/save`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': wpNonce
        },
        credentials: 'include',
        body: JSON.stringify({
          post_id: parseInt(postId, 10),
          visibility: visibility,
          title: title,
          description: description,
          use_cover: useCover
        })
      });

      const data = await response.json();

      if (window.bspfyOverlay) {
        window.bspfyOverlay.hide();
      }

      if (!response.ok || !data.success) {
        const errorMsg = data.message || data.error || __('Failed to save playlist.', 'betait-spfy-playlist');
        throw new Error(errorMsg);
      }

      // Success!
      showSuccess(data, btn);

    } catch (error) {
      if (window.bspfyOverlay) {
        window.bspfyOverlay.hide();
      }
      showError(error.message || __('An error occurred while saving the playlist.', 'betait-spfy-playlist'));
      console.error('Save playlist error:', error);
    } finally {
      // Re-enable button
      btn.disabled = false;
      btn.setAttribute('aria-busy', 'false');
      btn.textContent = originalText;
    }
  }

  function showSuccess(data, btn) {
    const msg = __('Playlist saved successfully!', 'betait-spfy-playlist');
    const link = data.spotify_playlist_url;
    
    // Create success message
    const successDiv = document.createElement('div');
    successDiv.className = 'bspfy-save-success';
    successDiv.setAttribute('role', 'status');
    successDiv.setAttribute('aria-live', 'polite');
    
    const msgP = document.createElement('p');
    msgP.textContent = msg;
    successDiv.appendChild(msgP);

    if (link) {
      const linkA = document.createElement('a');
      linkA.href = link;
      linkA.target = '_blank';
      linkA.rel = 'noopener noreferrer';
      linkA.className = 'bspfy-open-spotify-link';
      linkA.textContent = __('Open in Spotify', 'betait-spfy-playlist');
      successDiv.appendChild(linkA);
    }

    if (data.added) {
      const statsP = document.createElement('p');
      statsP.className = 'bspfy-save-stats';
      statsP.textContent = sprintf(
        __('%d tracks added', 'betait-spfy-playlist'),
        data.added
      );
      if (data.skipped) {
        statsP.textContent += ', ' + sprintf(
          __('%d already existed', 'betait-spfy-playlist'),
          data.skipped
        );
      }
      successDiv.appendChild(statsP);
    }

    // Insert after button
    btn.parentNode.insertBefore(successDiv, btn.nextSibling);

    // Auto-remove after 10 seconds
    setTimeout(() => {
      if (successDiv.parentNode) {
        successDiv.parentNode.removeChild(successDiv);
      }
    }, 10000);
  }

  function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'bspfy-save-error';
    errorDiv.setAttribute('role', 'alert');
    errorDiv.textContent = message;

    // Find a suitable container or use body
    const container = document.querySelector('.bspfy-container') || document.body;
    container.insertBefore(errorDiv, container.firstChild);

    // Auto-remove after 8 seconds
    setTimeout(() => {
      if (errorDiv.parentNode) {
        errorDiv.parentNode.removeChild(errorDiv);
      }
    }, 8000);
  }

  // Simple i18n fallback
  function __(text) {
    // In a real implementation, this would use WordPress i18n
    return text;
  }

  function sprintf(format, ...args) {
    let i = 0;
    return format.replace(/%[sd]/g, () => args[i++]);
  }

})();
