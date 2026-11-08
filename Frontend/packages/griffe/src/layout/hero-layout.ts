// src/layout/hero-layout.ts — the landing chrome, as a plain custom element.
//
// This and `horizontal-scroll-hero` are the only two elements the entry
// registers, and they were the only reason `lit` was in the shipped graph. On
// probe builds the Lit runtime measured ~35 KB of the ~45 KB bundle — 79 % of
// the JS every visitor downloaded — and this element's share of it bought one
// boolean: `isScrolled`. The native element below
// keeps the identical contract: same tag, same attributes, same shadow DOM,
// same CSS, same markup. PHP (`GriffeTheme::homepage()`) does not change, and
// neither does `GriffeThemeTest`.
//
// There is nothing to schedule here: the theme emits the element once, and the
// only thing that changes afterwards is one class on the header. Every site
// value is written with `textContent`/`setAttribute`, which is the escaping the
// template engine used to do for us.

import { css } from '../utils/css'

export interface HeroNavLink {
  label: string
  href: string
}

const styles = css`
  /*
   * width: 100% and not 100vw: 100vw includes the scrollbar gutter, so on a
   * desktop with a visible scrollbar it overflows the viewport by its width —
   * the old horizontal scrollbar and the clipped right edge on mobile had the
   * same cause. Explicit border-box keeps the padding inside that width.
   */
  :host {
    display: block;
    width: 100%;
    max-width: 100%;
    height: 100vh;
    height: 100dvh;
    position: relative;
    overflow: hidden;
  }

  *,
  *::before,
  *::after {
    box-sizing: border-box;
  }

  /* Fixed header */
  .hero-header {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 24px;
    padding: 24px 40px;
    padding-left: calc(40px + env(safe-area-inset-left, 0px));
    padding-right: calc(40px + env(safe-area-inset-right, 0px));
    z-index: 1000;
    background: linear-gradient(to bottom, rgba(13, 17, 25, 0.9), transparent);
    transition: background 0.3s ease, padding 0.3s ease;
  }

  .hero-header.scrolled {
    background: rgba(13, 17, 25, 0.95);
    backdrop-filter: blur(10px);
    padding-top: 16px;
    padding-bottom: 16px;
  }

  /*
   * The logo shrinks instead of pushing the nav off-screen: a long site name
   * ellipsizes (min-width: 0 is what lets a flex item go below its content
   * width — without it text-overflow never applies).
   */
  .logo {
    flex: 0 1 auto;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-family: 'Roboto Condensed', 'Roboto Condensed Fallback', sans-serif;
    font-size: 24px;
    font-weight: 700;
    color: white;
    text-decoration: none;
  }

  .nav-links {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 32px;
    min-width: 0;
  }

  .nav-link {
    color: rgba(255, 255, 255, 0.8);
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    /* Vertical padding, not margin: a 14px label with a 44px-ish tap area. */
    padding: 8px 0;
    transition: color 0.3s ease;
  }

  .nav-link:hover {
    color: white;
  }

  /* Main content slot */
  .hero-main {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 1;
  }

  /* Footer */
  .hero-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    width: 100%;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
    padding: 20px 40px;
    padding-left: calc(40px + env(safe-area-inset-left, 0px));
    padding-right: calc(40px + env(safe-area-inset-right, 0px));
    padding-bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    z-index: 1000;
    color: rgba(255, 255, 255, 0.6);
    font-family: 'Inter', sans-serif;
    font-size: 12px;
  }

  .copyright {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .social-links {
    display: flex;
    gap: 16px;
  }

  .social-link {
    color: rgba(255, 255, 255, 0.6);
    text-decoration: none;
    transition: color 0.3s ease;
  }

  .social-link:hover {
    color: white;
  }

  /* Tablet: tighter chrome, still one row. */
  @media (max-width: 900px) {
    .hero-header {
      gap: 8px 20px;
      padding: 18px 24px;
      padding-left: calc(24px + env(safe-area-inset-left, 0px));
      padding-right: calc(24px + env(safe-area-inset-right, 0px));
    }

    .logo {
      font-size: 20px;
    }

    .nav-links {
      gap: 20px;
    }
  }

  /*
   * Phone: the nav drops onto its own full-width row instead of being pushed
   * off the right edge, and stays horizontally scrollable if a site ever adds
   * more links than fit. The logo keeps the first row alone.
   */
  @media (max-width: 640px) {
    .hero-header {
      row-gap: 2px;
      padding: 14px 16px;
      padding-left: calc(16px + env(safe-area-inset-left, 0px));
      padding-right: calc(16px + env(safe-area-inset-right, 0px));
    }

    .logo {
      flex: 1 1 auto;
      font-size: 18px;
    }

    .nav-links {
      order: 3;
      width: 100%;
      justify-content: flex-start;
      gap: 18px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
    }

    .nav-links::-webkit-scrollbar {
      display: none;
    }

    .nav-link {
      font-size: 12px;
      padding: 10px 0;
      white-space: nowrap;
    }

    .hero-footer {
      flex-direction: column;
      gap: 6px;
      padding: 14px 16px;
      padding-left: calc(16px + env(safe-area-inset-left, 0px));
      padding-right: calc(16px + env(safe-area-inset-right, 0px));
      padding-bottom: calc(14px + env(safe-area-inset-bottom, 0px));
      text-align: center;
    }
  }
`;

