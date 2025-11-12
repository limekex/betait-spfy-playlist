/* global window, document, bspfyOverlay, bspfySaveConfig */
(function () {
  'use strict';

  // Get config
  const REST_ROOT = (window.bspfySaveConfig?.rest_root || (window.location.origin + '/wp-json')).replace(/\/$/, '');
  const WP_NONCE = window.bspfySaveConfig?.rest_nonce || '';

  // Simple auth helper for save playlist (standalone, no admin JS dependency)
  const auth = {
    inflight: null,

    async ensureAccessToken(context) {
      if (this.inflight) return this.inflight;

      this.inflight = (async () => {
        try {
          // Check if we have a token
          const tokenRes = await fetch(`${REST_ROOT}/bspfy/v1/oauth/token`, {
            credentials: 'include',
            cache: 'no-store',
            headers: WP_NONCE ? { 'X-WP-Nonce': WP_NONCE } : {}
          });
          
          const tokenData = await tokenRes.json();
          if (tokenData.authenticated && tokenData.access_token) {
            return tokenData.access_token;
          }

          // Need to authenticate - start auth popup
          const startRes = await fetch(`${REST_ROOT}/bspfy/v1/oauth/start`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              ...(WP_NONCE ? { 'X-WP-Nonce': WP_NONCE } : {})
            },
            credentials: 'include',
            body: JSON.stringify({
              redirectBack: window.location.href,
              context: context
            })
          });

          const startData = await startRes.json();
          if (!startData.authorizeUrl) {
            throw new Error('No authorize URL returned');
          }

          // Open auth popup
          await this.openAuthPopup(startData.authorizeUrl);

          // After auth, get token again
          const tokenRes2 = await fetch(`${REST_ROOT}/bspfy/v1/oauth/token`, {
            credentials: 'include',
            cache: 'no-store',
            headers: WP_NONCE ? { 'X-WP-Nonce': WP_NONCE } : {}
          });
          
          const tokenData2 = await tokenRes2.json();
          if (tokenData2.authenticated && tokenData2.access_token) {
            return tokenData2.access_token;
          }

          throw new Error('Authentication failed');
        } finally {
          this.inflight = null;
        }
      })();

      return this.inflight;
    },

    openAuthPopup(url) {
      return new Promise((resolve, reject) => {
        const w = 520, h = 680;
        const left = window.screenX + (window.outerWidth - w) / 2;
        const top = window.screenY + (window.outerHeight - h) / 2;
        const popup = window.open(url, 'bspfy-auth', `width=${w},height=${h},left=${left},top=${top}`);
        
        if (!popup) {
          reject(new Error('Popup blocked'));
          return;
        }

        const handler = (ev) => {
          if (ev.origin !== window.location.origin) return;
          if (ev.data && ev.data.type === 'bspfy-auth' && ev.data.success) {
            window.removeEventListener('message', handler);
            clearInterval(closedCheck);
            try { popup.close(); } catch (e) {}
            resolve(true);
          }
        };
        window.addEventListener('message', handler);

        const closedCheck = setInterval(() => {
          try {
            if (!popup || popup.closed) {
              clearInterval(closedCheck);
              window.removeEventListener('message', handler);
              reject(new Error('Popup closed'));
            }
          } catch (e) {}
        }, 1000);
      });
    }
  };

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

      // Determine context based on visibility and cover
      let context = 'core';
      if (useCover) {
        context = 'save_playlist_with_image';
      } else if (visibility === 'public') {
        context = 'save_playlist_public';
      } else {
        context = 'save_playlist_private';
      }

      // Ensure we have an access token with proper scopes
      await auth.ensureAccessToken(context);

      // Call the save endpoint

      const response = await fetch(`${REST_ROOT}/bspfy/v1/playlist/save`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(WP_NONCE ? { 'X-WP-Nonce': WP_NONCE } : {})
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
