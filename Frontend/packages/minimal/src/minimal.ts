/**
 * minimal.ts — minimal theme frontend entry.
 *
 * Loaded only on minimal-themed pages (FrontendLayout resolves the built
 * entry from the Vite manifest when the active theme is 'minimal'). The
 * stylesheet is the combined starter base + minimal component styles; the
 * JS below wires the two behaviours the minimal shell needs beyond the
 * starter's (whose scripts stay untouched):
 *
 *   1. Back-to-top button (shows after scrolling, smooth-scrolls up) —
 *      the mobile menu is the CSS-only checkbox pattern (no JS).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax — the
 * build additionally transpiles with target 'es2015' as a belt-and-braces
 * guarantee (same as main.ts).
 */

import './minimal/index.css';

/** Back to top — visible after 400px of scroll, smooth-scrolls to the top. */
const initBackToTop = (): void => {
  const btn = document.getElementById('min-backtotop');
  if (!btn) {
    return;
  }

  const onScroll = function () {
    if (window.scrollY > 400) {
      btn.classList.add('is-visible');
    } else {
      btn.classList.remove('is-visible');
    }
  };

  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  window.addEventListener('scroll', onScroll, { passive: true });

  // Initial visibility check — deferred past the first paint via a double
  // requestAnimationFrame: the read runs in the frame after the renderer
  // has laid out and painted the first frame, so window.scrollY cannot
  // force layout on the load critical path (DevTools/Lighthouse "forced
  // reflow" insight).
  requestAnimationFrame(function () {
    requestAnimationFrame(onScroll);
  });
};

const onDomReady = (): void => {
  initBackToTop();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}
