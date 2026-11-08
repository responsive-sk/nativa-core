/**
 * gr-header.ts — hide-on-scroll for the sticky header, without the framework.
 *
 * Ported from Modules/Griffe/assets/scripts/gr-header.js; behaviour is
 * identical. The reference drives this through Xtend UI (`data-xt-sticky`,
 * fixed-always, hide-on-scroll-down). The port keeps the CSS half
 * (`position: sticky`) and this ~20-line twin toggles `.gr-header--hidden`
 * past a small threshold. No-JS fallback: a plain sticky header that
 * never hides.
 *
 * Self-gating: returns early when the page renders no `.gr-header`, so the
 * single griffe bundle entry can call it on every page.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export function initGrHeader(): void {
  const header = document.querySelector('.gr-header')
  if (!header) {
    return
  }
  let last = window.pageYOffset || 0
  let ticking = false
  const update = (): void => {
    ticking = false
    const y = window.pageYOffset || 0
    if (y > 160 && y > last) {
      header.classList.add('gr-header--hidden')
    } else {
      header.classList.remove('gr-header--hidden')
    }
    last = y
  }
  window.addEventListener('scroll', () => {
    if (!ticking) {
      ticking = true
      if (window.requestAnimationFrame) {
        window.requestAnimationFrame(update)
      } else {
        update()
      }
    }
  }, { passive: true })
}
