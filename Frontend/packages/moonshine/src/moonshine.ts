/**
 * moonshine.ts — MoonShine theme frontend entry.
 *
 * Loaded on moonshine-themed pages — the shell resolves the built entry
 * from the Vite manifest for both roles (the path-based `/mark/*` admin
 * resolution and the publicly selectable theme).
 *
 * The stylesheet is the combined starter base + moonshine component
 * styles; the chrome inline script wires the theme toggle, burger drawer,
 * sidebar collapse (localStorage persistence) and back-to-top. This entry
 * imports the deferred stylesheet and starts the shared runtime helpers,
 * whose bytes are duplicated per package so no bundle depends on another
 * package's sources (FrontendPackageParityTest).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax — the
 * build additionally transpiles with target 'es2015' as a belt-and-braces
 * guarantee (same as main.ts).
 */

import './moonshine/index.css';
import { initTocScrollspy } from './toc-scrollspy';
import { initCodeHighlight } from './code-highlight';
// The author's Markdown editor: toolbar + live preview over the canonical textarea.
import { initInlineEditor } from './inline-editor';

// The same runtime helpers as the starter and erik bundles, byte-identical
// copies (FrontendPackageParityTest). Both have markup to act on today:
// moonshine's article view renders ms-toc__link with the data-heading opt-in
// the scrollspy queries, and article bodies carry the code highlighter hooks.
const onDomReady = (): void => {
  initTocScrollspy()
  initCodeHighlight()
  initInlineEditor()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}