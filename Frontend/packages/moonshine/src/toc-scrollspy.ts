/**
 * toc-scrollspy.ts — highlights the article TOC link of the section
 * currently in view (both the desktop sidebar and the mobile panel share
 * the same markup, so one tracker serves both).
 *
 * A passive scroll listener compares each heading's offset against a
 * marker near the top of the viewport and toggles an active class on the
 * last heading above it — the same "current section" logic as native
 * scrollspy libraries, minus the dependency. TOC links also carry
 * data-heading anchors, so the active state survives clicks (the target
 * becomes active on scroll).
 *
 * Theme-agnostic on purpose: links opt in with `data-toc-link` and their
 * own BEM block supplies the active class, so the starter
 * (st-article-toc__link / st-article-toc__link--active) and erik
 * (er-toc__link / er-toc__link--active) run the same bytes. A hardcoded
 * `.st-article-toc__link` selector silently matched nothing in erik, so
 * its TOC never moved — the guard `TocScrollspyContractTest` now fails if
 * a theme ships TOC markup without the opt-in attribute.
 *
 * ES2015-safe: no `?.`, `??` — this source runs on Chrome 70 even
 * without transpilation (the build still transpiles to target 'es2015').
 */

interface TocEntry {
  /** The heading element this TOC link points at */
  heading: HTMLElement;
  /** The matching TOC link (sidebar and/or mobile panel) */
  links: HTMLAnchorElement[];
}

/**
 * The BEM block of a link, derived from its own class list.
 *
 * `st-article-toc__link st-article-toc__link--sub` yields
 * `st-article-toc__link`, whose active sibling is
 * `st-article-toc__link--active`. Reading the block off the element is what
 * lets one tracker serve themes with different prefixes instead of
 * hardcoding the starter's.
 */
const activeClassFor = (link: Element): string => {
  let block = '';

  for (let i = 0; i < link.classList.length; i++) {
    const name = link.classList[i];

    // Skip BEM modifiers (`…--sub`) and keep the plain block (`…__link`).
    if (name.indexOf('__') !== -1 && name.indexOf('--') === -1) {
      block = name;
      break;
    }
  }

  return '' === block ? 'toc-link--active' : block + '--active';
};

/**
 * Collect heading → TOC link pairs from the shared data-heading markup.
 * A heading may be referenced by several links (desktop sidebar + mobile
 * panel), so every matching link is tracked and toggled together.
 */
const collectEntries = (): TocEntry[] => {
  const entries: TocEntry[] = [];

  document.querySelectorAll<HTMLAnchorElement>('[data-toc-link][data-heading]').forEach((link) => {
    const id = link.getAttribute('data-heading');
    if (null === id) {
      return;
    }

    const heading = document.getElementById(id);
    if (null === heading) {
      return;
    }

    const existing = entries.find((entry) => entry.heading === heading);
    if (undefined !== existing) {
      existing.links.push(link);

      return;
    }

    entries.push({ heading: heading, links: [link] });
  });

  return entries;
};

/**
 * Mark the entry whose heading sits above the viewport marker as active —
 * the marker sits at 1/3 of the viewport height, so the section you are
 * actually reading is the one highlighted. The first heading wins when
 * the page is at the top; the last one wins once scrolled past all of
 * them.
 */
const markActive = (entries: TocEntry[], marker: number): void => {
  let activeIndex = 0;

  entries.forEach((entry, index) => {
    if (entry.heading.getBoundingClientRect().top <= marker) {
      activeIndex = index;
    }
  });

  entries.forEach((entry, index) => {
    entry.links.forEach((link) => {
      const isActive = index === activeIndex;
      link.classList.toggle(activeClassFor(link), isActive);
      // Surface the current section to screen readers alongside the visual
      // highlight (the links are already labelled by their text).
      if (isActive) {
        link.setAttribute('aria-current', 'location');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  });
};

/**
 * Start the scrollspy once the DOM is ready. Passive listener (no
 * preventDefault) so it never interferes with scrolling; the position is
 * also computed on init for pages opened with a #fragment.
 */
export const initTocScrollspy = (): void => {
  const entries = collectEntries();
  if (0 === entries.length) {
    return;
  }

  const update = (): void => {
    markActive(entries, window.innerHeight / 3);
  };

  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update, { passive: true });
  update();
};