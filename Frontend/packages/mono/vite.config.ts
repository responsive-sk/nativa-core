/**
 * vite.config.ts — the mono theme bundle (public, selectable).
 *
 * Single entry, like griffe: one self-contained file, no shared chunks —
 * the deferred `<script>` on the Chrome 70 baseline cannot execute
 * ES module imports. The three behaviours (variant switcher, TOC
 * scrollspy, highlight) are self-gating inits inside the entry, which
 * replaces the per-surface PHP gating MonoTheme::pageWrap() used to do.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'mono',
  inputs: { mono: 'src/mono.ts' },
});
