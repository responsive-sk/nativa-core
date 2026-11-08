/**
 * griffe.ts — griffe theme frontend entry.
 *
 * One self-contained bundle for the deferred `<script>`: the three behaviours
 * GriffeTheme::pageWrap() used to inline per surface (header, reveal,
 * highlight) are self-gating inits here — each returns early when its markup
 * is absent, so a blog page that renders no header runs nothing. Keep the
 * bundle independent from the other theme stylesheets; the PHP layout selects
 * it through AssetManager('griffe').
 *
 * ## What is here, and why only this
 *
 * The entry registers EXACTLY what the PHP theme renders today:
 *
 *   - `hero-layout` + `horizontal-scroll-hero` — the landing (homepage).
 *   - `gr-header` hide-on-scroll, `gr-anim` reveal, `tok-*` highlighter —
 *     the three surfaces every other page uses.
 *
 * Everything else resp-front shipped (`boson-*`, sections, layouts, the mark
 * admin components) lives in `src/` but is deliberately NOT imported: each one
 * is bytes in a bundle every visitor downloads on every page, and the measured
 * difference was ~150 KB minified. `src/app.ts` is the full aggregator kept for
 * the day a surface renders more of those elements — import that file (or the
 * individual component) here then, and nowhere else.
 *
 * ## No framework runtime
 *
 * The two elements above are plain `HTMLElement` subclasses with a shadow
 * root, an observed-attribute list and direct DOM writes; so are the three
 * behaviours. Nothing in this graph imports Lit. That matters because Lit was
 * the bundle: on probe builds the runtime measured ~35 KB of a ~45 KB file —
 * 79 % of the JS — for a header that toggles one class and a carousel that
 * moves a track. `lit` and `chart.js` therefore sit in `devDependencies`
 * (`package.json`): `pnpm typecheck` still checks the opt-in `src/app.ts`
 * layer, but the build ships none of it. Measured after the rewrite: 28 806 B
 * minified / 9 190 B gzip, down from 44 830 B / 15 090 B. What is left is the
 * three behaviours plus the two components' markup logic and their shadow
 * stylesheets (7 606 B of the total); there is no framework runtime to pay for.
 *
 * The slider init (`gr-slider.ts`) is gone with the surface that used it: the
 * griffe hero slider no longer renders anywhere, so its init was dead code
 * walking the DOM for a `.gr-hero-slider` that no page emits.
 *
 * PJAX is gone too: no theme page renders `[data-container]`, so it set a
 * global flag and returned, while its four `console.log` lines ran on every
 * load.
 */

import './app.css'
import './griffe/index.css'
import { initGrHeader } from './gr-header'
import { initGrReveal } from './gr-reveal'
import { initGrCodeHighlight } from './gr-code-highlight'
import './layout/hero-layout'
import './components/sections/horizontal-scroll-hero'

// header, reveal, highlight — the order the inline scripts used to run in.
// The highlighter mutates code blocks (and force-adds `.gr-in` on the blocks
// it processes); the reveal observer reads the mask state first, so it goes
// before it.
const onDomReady = (): void => {
  initGrHeader()
  initGrReveal()
  initGrCodeHighlight()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}
