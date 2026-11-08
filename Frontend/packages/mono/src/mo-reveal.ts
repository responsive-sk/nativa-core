/**
 * mo-reveal.ts — line-mask reveals for slide titles, without the framework.
 *
 * Ported from gr-reveal.ts (griffe). Server markup always carries the
 * masked spans (`.mo-anim` > `.mo-anim__line`); this script FIRST arms
 * `.mo-reveal-armed` on <html> (only then does CSS hide unrevealed lines —
 * no-JS readers never see hidden text) and then adds `.mo-in` through an
 * IntersectionObserver. Above-fold lines intersect immediately. The slider
 * calls `revealWithin()` on slide activation, so the incoming title always
 * rises even when it was never in view.
 *
 * Self-gating: returns early when the page renders no `.mo-anim`, and under
 * reduced motion (lines stay plain text). A timed sweep rescues above-fold
 * lines when the observer never fires.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

let observer: IntersectionObserver | null = null

export function revealWithin(root: Element): void {
  const lines = root.querySelectorAll('.mo-anim')
  for (let i = 0; i < lines.length; i++) {
    lines[i].classList.add('mo-in')
    if (observer) {
      observer.unobserve(lines[i])
    }
  }
}

export function initMoReveal(): void {
  const lines = document.querySelectorAll('.mo-anim, .mo-zoom')
  if (0 === lines.length) {
    return
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return
  }
  document.documentElement.classList.add('mo-reveal-armed')
  if (!('IntersectionObserver' in window)) {
    for (let i = 0; i < lines.length; i++) {
      lines[i].classList.add('mo-in')
    }
    return
  }
  observer = new IntersectionObserver((entries) => {
    for (let e = 0; e < entries.length; e++) {
      if (entries[e].isIntersecting) {
        entries[e].target.classList.add('mo-in')
        if (observer) {
          observer.unobserve(entries[e].target)
        }
      }
    }
  }, { threshold: 0.2 })
  for (let j = 0; j < lines.length; j++) {
    observer.observe(lines[j])
  }
  // Rescue sweep: if the observer never fires, above-fold lines would stay
  // masked behind the armed state. After a beat, reveal whatever is already
  // at or above the viewport.
  window.setTimeout(() => {
    const viewport = window.innerHeight || 0
    for (let k = 0; k < lines.length; k++) {
      if (lines[k].classList.contains('mo-in')) {
        continue
      }
      if (lines[k].getBoundingClientRect().top < viewport) {
        lines[k].classList.add('mo-in')
        if (observer) {
          observer.unobserve(lines[k])
        }
      }
    }
  }, 1200)
}
