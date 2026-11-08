/**
 * vite.config.ts — the moonshine theme bundle (admin chrome behind /mark).
 *
 * Three entries in one build: the `moonshine` theme entry plus the admin
 * `mark` and `mark-switch` stylesheets that `AdminLayout::markCssLinks()`
 * resolves from this package's manifest. The input keys are load-bearing —
 * `AssetManager::manifestEntry()` probes `src/<key>.ts`, `src/<key>.css` and
 * `src/<key>/index.css` — so a key may never be renamed. The admin design
 * system is itself split into ordered section files under `src/mark/`, which
 * is why its input is `src/mark/index.css` rather than a single `mark.css`.
 *
 * `codeSplitting: false` (from the factory) keeps every emitted file
 * self-contained: the entries share no JavaScript, so no chunk is extracted.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'moonshine',
  inputs: {
    moonshine: 'src/moonshine.ts',
    mark: 'src/mark/index.css',
    'mark-switch': 'src/mark-switch.css',
  },
});
