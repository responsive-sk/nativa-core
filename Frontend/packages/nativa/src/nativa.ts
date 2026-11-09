/**
 * nativa.ts — nativa theme frontend entry.
 *
 * One self-contained bundle for the deferred `<script>`. The theme was built
 * build-free on purpose, so this entry carries exactly one behaviour: the TOC
 * scrollspy that marks the section a reader is inside. Everything else the
 * theme does — the sticky header, the phone menu, the dark/light switch — is
 * CSS or a kernel-inlined handler, so none of it belongs here.
 *
 * The stylesheet imported below IS `modules/Themes/Nativa/assets/critical/nativa.css`,
 * not a copy: one design, one file, and the build cannot fall behind the
 * fallback. Keep the bundle independent from the other theme stylesheets; the
 * PHP layout selects it through AssetManager('nativa').
 */

import '../../../../modules/Themes/Nativa/assets/critical/nativa.css'
import { initNaTocScrollspy } from './na-toc-scrollspy'

const onDomReady = (): void => {
  initNaTocScrollspy()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}
