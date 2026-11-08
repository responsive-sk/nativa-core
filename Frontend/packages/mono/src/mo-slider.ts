/**
 * mo-slider.ts — fullscreen home slider behaviour, without the framework.
 *
 * Ported from gr-slider.ts (griffe); behaviour is identical, only the
 * vocabulary is mono's (`mo-hero-slider`, `mo-slide`, `mo-pag`,
 * `data-mo-autoplay`). Adds `.mo-slider--live` (CSS switches stacked
 * slides to one active stage), auto-advances every `data-mo-autoplay` ms
 * with the filler's width as the progress bar, wires pagination buttons +
 * prev/next arrows + arrow keys, pauses on hover/focus and when the tab
 * hides. Slide changes keep every title readable: all slide text ships
 * `mo-is-visible` server-side, so the slider cross-fades containers and
 * never depends on a reveal observer.
 *
 * Hover/focus pauses on the CONTROLS only (pagination + arrows), never on
 * the stage: the hero is fullscreen, so the cursor rests inside it almost
 * by definition — pausing on the whole root would freeze autoplay for
 * anyone reading the headline with the mouse parked over it. Deliberate
 * divergence from the griffe twin (whose 92svh stage has the same trap,
 * but griffe keeps the reference behaviour verbatim).
 *
 * Self-gating: stays static (no `--live`, slides stack, controls stay
 * hidden) on pages without a slider, with a single slide, or under
 * reduced motion — the no-JS fallback doubles as the calm one.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

import { revealWithin } from './mo-reveal'

export function initMoSlider(): void {
  const root = document.querySelector('.mo-hero-slider') as HTMLElement | null
  if (!root) {
    return
  }
  const slides = root.querySelectorAll('.mo-slide')
  if (slides.length < 2) {
    return
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return
  }
  let delay = parseInt(root.getAttribute('data-mo-autoplay') || '6000', 10)
  if (!(delay > 0)) {
    delay = 6000
  }
  const pag = root.querySelectorAll('.mo-pag')
  let index = 0
  let timer: number | null = null

  root.classList.add('mo-slider--live')

  const fill = (): void => {
    const active = root.querySelector('.mo-pag--active .mo-pag__fill') as HTMLElement | null
    if (!active) {
      return
    }
    active.style.transitionDuration = delay + 'ms'
  }

  const stop = (): void => {
    if (null !== timer) {
      window.clearTimeout(timer)
      timer = null
    }
  }

  const restart = (): void => {
    stop()
    fill()
    timer = window.setTimeout(() => {
      go(index + 1)
    }, delay)
  }

  const go = (next: number): void => {
    next = (next + slides.length) % slides.length
    if (next === index) {
      restart()
      return
    }
    slides[index].classList.remove('mo-slide--active')
    if (pag[index]) {
      pag[index].classList.remove('mo-pag--active')
      pag[index].removeAttribute('aria-current')
    }
    index = next
    slides[index].classList.add('mo-slide--active')
    revealWithin(slides[index])
    if (pag[index]) {
      pag[index].classList.add('mo-pag--active')
      pag[index].setAttribute('aria-current', 'true')
    }
    restart()
  }

  for (let i = 0; i < pag.length; i++) {
    const button = pag[i]
    const target = i
    button.addEventListener('click', () => {
      go(target)
    })
  }

  const prev = root.querySelector('.mo-slider__arrow--prev')
  const nextBtn = root.querySelector('.mo-slider__arrow--next')
  if (prev) {
    prev.addEventListener('click', () => {
      go(index - 1)
    })
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      go(index + 1)
    })
  }
  root.addEventListener('keydown', (event) => {
    if ('ArrowLeft' === event.key) {
      go(index - 1)
    } else if ('ArrowRight' === event.key) {
      go(index + 1)
    }
  })
  // Pause surfaces: the controls only (see the header note). Every one
  // exists only in a multi-slide hero (PHP renders none for a lone hero),
  // so a missing control is simply skipped, never an error.
  const pauseSurfaces: Element[] = []
  const pagRoot = root.querySelector('.mo-slider__pag')
  if (pagRoot) {
    pauseSurfaces.push(pagRoot)
  }
  const arrowPrev = root.querySelector('.mo-slider__arrow--prev')
  if (arrowPrev) {
    pauseSurfaces.push(arrowPrev)
  }
  const arrowNext = root.querySelector('.mo-slider__arrow--next')
  if (arrowNext) {
    pauseSurfaces.push(arrowNext)
  }
  for (let s = 0; s < pauseSurfaces.length; s++) {
    pauseSurfaces[s].addEventListener('mouseenter', stop)
    pauseSurfaces[s].addEventListener('mouseleave', restart)
    pauseSurfaces[s].addEventListener('focusin', stop)
    pauseSurfaces[s].addEventListener('focusout', restart)
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stop()
    } else {
      restart()
    }
  })

  restart()
}
