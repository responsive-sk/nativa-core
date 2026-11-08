/**
 * vite.config.ts — the starter theme bundle (public default).
 *
 * Builds `src/main.ts` into `public/dist/starter/` with a Vite manifest
 * (`manifest.json`) that the PHP AssetManager resolves. The old-browser
 * baseline (ES2015 / Chrome 70), the @layer stripping and the single-file
 * classic-script contract are fixed by the shared factory.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'starter',
  inputs: { main: 'src/main.ts' },
});
