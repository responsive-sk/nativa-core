/**
 * erik.ts — erik theme frontend entry.
 *
 * The theme carries no custom JS of its own: the theme-init inline script
 * (Shell::themeInitScript) owns the data-theme restore, the shared toggle
 * handler ships in the layout, and the code highlighter + copy buttons run
 * from the shared module. Keep the bundle independent from the starter
 * stylesheet; the PHP layout selects it through AssetManager('erik').
 */

import './erik/index.css';
import { containerQueryManager } from './container-query';
import { initTocScrollspy } from './toc-scrollspy';
import { initCodeHighlight } from './code-highlight';
// The author's Markdown editor: toolbar + live preview over the canonical textarea.
import { initInlineEditor } from './inline-editor';

// The erik bundle carries the same three runtime helpers as the starter
// bundle, byte-identical copies (FrontendPackageParityTest). Two of them have
// markup to act on: initCodeHighlight on fenced blocks, and initTocScrollspy
// on the article rail's links (ErikTheme emits data-toc-link/data-heading with
// the "On this page" aside). No erik surface emits data-cq yet, so the
// container-query manager is wired and idle — it returns early without its
// selector, and a future surface that needs it does not have to touch the
// build.
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
