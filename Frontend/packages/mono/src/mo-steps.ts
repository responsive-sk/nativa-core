/**
 * mo-steps.ts — horizontal accordion for the mono steps section.
 *
 * Ported from boson's horizontal-accordion.js (Lit). Clicking or pressing
 * Enter/Space on a closed step opens it and closes the others; the CSS does
 * the expansion, the fade-in and the grayscale-to-colour hover. The step
 * text ships in the HTML, so the server-rendered open step reads without JS.
 *
 * Self-gating: returns early when the page renders no `.mo-steps__accordion`.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export function initMoSteps(): void {
  const root = document.querySelector('.mo-steps__accordion') as HTMLElement | null
  if (!root) {
    return
  }
  const steps = root.querySelectorAll('.mo-step')

  const open = (index: number): void => {
    for (let i = 0; i < steps.length; i++) {
      const on = i === index
      steps[i].classList.toggle('mo-step--open', on)
      steps[i].classList.toggle('mo-step--closed', !on)
      steps[i].setAttribute('aria-expanded', on ? 'true' : 'false')
    }
  }

  for (let i = 0; i < steps.length; i++) {
    const step = steps[i]
    const index = i
    step.addEventListener('click', () => {
      open(index)
    })
    step.addEventListener('keydown', (event: Event) => {
      const key = (event as KeyboardEvent).key
      if ('Enter' === key || ' ' === key) {
        event.preventDefault()
        open(index)
      }
    })
  }
}
