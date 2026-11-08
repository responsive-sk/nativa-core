/**
 * vite.config.ts — the minimal reference theme bundle.
 *
 * Minimal is unwired reference code (no route resolves to it, ADR-0001),
 * but it builds like every other theme so its CSS is held to the same
 * old-browser baseline and its `--min-*` tokens are auditable in the
 * built output.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'minimal',
  inputs: { minimal: 'src/minimal.ts' },
});
