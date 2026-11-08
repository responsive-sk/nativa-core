// src/components/sections/horizontal-scroll-hero.ts — the fullscreen landing
// deck, as a plain custom element.
//
// The Lit runtime this deck (with `hero-layout`) pulled in measured ~35 KB.
// The component re-rendered its whole template on every slide change because
// that is how `@state` works; what it actually does is move a flex track,
// toggle `.active`, load the one background image that is about to be seen and
// update a counter — all of which are direct DOM writes. The tag, the
// attributes and the shadow DOM are unchanged, so PHP
// (`GriffeTheme::homepage()`) and `GriffeThemeTest` do not change either.

import { css } from '../../utils/css'

export interface HorizontalSlide {
  id: string
  titleLine1: string
  titleLine2?: string
  subtitle?: string
  tag?: string
  link: string
  backgroundImage: string
  backgroundImageMobile?: string
  themeColor: string
  textColor?: string
  buttonText?: string
}

const styles = css`
  /*
   * width 100% (not 100vw) and a dvh height: 100vw includes the scrollbar
   * gutter and overflows; 100dvh tracks the mobile URL bar, so the hero is
   * never taller than the visible area. The 700px floor only applies where
   * the viewport can afford it — on a 667px-tall phone the old 700px floor
   * inside overflow: hidden clipped the last 33px.
   */
  :host {
    display: block;
    width: 100%;
    max-width: 100%;
    height: 100vh;
    height: 100dvh;
    position: relative;
    overflow: hidden;
    background: #0d1119;
  }

  @media (min-height: 700px) {
    :host {
      min-height: 700px;
    }
  }

  * {
    box-sizing: border-box;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  /* Main container for horizontal scroll */
  .horizontal-scroll-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
  }

  /* Slides wrapper - moves horizontally */
  .slides-wrapper {
    display: flex;
    width: 100%;
    height: 100%;
    transition: transform 1.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    will-change: transform;
  }

  /* Individual slide — 100% of the wrapper, which is 100% of the host. */
  .slide {
    flex: 0 0 100%;
    width: 100%;
    height: 100%;
    position: relative;
    overflow: hidden;
  }

  /* Slide background */
  .slide-bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    transition: transform 1.5s cubic-bezier(0.215, 0.61, 0.355, 1);
  }

  .slide-bg::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(
      90deg,
      rgba(0, 0, 0, 0.8) 0%,
      rgba(0, 0, 0, 0.6) 30%,
      rgba(0, 0, 0, 0.4) 50%,
      rgba(0, 0, 0, 0.2) 70%,
      transparent 100%
    );
  }

  /* Slide content */
  .slide-content {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    padding: 0 10%;
    z-index: 2;
  }

  .text-content {
    max-width: 600px;
    position: relative;
    z-index: 3;
  }

  /* Tag */
  .slide-tag {
    display: inline-block;
    font-family: 'Roboto Condensed', 'Roboto Condensed Fallback', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #FF5722;
    margin-bottom: 20px;
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.8s cubic-bezier(0.215, 0.61, 0.355, 1);
  }

  .slide.active .slide-tag {
    opacity: 1;
    transform: translateY(0);
    transition-delay: 0.2s;
  }

  /* Title */
  .title-line {
    display: block;
    overflow: hidden;
    margin-bottom: 8px;
  }

  .title-text {
    display: block;
    font-family: 'Roboto Condensed', 'Roboto Condensed Fallback', sans-serif;
    font-weight: 700;
    font-size: clamp(3rem, 8vw, 5.5rem);
    line-height: 1.1;
    color: #FFFFFF;
    transform: translateY(100%);
    transition: transform 0.9s cubic-bezier(0.215, 0.61, 0.355, 1);
  }

  .slide.active .title-text {
    transform: translateY(0);
  }

  .title-line:nth-child(1) .title-text {
    transition-delay: 0.3s;
  }

  .title-line:nth-child(2) .title-text {
    transition-delay: 0.5s;
  }

  /* Subtitle */
  .subtitle {
    font-family: 'Inter', sans-serif;
    font-size: clamp(1.125rem, 2.5vw, 1.5rem);
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.8);
    margin-top: 24px;
    max-width: 500px;
    opacity: 0;
    transform: translateY(30px);
    transition: all 0.8s cubic-bezier(0.215, 0.61, 0.355, 1);
  }

  .slide.active .subtitle {
    opacity: 1;
    transform: translateY(0);
    transition-delay: 0.7s;
  }

  /* Action Button */
  .action-button {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    margin-top: 40px;
    padding: 16px 32px;
    background: rgba(255, 87, 34, 0.1);
    border: 1px solid rgba(255, 87, 34, 0.3);
    border-radius: 50px;
    color: #FFFFFF;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 500;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 0;
    transform: translateY(20px);
  }

  .slide.active .action-button {
    opacity: 1;
    transform: translateY(0);
    transition: all 0.8s cubic-bezier(0.215, 0.61, 0.355, 1) 0.9s;
  }

  .action-button:hover {
    background: rgba(255, 87, 34, 0.2);
    border-color: rgba(255, 87, 34, 0.5);
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(255, 87, 34, 0.3);
  }

  .button-icon {
    font-size: 20px;
    transition: transform 0.3s ease;
  }

  .action-button:hover .button-icon {
    transform: translateX(4px);
  }

  /* Scroll hint */
  .scroll-hint {
    position: absolute;
    bottom: 40px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: rgba(255, 255, 255, 0.6);
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    z-index: 10;
    opacity: 0;
    animation: fadeIn 1s ease 2s forwards;
  }

  @keyframes fadeIn {
    to { opacity: 1; }
  }

  .mouse-wheel {
    width: 24px;
    height: 40px;
    border: 2px solid rgba(255, 255, 255, 0.6);
    border-radius: 12px;
    position: relative;
  }

  .mouse-wheel::after {
    content: '';
    position: absolute;
    top: 8px;
    left: 50%;
    width: 4px;
    height: 4px;
    background: rgba(255, 255, 255, 0.8);
    border-radius: 50%;
    transform: translateX(-50%);
    animation: scrollHint 2s infinite;
  }

  @keyframes scrollHint {
    0%, 100% { transform: translateX(-50%) translateY(0); opacity: 0.8; }
    50% { transform: translateX(-50%) translateY(8px); opacity: 1; }
  }

  /* Progress bar */
  .progress-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: rgba(255, 255, 255, 0.1);
    z-index: 1000;
  }

  .progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #FF5722, #FF7043);
    width: 0%;
    transition: width 0.3s ease;
  }

  /* Slide counter — clears the fixed hero footer on every width. */
  .slide-counter {
    position: fixed;
    bottom: 84px;
    right: 40px;
    font-family: 'Roboto Condensed', 'Roboto Condensed Fallback', sans-serif;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.6);
    z-index: 100;
  }

  .counter-current {
    color: white;
    font-size: 24px;
    font-weight: 700;
  }

  .counter-total {
    font-size: 14px;
  }

  /* Mobile styles */
  @media (max-width: 768px) {
    .slide-content {
      padding: 0 5%;
    }

    .slide-counter {
      right: 20px;
    }

    /* A wheel hint on a touch device points at an affordance that is not
         there — and it collides with the footer. */
    .scroll-hint {
      display: none;
    }

    .action-button {
      padding: 14px 28px;
    }
  }

  /* Phone: hero footer stacks, so slide text and the counter get more room. */
  @media (max-width: 640px) {
    .slide-content {
      padding: 0 6%;
      padding-top: 88px;
      padding-bottom: 116px;
    }

    .slide-tag {
      font-size: 12px;
      margin-bottom: 12px;
    }

    .title-text {
      font-size: clamp(2rem, 9vw, 3rem);
    }

    .subtitle {
      font-size: 1rem;
      margin-top: 16px;
    }

    .action-button {
      margin-top: 24px;
      padding: 14px 24px;
    }

    .slide-counter {
      /* Clears the stacked (two-line) footer. */
      bottom: 104px;
      right: 16px;
    }
  }
`;

