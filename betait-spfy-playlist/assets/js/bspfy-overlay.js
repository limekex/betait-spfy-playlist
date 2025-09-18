/* global window, document */
(function () {
  'use strict';

  // Small helper to create elements
  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === 'text') node.textContent = v;
      else if (k === 'html') node.innerHTML = v;
      else node.setAttribute(k, v);
    });
    children.forEach(c => node.appendChild(c));
    return node;
  }

  const state = {
    root: null,
    timers: { delay: null, timeout: null, minshow: null },
    lastTrigger: null,
    onRetry: null,
    openSince: 0,
  };

  function ensureDom() {
    if (state.root) return state.root;

    // Build overlay DOM and append to body
    const bars = Array.from({ length: 7 }, () => el('span', { class: 'bspfy-eq__bar', 'aria-hidden': 'true' }));

    const eq = el('div', { class: 'bspfy-eq', 'aria-hidden': 'true' }, bars);
    const title = el('div', { class: 'bspfy-overlay__title', id: 'bspfy-overlay-title', text: 'Working...' });
    const msgBusy = el('p', { class: 'bspfy-overlay__msg bspfy-overlay__msg--busy', text: 'Connecting to Spotify …' });
    const msgError = el('p', { class: 'bspfy-overlay__msg bspfy-overlay__msg--error', text: 'Ups! Something is wrong. Try again.' });

    const head = el('div', { class: 'bspfy-overlay__head' }, [eq, title]);
    const footer = el('div', { class: 'bspfy-overlay__footer' });
    const btnRetry = el('button', { class: 'bspfy-btn', type: 'button', 'data-action': 'retry', hidden: '' , text: 'Try again.' });
    footer.appendChild(btnRetry);

    const card = el('div', { class: 'bspfy-overlay__card', role: 'document' }, [
      head, msgBusy, msgError, footer
    ]);

    const notes = el('div', { class: 'bspfy-notes', 'aria-hidden': 'true' });

    const root = el('div', {
      class: 'bspfy-overlay',
      role: 'status',
      'aria-live': 'polite',
      'aria-busy': 'false',
      'aria-labelledby': 'bspfy-overlay-title',
      'data-state': 'busy',
      'data-open': 'false'
    }, [notes, card]);

    root.addEventListener('click', (ev) => {
      // Prevent clicks from leaking through
      ev.stopPropagation();
    }, true);

    btnRetry.addEventListener('click', () => {
      if (typeof state.onRetry === 'function') state.onRetry();
    });

    document.addEventListener('keydown', (e) => {
      // Close error overlay on Escape (does not close busy state)
      if (e.key === 'Escape' && state.root && state.root.getAttribute('data-open') === 'true') {
        if (state.root.getAttribute('data-state') === 'error') {
          hide();
        }
      }
    });

    document.body.appendChild(root);
    state.root = root;
    return root;
  }

  function clearTimers() {
    Object.keys(state.timers).forEach(k => {
      if (state.timers[k]) { clearTimeout(state.timers[k]); state.timers[k] = null; }
    });
  }

  function openOverlay({ title = 'Working...', busyText = 'Connecting to Spotify...', reason = '', onRetry = null, showRetry = false } = {}) {
    const root = ensureDom();
    state.onRetry = onRetry;
    state.openSince = performance.now();

    root.setAttribute('aria-busy', 'true');
    root.setAttribute('data-state', 'busy');
    root.setAttribute('data-open', 'true');
    if (reason) root.dataset.reason = reason;

    root.querySelector('.bspfy-overlay__title').textContent = title;
    root.querySelector('.bspfy-overlay__msg--busy').textContent = busyText;

    const retryBtn = root.querySelector('[data-action="retry"]');
    retryBtn.hidden = !showRetry;

    // Do not trap focus: keep focus on the trigger element (if any).
    root.style.display = 'flex';

    window.dispatchEvent(new CustomEvent('bspfy:overlay:show', { detail: { reason } }));
  }

  function show(options = {}) {
    clearTimers();
    openOverlay(options);
  }

  function fail({ title = 'Something is wrong', errorText = 'Something is wrong. Try again.', onRetry = null } = {}) {
    ensureDom();
    state.onRetry = onRetry;
    state.root.setAttribute('data-state', 'error');
    state.root.setAttribute('aria-busy', 'false');
    state.root.querySelector('.bspfy-overlay__title').textContent = title;
    state.root.querySelector('.bspfy-overlay__msg--error').textContent = errorText;
    state.root.querySelector('[data-action="retry"]').hidden = !onRetry;

    window.dispatchEvent(new CustomEvent('bspfy:overlay:error', { detail: { message: errorText } }));
  }

  function hide() {
    clearTimers();
    if (!state.root) return;
    // Ensure minimal visible time to avoid flicker
    const visibleFor = performance.now() - state.openSince;
    const minShow = Math.max(0, (state.minShowMs || 0) - visibleFor);

    setTimeout(() => {
      state.root.setAttribute('data-open', 'false');
      state.root.setAttribute('aria-busy', 'false');
      state.root.style.display = 'none';
      window.dispatchEvent(new CustomEvent('bspfy:overlay:hide'));
    }, minShow);
  }

  /**
   * Delayed auto overlay for long operations:
   * - delayMs: how long to wait before showing overlay (avoid flicker)
   * - minShowMs: ensure overlay is visible at least this long once shown
   * - timeoutMs: hard timeout → fail()
   */
  async function wrap(promiseOrFn, {
    title = 'Working …',
    busyText = 'Working …',
    reason = '',
    delayMs = 300,
    minShowMs = 500,
    timeoutMs = 15000,
    onRetry = null,
    onTimeoutText = 'This took to long, check your network and try again.'
  } = {}) {
    clearTimers();
    const root = ensureDom();
    state.minShowMs = minShowMs;

    const p = (typeof promiseOrFn === 'function') ? promiseOrFn() : promiseOrFn;

    // Show after delay (if still pending)
    state.timers.delay = setTimeout(() => {
      openOverlay({ title, busyText, reason, onRetry: null, showRetry: false });
    }, Math.max(0, delayMs));

    // Hard timeout
    state.timers.timeout = setTimeout(() => {
      // Only show fail if still not resolved
      if (root.getAttribute('data-open') !== 'true') {
        // Force it open in error state
        openOverlay({ title, busyText, reason });
      }
      fail({ title: 'Time-out', errorText: onTimeoutText, onRetry });
    }, Math.max(1000, timeoutMs));

    try {
      const res = await p;
      hide();
      return res;
    } catch (err) {
      // Open overlay (if not already), then show error state
      if (root.getAttribute('data-open') !== 'true') {
        openOverlay({ title, busyText, reason, onRetry });
      }
      fail({ title: 'Error', errorText: (err && err.message) ? err.message : 'Unknown error', onRetry });
      throw err;
    } finally {
      clearTimeout(state.timers.delay);
      clearTimeout(state.timers.timeout);
    }
  }

  window.addEventListener('pagehide', () => window.bspfyOverlay?.hide());
  window.addEventListener('unhandledrejection', () => window.bspfyOverlay?.hide());


  // Public API
  const api = { show, hide, fail, wrap };

  // Attach to global namespace used i prosjektet
  window.bspfyOverlay = api;
})();
