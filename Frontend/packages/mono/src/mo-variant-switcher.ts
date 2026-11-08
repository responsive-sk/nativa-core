/**
 * mo-variant-switcher.ts — dev palette switcher for the Mono navbar.
 *
 * Ported from Modules/Mono/assets/scripts/mo-variant-switcher.js;
 * behaviour is identical. Where upstream injects a `<link>` to neon.css /
 * fire-show.css, Mono's variants already ship in the built CSS under
 * `html[data-variant="…"]`, so the tracker only flips the attribute.
 * Variants are mutually exclusive, a second click on the active button
 * toggles back to the default palette, and the choice persists in
 * localStorage (pre-paint restore lives in the theme init script, like
 * the dark/light mode).
 *
 * Self-gating: returns early when the page renders no
 * `[data-variant-toggle]` buttons, so the single mono bundle entry can
 * call it on every page. The navbar precedes every Mono page, so in
 * practice this wires two buttons and idles.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

const STORAGE_KEY = 'nativa-variant'

function storedVariant(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch (e) {
    return null
  }
}

function persistVariant(variant: string | null): void {
  try {
    if (null === variant) {
      window.localStorage.removeItem(STORAGE_KEY)
    } else {
      window.localStorage.setItem(STORAGE_KEY, variant)
    }
  } catch (e) {}
}

function paintVariant(buttons: NodeListOf<Element>, variant: string | null): void {
  if (null === variant) {
    document.documentElement.removeAttribute('data-variant')
  } else {
    document.documentElement.setAttribute('data-variant', variant)
  }
  for (let i = 0; i < buttons.length; i++) {
    const on = buttons[i].getAttribute('data-variant-toggle') === variant
    buttons[i].classList.toggle('mo-navbar__dev-toggle--active', on)
    buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false')
  }
}

export function initMoVariantSwitcher(): void {
  const buttons = document.querySelectorAll('[data-variant-toggle]')
  if (0 === buttons.length) {
    return
  }
  let current = storedVariant()
  // A stale stored value must never reskin the page — the pre-paint
  // restore ignores unknowns the same way, so both agree on default.
  if ('ember' !== current && 'glacier' !== current) {
    current = null
  }
  paintVariant(buttons, current)
  for (let i = 0; i < buttons.length; i++) {
    const button = buttons[i]
    button.addEventListener('click', () => {
      const want = button.getAttribute('data-variant-toggle')
      const next = document.documentElement.getAttribute('data-variant') === want ? null : want
      paintVariant(buttons, next)
      persistVariant(next)
    })
  }
}