const SLIDE_TRANSITION_MS = 1200
const DEFAULT_AUTOPLAY_INTERVAL = 4000
const WHEEL_LOCK_MS = 800
const MOBILE_BREAKPOINT = 768
const SWIPE_THRESHOLD = 50
const SCROLL_HINT_LABEL = 'Scroll to navigate'
const DEFAULT_BUTTON_TEXT = 'Read More'
const DEFAULT_THEME_COLOR = '#0d1119'

/**
 * The static shell. Slides, counter and hint are built in `rebuild()`; every
 * value that comes from the `slides` payload is written with `textContent` or
 * an attribute, so copy cannot become markup.
 */
const SHELL = [
  '<div class="horizontal-scroll-container">',
  '<div class="progress-container"><div class="progress-bar"></div></div>',
  '<div class="slides-wrapper"></div>',
  '</div>',
].join('')

/**
 * Read the `slides` JSON attribute, the same payload the Lit `Array`
 * converter used to receive. Unknown shapes degrade to "no slides", which is
 * what the element rendered before too.
 */
function parseSlides(raw: string | null): HorizontalSlide[] {
  if (null === raw) {
    return []
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

  const slides: HorizontalSlide[] = []

  for (const item of parsed) {
    if (null === item || 'object' !== typeof item) {
      continue
    }

    const entry = item as Partial<HorizontalSlide>

    slides.push({
      id: String(entry.id ?? ''),
      titleLine1: String(entry.titleLine1 ?? ''),
      titleLine2: entry.titleLine2 ? String(entry.titleLine2) : undefined,
      subtitle: entry.subtitle ? String(entry.subtitle) : undefined,
      tag: entry.tag ? String(entry.tag) : undefined,
      link: String(entry.link ?? ''),
      backgroundImage: String(entry.backgroundImage ?? ''),
      backgroundImageMobile: entry.backgroundImageMobile
        ? String(entry.backgroundImageMobile)
        : undefined,
      themeColor: String(entry.themeColor ?? DEFAULT_THEME_COLOR),
      textColor: entry.textColor ? String(entry.textColor) : undefined,
      buttonText: entry.buttonText ? String(entry.buttonText) : undefined,
    })
  }

  return slides
}

export class HorizontalScrollHero extends HTMLElement {
  static get observedAttributes(): string[] {
    return ['slides', 'autoplay-interval', 'show-scroll-hint']
  }

  private readonly container: HTMLElement
  private readonly wrapper: HTMLElement
  private readonly progressBar: HTMLElement

  private slidesData: HorizontalSlide[] = []
  private slideEls: HTMLElement[] = []
  private hint: HTMLElement | null = null
  private counter: HTMLElement | null = null
  private counterCurrent: HTMLElement | null = null

  private current = 0
  private progress = 0
  private animating = false
  private autoplayTimer: number | null = null
  private wheelTimeout: number | null = null
  private wheelBlocked = false
  private touchStartX = 0
  private touchStartY = 0

  constructor() {
    super()

    const root = this.attachShadow({ mode: 'open' })

    const style = document.createElement('style')
    style.textContent = styles

    const shell = document.createElement('template')
    shell.innerHTML = SHELL

    root.append(style, shell.content)

    this.container = root.querySelector('.horizontal-scroll-container') as HTMLElement
    this.wrapper = root.querySelector('.slides-wrapper') as HTMLElement
    this.progressBar = root.querySelector('.progress-bar') as HTMLElement
  }

  /** The parsed deck, for a script that builds it in memory instead of markup. */
  get slides(): HorizontalSlide[] {
    return this.slidesData
  }

  set slides(value: HorizontalSlide[]) {
    this.setSlides(value)
  }

  connectedCallback(): void {
    // The deck is the whole viewport; a scrolling body behind it shows the
    // next section for one wheel tick on every navigation.
    document.body.style.overflow = 'hidden'

    this.addEventListener('wheel', this.onWheel, { passive: false })
    document.addEventListener('keydown', this.onKeyDown)
    this.addEventListener('touchstart', this.onTouchStart, { passive: true })
    this.addEventListener('touchend', this.onTouchEnd, { passive: true })
    window.addEventListener('resize', this.onResize)
    this.container.addEventListener('mouseenter', this.onMouseEnter)
    this.container.addEventListener('mouseleave', this.onMouseLeave)

    this.update()
    this.startAutoplay()
  }

  disconnectedCallback(): void {
    this.stopAutoplay()

    this.removeEventListener('wheel', this.onWheel)
    document.removeEventListener('keydown', this.onKeyDown)
    this.removeEventListener('touchstart', this.onTouchStart)
    this.removeEventListener('touchend', this.onTouchEnd)
    window.removeEventListener('resize', this.onResize)
    this.container.removeEventListener('mouseenter', this.onMouseEnter)
    this.container.removeEventListener('mouseleave', this.onMouseLeave)

    document.body.style.overflow = ''
  }

  attributeChangedCallback(name: string, _oldValue: string | null, value: string | null): void {
    switch (name) {
      case 'slides':
        this.setSlides(parseSlides(value))
        break
      case 'autoplay-interval':
        this.startAutoplay()
        break
      case 'show-scroll-hint':
        this.syncHint()
        break
    }
  }

  private readonly onWheel = (event: WheelEvent): void => {
    if (this.animating || this.wheelBlocked) {
      return
    }

    if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
      return
    }

    event.preventDefault()

    // One gesture is one slide: a trackpad emits dozens of wheel events, and
    // without the lock the deck ran through every slide in a single flick.
    this.wheelBlocked = true

    if (null !== this.wheelTimeout) {
      window.clearTimeout(this.wheelTimeout)
    }

    this.wheelTimeout = window.setTimeout(() => {
      this.wheelBlocked = false
    }, WHEEL_LOCK_MS)

    if (event.deltaY > 0) {
      this.next()
    } else {
      this.previous()
    }
  }

  private readonly onKeyDown = (event: KeyboardEvent): void => {
    if (this.animating) {
      return
    }

    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault()
        this.previous()
        break
      case 'ArrowRight':
      case 'ArrowDown':
      case ' ':
        event.preventDefault()
        this.next()
        break
      case 'Home':
        event.preventDefault()
        this.goTo(0)
        break
      case 'End':
        event.preventDefault()
        this.goTo(this.slideEls.length - 1)
        break
    }
  }

  private readonly onTouchStart = (event: TouchEvent): void => {
    this.touchStartX = event.touches[0].clientX
    this.touchStartY = event.touches[0].clientY
  }

  private readonly onTouchEnd = (event: TouchEvent): void => {
    const touch = event.changedTouches[0]

    if (!touch) {
      return
    }

    const deltaX = touch.clientX - this.touchStartX
    const deltaY = touch.clientY - this.touchStartY

    // A horizontal swipe, not the vertical scroll a phone uses to browse.
    if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > SWIPE_THRESHOLD) {
      if (deltaX > 0) {
        this.previous()
      } else {
        this.next()
      }
    }
  }

  private readonly onResize = (): void => {
    // Re-evaluates the background too: a rotation across 768px has to swap
    // `backgroundImageMobile` for the desktop image.
    this.update()
  }

  private readonly onMouseEnter = (): void => {
    this.stopAutoplay()
  }

  private readonly onMouseLeave = (): void => {
    this.startAutoplay()
  }

  private autoplayInterval(): number {
    const raw = this.getAttribute('autoplay-interval')

    if (null === raw || '' === raw.trim()) {
      return DEFAULT_AUTOPLAY_INTERVAL
    }

    const value = Number(raw)

    return Number.isFinite(value) ? value : DEFAULT_AUTOPLAY_INTERVAL
  }

  private showsHint(): boolean {
    return 'false' !== this.getAttribute('show-scroll-hint')
  }

  private syncHint(): void {
    if (null !== this.hint) {
      this.hint.style.display = this.showsHint() ? '' : 'none'
    }
  }

  private setSlides(value: HorizontalSlide[]): void {
    this.slidesData = value
    this.rebuild()
  }

  private rebuild(): void {
    this.stopAutoplay()

    this.current = 0
    this.progress = 0
    this.wrapper.textContent = ''
    this.slideEls = this.slidesData.map((slide) => this.buildSlide(slide))
    this.wrapper.append(...this.slideEls)

    this.hint?.remove()
    this.hint = null
    this.counter?.remove()
    this.counter = null
    this.counterCurrent = null

    // One slide is a poster, not a deck: no counter, no hint, no autoplay.
    if (this.slideEls.length > 1) {
      const counter = document.createElement('div')
      counter.className = 'slide-counter'

      const current = document.createElement('span')
      current.className = 'counter-current'

      const total = document.createElement('span')
      total.className = 'counter-total'
      total.textContent = '/' + this.slideEls.length

      counter.append(current, total)
      this.container.appendChild(counter)

      this.counter = counter
      this.counterCurrent = current

      if (this.showsHint()) {
        const hint = document.createElement('div')
        hint.className = 'scroll-hint'

        const wheel = document.createElement('div')
        wheel.className = 'mouse-wheel'

        const label = document.createElement('span')
        label.textContent = SCROLL_HINT_LABEL

        hint.append(wheel, label)
        this.container.appendChild(hint)

        this.hint = hint
      }
    }

    this.update()
    this.startAutoplay()
  }

  private buildSlide(slide: HorizontalSlide): HTMLElement {
    const element = document.createElement('div')
    element.className = 'slide'

    if ('' !== slide.id) {
      element.dataset.id = slide.id
    }

    const background = document.createElement('div')
    background.className = 'slide-bg'
    element.appendChild(background)

    const content = document.createElement('div')
    content.className = 'slide-content'

    const text = document.createElement('div')
    text.className = 'text-content'

    content.appendChild(text)
    element.appendChild(content)

    if (slide.tag) {
      const tag = document.createElement('div')
      tag.className = 'slide-tag'
      tag.textContent = slide.tag
      text.appendChild(tag)
    }

    // Line one renders even when empty, as it did before: `.title-line` is the
    // mask the reveal transition slides behind, and skipping it shifts the
    // `nth-child` delays of the second line.
    text.appendChild(this.buildTitleLine(slide.titleLine1))

    if (slide.titleLine2) {
      text.appendChild(this.buildTitleLine(slide.titleLine2))
    }

    if (slide.subtitle) {
      const subtitle = document.createElement('div')
      subtitle.className = 'subtitle'
      subtitle.textContent = slide.subtitle
      text.appendChild(subtitle)
    }

    const button = document.createElement('a')
    button.className = 'action-button'
    button.setAttribute('href', slide.link)
    button.textContent = slide.buttonText || DEFAULT_BUTTON_TEXT

    const icon = document.createElement('span')
    icon.className = 'button-icon'
    icon.textContent = '→'
    button.appendChild(icon)

    // In-page targets are the PJAX router's, not a full page load. Same
    // contract as before: the anchor stays a real link without JS.
    button.addEventListener('click', (event: Event) => {
      if (!slide.link.startsWith('#')) {
        return
      }

      event.preventDefault()
      window.dispatchEvent(new CustomEvent('pjax:navigate', { detail: { url: slide.link } }))
    })

    text.appendChild(button)

    return element
  }

  private buildTitleLine(value: string): HTMLElement {
    const line = document.createElement('div')
    line.className = 'title-line'

    const span = document.createElement('span')
    span.className = 'title-text'
    span.textContent = value

    line.appendChild(span)

    return line
  }

  private update(): void {
    const count = this.slideEls.length

    this.wrapper.style.transform = `translateX(${-this.current * 100}%)`
    this.progressBar.style.width = `${this.progress}%`

    this.slideEls.forEach((element, index) => {
      const active = index === this.current

      element.classList.toggle('active', active)

      const background = element.firstElementChild as HTMLElement | null

      if (null !== background) {
        // Only the active slide and its two neighbours ask for an image, so a
        // four-slide deck downloads one cover up front instead of four — and a
        // neighbour that is one step away still has it when the track moves.
        const preload = active
          || index === (this.current + 1) % count
          || index === (this.current - 1 + count) % count
        const url = preload ? this.backgroundFor(this.slidesData[index]) : ''

        background.style.backgroundImage = '' === url ? 'none' : `url('${url}')`
        background.style.transform = active ? 'scale(1.05)' : 'scale(1)'
      }
    })

    if (null !== this.counterCurrent) {
      this.counterCurrent.textContent = String(this.current + 1)
    }
  }

  private backgroundFor(slide: HorizontalSlide): string {
    if (window.innerWidth < MOBILE_BREAKPOINT && slide.backgroundImageMobile) {
      return slide.backgroundImageMobile
    }

    return slide.backgroundImage
  }

  private next(): void {
    if (0 === this.slideEls.length) {
      return
    }

    this.goTo((this.current + 1) % this.slideEls.length)
  }

  private previous(): void {
    if (0 === this.slideEls.length) {
      return
    }

    this.goTo(0 === this.current ? this.slideEls.length - 1 : this.current - 1)
  }

  private goTo(index: number): void {
    const count = this.slideEls.length

    if (this.animating || index < 0 || index >= count || index === this.current) {
      return
    }

    this.animating = true
    this.current = index
    this.progress = count > 1 ? (index / (count - 1)) * 100 : 0

    // A manual move restarts the clock, so autoplay never fires right after a
    // reader has just chosen a slide.
    this.stopAutoplay()
    this.startAutoplay()

    this.update()

    window.setTimeout(() => {
      this.animating = false
    }, SLIDE_TRANSITION_MS)
  }

  private startAutoplay(): void {
    this.stopAutoplay()

    if (!this.isConnected || this.autoplayInterval() <= 0 || this.slideEls.length < 2) {
      return
    }

    this.autoplayTimer = window.setInterval(() => {
      this.next()
    }, this.autoplayInterval())
  }

  private stopAutoplay(): void {
    if (null === this.autoplayTimer) {
      return
    }

    window.clearInterval(this.autoplayTimer)
    this.autoplayTimer = null
  }
}

customElements.define('horizontal-scroll-hero', HorizontalScrollHero)

declare global {
  interface HTMLElementTagNameMap {
    'horizontal-scroll-hero': HorizontalScrollHero
  }
}
