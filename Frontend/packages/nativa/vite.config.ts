/**
 * vite.config.ts — the nativa theme bundle (public, selectable).
 *
 * Single entry, like mono: one self-contained file, no shared chunks — the
 * deferred `<script>` on the Chrome 70 baseline cannot execute ES module
 * imports. The one behaviour (TOC scrollspy) is a self-gating init inside the
 * entry, so a page with no contents rail ships the bytes but runs nothing.
 *
 * The stylesheet is the module's own critical file, imported rather than
 * copied: this theme has one design, and a second copy under `src/` would be
 * the first place the two drift. The bundle therefore adds BEHAVIOUR and a
 * manifest; the CSS it inlines is byte-identical to the no-build fallback.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'nativa',
  inputs: { nativa: 'src/nativa.ts' },
});
