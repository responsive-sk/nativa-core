/**
 * mo-brand-logo.ts — the boson dot-grid mark, scaled for the navbar brand.
 *
 * Ported from boson's boson-logo (logo.js): concentric rings of square dots,
 * an inner grey/red core, mouse repulsion inside the mark and random dimming
 * of single dots. The Lit component is replaced by a plain DOM build, and
 * the geometry follows the element's box instead of the 550px design size,
 * with minimum dot and spacing sizes so the grid still reads at navbar scale.
 *
 * Self-gating: returns early when the page renders no `.mo-brand-dots`, and
 * under reduced motion the dots are drawn once and stay still.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

interface Dot {
  homeX: number
  homeY: number
  x: number
  y: number
  el: HTMLElement
}

const OUTER_COLOR_CLASS = 'mo-dot'
const INNER_CLASS = 'mo-dot--inner'
const DIMMED_CLASS = 'mo-dot--dimmed'

export function initMoBrandLogo(): void {
  const host = document.querySelector('.mo-brand-dots') as HTMLElement | null
  if (!host) {
    return
  }
  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches

  let dots: Dot[] = []
  let timers: number[] = []
  let radius = 0
  let mouseRadius = 0
  let strength = 0
  let mouseX = -1000
  let mouseY = -1000
  let targetX = -1000
  let targetY = -1000
  let hovered = false

  const clearTimers = (): void => {
    for (let i = 0; i < timers.length; i++) {
      window.clearInterval(timers[i])
    }
    timers = []
  }

  const addDot = (x: number, y: number, inner: boolean, size: number): void => {
    const el = document.createElement('span')
    el.className = OUTER_COLOR_CLASS + (inner ? ' ' + INNER_CLASS : '')
    el.style.left = x + 'px'
    el.style.top = y + 'px'
    el.style.width = size + 'px'
    el.style.height = size + 'px'
    el.style.transform = 'translate(-50%, -50%)'
    host.appendChild(el)
    dots.push({ homeX: x, homeY: y, x: x, y: y, el: el })
  }

  const build = (): void => {
    clearTimers()
    host.innerHTML = ''
    dots = []

    const w = host.clientWidth
    const h = host.clientHeight
    if (!w || !h) {
      return
    }
    const cx = w / 2
    const cy = h / 2
    radius = Math.min(w, h) / 2
    const size = Math.max(1.2, radius * 0.1)
    const spacing = Math.max(2.5, radius * 0.22)
    mouseRadius = radius * 0.6
    strength = radius * 0.1

    // Outer rings: three concentric layers, dots evenly spaced on each.
    const outerR = radius - size
    for (let layer = 0; layer < 3; layer++) {
      const r = outerR - layer * spacing
      if (r <= 0) {
        break
      }
      const count = Math.max(6, Math.floor((2 * Math.PI * r) / spacing))
      for (let i = 0; i < count; i++) {
        const a = (i / count) * Math.PI * 2
        addDot(cx + Math.cos(a) * r, cy + Math.sin(a) * r, false, size)
      }
    }

    // Inner core: rings shrink toward the centre; the last dot sits centred.
    const coreR = radius * 0.3
    for (let layer = 0; layer < 2; layer++) {
      const r = coreR - layer * spacing
      if (r < spacing) {
        addDot(cx, cy, true, size)
        break
      }
      const count = Math.max(6, Math.floor((2 * Math.PI * r) / spacing))
      for (let i = 0; i < count; i++) {
        const a = (i / count) * Math.PI * 2
        addDot(cx + Math.cos(a) * r, cy + Math.sin(a) * r, true, size)
      }
    }

    if (!reduced) {
      for (let i = 0; i < dots.length; i++) {
        const el = dots[i].el
        if (Math.random() > 0.96) {
          el.classList.add(DIMMED_CLASS)
        }
        timers.push(window.setInterval(() => {
          if (Math.random() > 0.3) {
            el.classList.toggle(DIMMED_CLASS)
          }
        }, 500 + Math.random() * 3000))
      }
    }
  }

  const frame = (): void => {
    if (hovered) {
      mouseX += (targetX - mouseX) * 0.5
      mouseY += (targetY - mouseY) * 0.5
    } else {
      mouseX = -1000
      mouseY = -1000
    }
    const mr2 = mouseRadius * mouseRadius
    for (let i = 0; i < dots.length; i++) {
      const d = dots[i]
      const dx = d.homeX - mouseX
      const dy = d.homeY - mouseY
      const dist2 = dx * dx + dy * dy
      if (dist2 < mr2 && dist2 > 0) {
        const dist = Math.sqrt(dist2)
        const push = ((mouseRadius - dist) / mouseRadius) * strength
        const ox = (dx / dist) * push
        const oy = (dy / dist) * push
        d.el.style.transform = 'translate(calc(-50% + ' + ox + 'px), calc(-50% + ' + oy + 'px))'
      } else {
        d.el.style.transform = 'translate(-50%, -50%)'
      }
    }
    window.requestAnimationFrame(frame)
  }

  build()

  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(() => build()).observe(host)
  }

  if (reduced) {
    return
  }

  host.addEventListener('pointerenter', () => {
    hovered = true
  })
  host.addEventListener('pointerleave', () => {
    hovered = false
  })
  host.addEventListener('pointermove', (event: PointerEvent) => {
    const rect = host.getBoundingClientRect()
    targetX = event.clientX - rect.left
    targetY = event.clientY - rect.top
  })

  window.requestAnimationFrame(frame)
}