/**
 * The static shell. No interpolation on purpose: a site name containing `<`
 * or `"` must not be able to become markup — it is text in `.logo` and an
 * attribute value on the anchors below.
 */
const SHELL = [
  '<header class="hero-header">',
  '<a class="logo"></a>',
  '<nav class="nav-links"></nav>',
  '</header>',
  '<div class="hero-main"><slot></slot></div>',
  '<footer class="hero-footer">',
  '<div class="copyright"></div>',
  '<div class="social-links"></div>',
  '</footer>',
].join('')

/**
 * Defaults kept from the Lit version so a hand-written `<hero-layout>` (no
 * attributes) still renders the documented chrome. PHP always passes all five
 * attributes, so on the homepage these are never the values that show.
 */
const DEFAULT_LOGO_TEXT = 'LOGO'
const DEFAULT_LOGO_LINK = '/'
const DEFAULT_COPYRIGHT = '© 2025 responsive.sk <3 All rights reserved.'
const DEFAULT_NAVIGATION: readonly HeroNavLink[] = [
  { label: 'Work', href: '/work' },
  { label: 'Services', href: '/services' },
  { label: 'About', href: '/about' },
  { label: 'Contact', href: '/contact' },
]
const DEFAULT_SOCIAL_LINKS: readonly HeroNavLink[] = [
  { label: 'Facebook', href: 'https://facebook.com' },
  { label: 'Instagram', href: 'https://instagram.com' },
  { label: 'LinkedIn', href: 'https://linkedin.com' },
]

/**
 * Read a `[{label, href}]` JSON attribute.
 *
 * An absent attribute keeps the defaults; a present one is authoritative even
 * when it is `[]` (PHP sends exactly that for social links) or malformed — a
 * broken payload must not resurrect links the site removed.
 */
function parseLinks(raw: string | null, fallback: readonly HeroNavLink[]): HeroNavLink[] {
  if (null === raw) {
    return fallback.map((link) => ({ ...link }))
  }

  let parsed: unknown

  try {
    parsed = JSON.parse(raw)
  } catch {
    return []
  }

  if (!Array.isArray(parsed)) {
    return []
  }

  return parsed.flatMap((item): HeroNavLink[] => {
    if (null === item || 'object' !== typeof item) {
      return []
    }

    const entry = item as { label?: unknown; href?: unknown }

    return [{ label: String(entry.label ?? ''), href: String(entry.href ?? '') }]
  })
}

export class HeroLayout extends HTMLElement {
  static get observedAttributes(): string[] {
    return ['logo-text', 'logo-link', 'navigation', 'social-links', 'copyright']
  }

  private readonly header: HTMLElement
  private readonly logo: HTMLAnchorElement
  private readonly nav: HTMLElement
  private readonly copyright: HTMLElement
  private readonly social: HTMLElement
  private scrolled = false

  constructor() {
    super()

    const root = this.attachShadow({ mode: 'open' })

    const style = document.createElement('style')
    style.textContent = styles

    const shell = document.createElement('template')
    shell.innerHTML = SHELL

    root.append(style, shell.content)

    this.header = root.querySelector('.hero-header') as HTMLElement
    this.logo = root.querySelector('.logo') as HTMLAnchorElement
    this.nav = root.querySelector('.nav-links') as HTMLElement
    this.copyright = root.querySelector('.copyright') as HTMLElement
    this.social = root.querySelector('.social-links') as HTMLElement
  }

  connectedCallback(): void {
    window.addEventListener('scroll', this.onScroll, { passive: true })
    // A reload can restore a scrolled position, and the header has to match it
    // before the first scroll event decides to fire.
    this.syncScrollState()
    this.render()
  }

  disconnectedCallback(): void {
    window.removeEventListener('scroll', this.onScroll)
  }

  attributeChangedCallback(): void {
    this.render()
  }

  private readonly onScroll = (): void => {
    this.syncScrollState()
  }

  private syncScrollState(): void {
    const scrolled = window.scrollY > 50

    if (scrolled === this.scrolled) {
      return
    }

    this.scrolled = scrolled
    this.header.classList.toggle('scrolled', scrolled)
  }

  private render(): void {
    this.logo.textContent = this.getAttribute('logo-text') ?? DEFAULT_LOGO_TEXT
    this.logo.setAttribute('href', this.getAttribute('logo-link') ?? DEFAULT_LOGO_LINK)
    this.copyright.textContent = this.getAttribute('copyright') ?? DEFAULT_COPYRIGHT

    this.renderLinks(
      this.nav,
      parseLinks(this.getAttribute('navigation'), DEFAULT_NAVIGATION),
      'nav-link',
      false,
    )
    this.renderLinks(
      this.social,
      parseLinks(this.getAttribute('social-links'), DEFAULT_SOCIAL_LINKS),
      'social-link',
      true,
    )
  }

  private renderLinks(
    target: HTMLElement,
    links: readonly HeroNavLink[],
    className: string,
    external: boolean,
  ): void {
    target.textContent = ''

    for (const link of links) {
      const anchor = document.createElement('a')
      anchor.className = className
      anchor.setAttribute('href', link.href)
      anchor.textContent = link.label

      if (external) {
        anchor.target = '_blank'
        anchor.rel = 'noopener'
      }

      target.appendChild(anchor)
    }
  }
}

customElements.define('hero-layout', HeroLayout)

declare global {
  interface HTMLElementTagNameMap {
    'hero-layout': HeroLayout
  }
}
