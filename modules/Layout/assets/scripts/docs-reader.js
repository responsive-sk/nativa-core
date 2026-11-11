/**
 * docs-reader.js — keep the contents rail and the page's anchors clear of the
 * theme's pinned header.
 *
 * The rail sticks at `calc(var(--docs-header-offset) + 1.5rem)`, and the kernel
 * cannot know that number: Boson fixes its bar at 100px and shrinks it to 70px
 * once the page scrolls, Griffe and Mono pin a sticky nav of their own, and a
 * theme that is not installed yet has its own. A per-theme constant would be a
 * rule a sixth theme forgets — and the failure is silent, because the rail still
 * renders, still sticks, and simply paints under the header (which is what
 * `/docs/themes` did at `top: 24px` behind a 71px bar).
 *
 * So the height is measured, not declared: whatever is pinned to the top of the
 * viewport and wide enough to be page chrome rather than a card or the rail
 * itself. It runs on scroll and resize because the answer changes while the
 * page is open, and it measures once more after the scroll settles because a
 * header that animates its height is still moving when the last scroll event
 * fires.
 *
 * The same height answers the other half: an in-page link (`#anchor`, and every
 * rail entry) scrolls its heading to the top edge, which a pinned header covers.
 * A theme may declare `scroll-padding-top` for itself — Boson does, header plus
 * 24px — and that declaration is never overwritten: the theme knows its chrome.
 * Where none is declared, the measured height becomes the padding, so anchors
 * land below the bar instead of behind it.
 */
(function () {
  function pinnedBottom() {
    var lowest = 0;
    var viewportWidth = window.innerWidth;
    var tallest = window.innerHeight * 0.4;
    var elements = document.body.getElementsByTagName('*');

    for (var index = 0; index < elements.length; index++) {
      var element = elements[index];

      // The rail is sticky too, and it lives inside the reader — never chrome.
      if (element.closest('[data-docs-reader]')) continue;

      var rect = element.getBoundingClientRect();

      // Only something sitting at the very top, at least a header's worth of
      // height, spanning most of the width. A card, a button and a hidden
      // element all fail at least one of those.
      if (rect.top > 8 || rect.height < 16 || rect.height > tallest) continue;
      if (rect.width < viewportWidth * 0.5) continue;

      var position = window.getComputedStyle(element).position;

      if (position === 'fixed' || position === 'sticky') {
        lowest = Math.max(lowest, rect.bottom);
      }
    }

    return Math.max(0, Math.round(lowest));
  }

  function mount() {
    var reader = document.querySelector('[data-docs-reader]');

    if (!reader) return;

    var rail = document.querySelector('[data-docs-toc]');
    var ownsScrollPadding = false;

    function apply() {
      var offset = pinnedBottom();

      // The rail is sticky only above its breakpoint; below it that property
      // would be a number nothing reads. Anchors need clearance at every width.
      if (rail && window.matchMedia('(min-width: 1024px)').matches) {
        reader.style.setProperty('--docs-header-offset', offset + 'px');
      }

      // Once written, the inline value is ours to keep in step; before that,
      // anything the theme declared is left alone.
      if (!ownsScrollPadding) {
        if (parseFloat(window.getComputedStyle(document.documentElement).scrollPaddingTop) > 0) return;
        if (offset === 0) return; // nothing pinned: nothing to clear
        ownsScrollPadding = true;
      }

      document.documentElement.style.scrollPaddingTop = offset + 24 + 'px';
    }

    var frame = 0;
    var settle = 0;

    function soon() {
      if (!frame) {
        frame = window.requestAnimationFrame(function () {
          frame = 0;
          apply();
        });
      }

      clearTimeout(settle);
      settle = setTimeout(apply, 300);
    }

    apply();
    window.addEventListener('scroll', soon, { passive: true });
    window.addEventListener('resize', soon);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
