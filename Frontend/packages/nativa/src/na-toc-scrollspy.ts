/**
 * na-toc-scrollspy.ts — highlights the Nativa contents-rail link of the
 * section currently in view.
 *
 * The markup contract is the kernel's, not this theme's: a link opts in with
 * `data-toc-link` + `data-heading`, which is what `MarkdownRenderer`'s
 * extracted headings and the docs reader already emit for every theme. The
 * active class is read off each link's own BEM block (`na-toc__link` →
 * `na-toc__link--active`), so this file names no theme.
 *
 * A passive scroll listener compares each heading's offset against a marker a
 * third of the way down the viewport and marks the last heading above it.
 * Computes once on init, so a `#fragment` deep link opens with the right link
 * highlighted before any scrolling happens.
 *
 * Self-gating: returns early when the page renders no opted-in links, which is
 * every page except a long article or guide.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

type TocEntry = { heading: Element; links: Element[] }

function activeClassFor(link: Element): string {
  let block = ''
  for (let i = 0; i < link.classList.length; i++) {
    const name = link.classList[i]
    // Skip BEM modifiers (`…--sub`) and keep the plain element (`…__link`).
    if (name.indexOf('__') !== -1 && name.indexOf('--') === -1) {
      block = name
      break
    }
  }
  return '' === block ? 'na-toc__link--active' : block + '--active'
}

function collectEntries(): TocEntry[] {
  const entries: TocEntry[] = []
  const links = document.querySelectorAll('[data-toc-link][data-heading]')

  for (let i = 0; i < links.length; i++) {
    const id = links[i].getAttribute('data-heading')
    if (null === id) {
      continue
    }

    const heading = document.getElementById(id)
    if (null === heading) {
      continue
    }

    let existing: TocEntry | null = null
    for (let j = 0; j < entries.length; j++) {
      if (entries[j].heading === heading) {
        existing = entries[j]
        break
      }
    }

    if (null !== existing) {
      existing.links.push(links[i])
    } else {
      entries.push({ heading: heading, links: [links[i]] })
    }
  }

  return entries
}

function markActive(entries: TocEntry[], marker: number): void {
  let activeIndex = 0

  for (let i = 0; i < entries.length; i++) {
    if (entries[i].heading.getBoundingClientRect().top <= marker) {
      activeIndex = i
    }
  }

  for (let i = 0; i < entries.length; i++) {
    for (let j = 0; j < entries[i].links.length; j++) {
      const isActive = i === activeIndex
      entries[i].links[j].classList.toggle(activeClassFor(entries[i].links[j]), isActive)

      // Surface the current section to screen readers alongside the visual
      // highlight — the links are already labelled by their own text.
      if (isActive) {
        entries[i].links[j].setAttribute('aria-current', 'location')
      } else {
        entries[i].links[j].removeAttribute('aria-current')
      }
    }
  }
}

export function initNaTocScrollspy(): void {
  const entries = collectEntries()

  if (0 === entries.length) {
    return
  }

  const update = (): void => {
    markActive(entries, window.innerHeight / 3)
  }

  window.addEventListener('scroll', update, { passive: true })
  window.addEventListener('resize', update, { passive: true })
  update()
}
