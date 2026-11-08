/**
 * main.ts — the jednotka frontend entry.
 *
 * `jednotka/index.css` is this theme's OWN design system, not a stamped copy
 * of the starter base: `bin/generate/stamp-theme-base.mjs` derives a caricature's
 * stylesheet, and this theme is not a caricature. Nothing under `src/jednotka/`
 * inherits a rule from starter's base.
 *
 * Imported CSS is bundled and emitted alongside the JS; the PHP layout
 * resolves both via the Vite manifest (AssetManager).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

import './jednotka/index.css';
// The form block is per theme, not a shared base section — see form.css.
import './form.css';
// The comment block is per theme, not a shared base section — see comments.css.
import './comments.css';
// The reader-questions block is per theme too — see questions.css.
import './questions.css';
// The owner-edit affordance is per theme too — see edit-link.css.
import './edit-link.css';
// The inline Markdown editor is module markup with this theme's prefix.
import './inline-edit.css';
import { containerQueryManager } from './container-query';
import { initTocScrollspy } from './toc-scrollspy';
import { initCodeHighlight } from './code-highlight';
// The author's Markdown editor: toolbar + live preview over the canonical textarea.
import { initInlineEditor } from './inline-editor';

// ── PWA manifest ───────────────────────────────────────────────

const injectManifest = (): void => {
  if (document.querySelector('link[rel="manifest"]') !== null) return
  const link = document.createElement('link')
  link.rel = 'manifest'; link.href = '/site.webmanifest'
  document.head.appendChild(link)
}

// The contents sidebar ships OPEN: a closed <details> cannot be forced open
// by CSS (Chrome hides the slot via ::details-content), and the summary
// that would open it is hidden on wide screens — so a closed sidebar is an
// empty column beside the prose. Below 62em the index grid goes single
// column and the sidebar wants its native disclosure instead, and that is
// a viewport question the server cannot answer. Without JS the list simply
// stays open: visible contents, which is the harmless direction to fail in.
const collapseTocOnNarrowScreens = (): void => {
  const strip = document.querySelector('.je-article-toc__disclosure') as HTMLDetailsElement | null
  if (strip === null) return
  if (window.matchMedia('(max-width: 61.99em)').matches) strip.open = false
}

// ── Init ───────────────────────────────────────────────────────

const onDomReady = (): void => {
  containerQueryManager.init()
  collapseTocOnNarrowScreens()
  initTocScrollspy()
  initCodeHighlight()
  initInlineEditor()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}

window.addEventListener('load', injectManifest)
