/**
 * mono.ts — mono theme frontend entry.
 *
 * One self-contained bundle for the deferred `<script>`: the four
 * behaviours MonoTheme::pageWrap() used to inline per surface (variant
 * switcher, slider, TOC scrollspy, highlight) are self-gating inits here —
 * each returns early when its markup is absent, so pages without a slider,
 * fences or a TOC ship the bytes but run nothing. Keep the bundle
 * independent from the other theme stylesheets; the PHP layout selects it
 * through AssetManager('mono').
 */

import './mono/index.css'
import { initMoVariantSwitcher } from './mo-variant-switcher'
import { initMoSlider } from './mo-slider'
import { initMoReveal } from './mo-reveal'
import { initMoChoice } from './mo-choice'
import { initMoBrandLogo } from './mo-brand-logo'
import { initMoSteps } from './mo-steps'
import { initMoQuotes } from './mo-quotes'
import { initMoTocScrollspy } from './mo-toc-scrollspy'
import { initMoCodeHighlight } from './mo-code-highlight'

// Same order the inline scripts used to run in: the switcher first (its
// buttons precede every page and it syncs their pressed state on load),
// then the slider, scrollspy, highlight. None depends on another's output,
// but the order keeps the waterfall familiar.
const onDomReady = (): void => {
  initMoVariantSwitcher()
  initMoReveal()
  initMoSlider()
  initMoChoice()
  initMoBrandLogo()
  initMoSteps()
  initMoQuotes()
  initMoTocScrollspy()
  initMoCodeHighlight()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}
