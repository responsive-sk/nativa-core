/**
 * magazine.ts — magazine theme frontend entry.
 *
 * Loaded only on magazine-themed pages (FrontendLayout resolves the built
 * entry from the Vite manifest when the active theme is 'magazine'). The
 * stylesheet is the combined starter base + magazine component styles; the
 * JS below wires the two behaviours the magazine shell needs beyond the
 * starter's (whose scripts stay untouched):
 *
 *   1. Mobile menu toggle (mag-mobile-btn → mag-mobile-menu.is-open)
 *   2. Back-to-top button (shows after scrolling, smooth-scrolls up)
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax — the
 * build additionally transpiles with target 'es2015' as a belt-and-braces
 * guarantee (same as main.ts).
 */

import './magazine/index.css';
import { initTocScrollspy } from './toc-scrollspy';
import { initCodeHighlight } from './code-highlight';
// The author's Markdown editor: toolbar + live preview over the canonical textarea.
import { initInlineEditor } from './inline-editor';

/**
 * Mobile menu — toggles the `.is-open` class on the header menu panel.
 *
 * Escape must close it AND return focus to the button: a keyboard user who
 * opens a menu and dismisses it with Escape is otherwise stranded on a
 * collapsed panel with no focus target. Outside clicks close it too.
 */
const initMobileMenu = (): void => {
  const btn = document.getElementById('mag-mobile-btn');
  const menu = document.getElementById('mag-mobile-menu');
  if (!btn || !menu) {
    return;
  }

  const setOpen = function (open: boolean): void {
    menu.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  const isOpen = (): boolean => menu.classList.contains('is-open');

  btn.addEventListener('click', function () {
    setOpen(!isOpen());
  });

  menu.addEventListener('click', function (e) {
    const target = e.target as HTMLElement | null;
    // Following a link closes the panel; clicking its padding does not.
    if (target && 'A' === target.tagName) {
      setOpen(false);
    }
  });

  document.addEventListener('click', function (e) {
    const target = e.target as Node | null;
    if (isOpen() && target && !menu.contains(target) && !btn.contains(target)) {
      setOpen(false);
    }
  });

  document.addEventListener('keydown', function (e) {
    const event = e as KeyboardEvent;
    if ('Escape' === event.key && isOpen()) {
      setOpen(false);
      btn.focus();
    }
  });
};

/** Back to top — visible after 400px of scroll, smooth-scrolls to the top. */
const initBackToTop = (): void => {
  const btn = document.getElementById('mag-backtotop');
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

// The shared runtime helpers, byte-identical copies carried per package
// (FrontendPackageParityTest). Both have markup to act on: magazine's
// article view renders mag-toc__link with the data-heading opt-in the
// scrollspy queries, and article bodies carry the code highlighter hooks.
const onDomReady = (): void => {
  initMobileMenu();
  initBackToTop();
  initTocScrollspy();
  initCodeHighlight();
  initInlineEditor();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}
