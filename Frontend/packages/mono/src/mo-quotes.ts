/**
 * mo-quotes.ts — testimonial carousel for the mono quotes section.
 *
 * The track is a native horizontal scroller (CSS scroll-snap), so the
 * behaviour is only the two arrow buttons: each one scrolls the viewport by
 * one slide and the buttons disable themselves at either end. Without JS the
 * track wraps as a plain row and the buttons stay hidden.
 *
 * Smooth scrolling comes from CSS (`scroll-behavior`), so reduced motion is
 * handled there. Self-gating: returns early when the page renders no `.mo-quotes`.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export function initMoQuotes(): void {
  const root = document.querySelector('.mo-quotes') as HTMLElement | null
  if (!root) {
    return
  }
  const viewport = root.querySelector('.mo-quotes__viewport') as HTMLElement | null
  const prev = root.querySelector('.mo-quotes__btn--prev') as HTMLButtonElement | null
  const next = root.querySelector('.mo-quotes__btn--next') as HTMLButtonElement | null
  if (!viewport || !prev || !next) {
    return
  }

  const slideStep = (): number => {
    const slide = viewport.querySelector('.mo-quote') as HTMLElement | null
    return slide ? slide.offsetWidth : viewport.clientWidth
  }

  const update = (): void => {
    const max = viewport.scrollWidth - viewport.clientWidth
    prev.disabled = viewport.scrollLeft <= 1
    next.disabled = viewport.scrollLeft >= max - 1
  }

  const step = (direction: number): void => {
    viewport.scrollBy(direction * slideStep(), 0)
  }

  root.classList.add('mo-quotes--live')
  prev.addEventListener('click', () => {
    step(-1)
  })
  next.addEventListener('click', () => {
    step(1)
  })
  viewport.addEventListener('scroll', update, { passive: true })
  window.addEventListener('resize', update)
  update()
}
