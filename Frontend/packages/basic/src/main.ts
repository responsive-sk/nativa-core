/**
 * main.ts — minimal starter frontend entry.
 *
 * Imported CSS is bundled and emitted alongside the JS; the PHP layout
 * resolves both via the Vite manifest (AssetManager).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

import './base/index.css';
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

// ── Init ───────────────────────────────────────────────────────

const onDomReady = (): void => {
  containerQueryManager.init()
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
