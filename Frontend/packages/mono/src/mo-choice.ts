/**
 * mo-choice.ts — "why choose" four-quadrant section, timed loop.
 *
 * Ported from boson's right-choice-section.js (Lit); the timing, distances
 * and mobile toggling are identical, only the DOM vocabulary is mono's
 * (`mo-choice`, `mo-choice__inner`, `mo-choice__dot`). The loop runs one
 * 8-segment cycle forever: four 7 s holds alternated with four 500 ms
 * transitions. Each frame moves the left/right quadrant pairs against each
 * other and fills the progress dots.
 *
 * Self-gating: returns early when the page renders no `.mo-choice`, and
 * under reduced motion the section stays static (both halves visible, no
 * transforms, no timers).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

const BLOCK_MS = 7000
const TRANSITION_MS = 500
const DISTANCE_PX = 800
const DOT_COUNT = 30

interface ChoiceState {
  progress: number
  stage: number
  second: boolean
}

// Eight segments: hold, transition, hold, transition, … (hold = BLOCK_MS,
// transition = TRANSITION_MS). Block segments map their elapsed fraction to
// progress; transitions map theirs to the quadrant stage.
const SEGMENT_MS: number[] = [
  BLOCK_MS, TRANSITION_MS, BLOCK_MS, TRANSITION_MS,
  BLOCK_MS, TRANSITION_MS, BLOCK_MS, TRANSITION_MS,
]

const SEGMENTS: Array<(x: number) => ChoiceState> = [
  (x) => ({ progress: x * 0.5, stage: 0, second: false }),
  (x) => ({ progress: 0.5, stage: x, second: x > 0.5 }),
  (x) => ({ progress: 0.5 + x * 0.5, stage: 1, second: true }),
  (x) => ({ progress: 1, stage: 1 - x, second: x < 0.5 }),
  (x) => ({ progress: 1 - x * 0.5, stage: 0, second: false }),
  (x) => ({ progress: 0.5, stage: x, second: x > 0.5 }),
  (x) => ({ progress: 0.5 - x * 0.5, stage: 1, second: true }),
  (x) => ({ progress: 0, stage: 1 - x, second: x < 0.5 }),
]

export function choiceStateAt(elapsedMs: number): ChoiceState {
  const cycle = SEGMENT_MS.reduce((sum, ms) => sum + ms, 0)
  let pos = elapsedMs % cycle
  for (let i = 0; i < SEGMENTS.length; i++) {
    if (pos < SEGMENT_MS[i]) {
      return SEGMENTS[i](pos / SEGMENT_MS[i])
    }
    pos -= SEGMENT_MS[i]
  }
  return SEGMENTS[0](0)
}

export function initMoChoice(): void {
  const root = document.querySelector('.mo-choice') as HTMLElement | null
  if (!root) {
    return
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return
  }

  const q = (selector: string): HTMLElement | null => root.querySelector(selector) as HTMLElement | null
  const topLeft = q('.mo-choice__cell--top-left .mo-choice__inner')
  const topRight = q('.mo-choice__cell--top-right .mo-choice__inner')
  const bottomLeft = q('.mo-choice__cell--bottom-left .mo-choice__inner')
  const bottomRight = q('.mo-choice__cell--bottom-right .mo-choice__inner')
  const cellTopLeft = q('.mo-choice__row--top .mo-choice__cell--left')
  const cellTopRight = q('.mo-choice__row--top .mo-choice__cell--right')
  const cellBottomLeft = q('.mo-choice__row--bottom .mo-choice__cell--left')
  const cellBottomRight = q('.mo-choice__row--bottom .mo-choice__cell--right')
  const dots = root.querySelectorAll('.mo-choice__dot')
  const portrait = window.matchMedia('(orientation: portrait)')
  const startedAt = Date.now()

  const applyDesktop = (stage: number): void => {
    if (!topLeft || !topRight || !bottomLeft || !bottomRight) {
      return
    }
    topLeft.style.transform = 'translateX(' + stage * DISTANCE_PX + 'px)'
    topRight.style.transform = 'translateX(' + Math.min(0, -DISTANCE_PX + stage * DISTANCE_PX) + 'px)'
    bottomRight.style.transform = 'translateX(' + -(stage * DISTANCE_PX) + 'px)'
    bottomLeft.style.transform = 'translateX(' + Math.max(0, DISTANCE_PX - stage * DISTANCE_PX) + 'px)'
  }

  const toggle = (el: HTMLElement | null, visible: boolean): void => {
    if (!el) {
      return
    }
    el.classList.toggle('mo-choice--visible', visible)
    el.classList.toggle('mo-choice--hidden', !visible)
  }

  const applyMobile = (second: boolean): void => {
    toggle(cellTopLeft, !second)
    toggle(cellTopRight, second)
    toggle(cellBottomLeft, !second)
    toggle(cellBottomRight, second)
  }

  const updateDots = (progress: number): void => {
    const active = Math.floor(progress * DOT_COUNT)
    for (let i = 0; i < dots.length; i++) {
      dots[i].classList.toggle('mo-choice__dot--on', i < active)
    }
  }

  const frame = (): void => {
    const state = choiceStateAt(Date.now() - startedAt)
    if (portrait.matches) {
      applyMobile(state.second)
    } else {
      applyDesktop(state.stage)
    }
    updateDots(state.progress)
    window.requestAnimationFrame(frame)
  }

  root.classList.add('mo-choice--live')
  frame()
}
