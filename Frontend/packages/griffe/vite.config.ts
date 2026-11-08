/**
 * vite.config.ts — the griffe theme bundle (public, selectable).
 *
 * Single entry, like erik: one self-contained file, no shared chunks — the
 * deferred `<script>` on the Chrome 70 baseline cannot execute ES module
 * imports. The entry keeps the three griffe behaviours (header, reveal,
 * highlight) self-gating and registers the two landing custom elements
 * adopted from `Frontend/resp-front` (`hero-layout`, `horizontal-scroll-hero`),
 * which are plain `HTMLElement` subclasses with no framework runtime.
 *
 * The `@/` alias mirrors the source's own convention (`tsconfig.json`
 * `paths`), so the unused opt-in components (`src/app.ts`) still resolve
 * without a rewrite.
 */
import { mergeConfig } from 'vite';
import { fileURLToPath } from 'url';
import { createThemeConfig } from '../build/vite-theme-factory.ts';
import { minifyTemplateLiterals } from './minify-literals.ts';

const src = fileURLToPath(new URL('./src', import.meta.url));

export default mergeConfig(
  createThemeConfig({
    theme: 'griffe',
    inputs: { griffe: 'src/griffe.ts' },
  }),
  {
    resolve: {
      alias: { '@': src },
    },
    // The two shipped components keep their stylesheets in `css` tagged
    // template literals, which the JS minifier copies verbatim: the comments
    // and indentation that make those stylesheets readable would otherwise be
    // shipped bytes. This plugin runs a real CSS/HTML minifier over literals
    // with those tags only, so the whitespace goes away without touching any
    // other string. The tag outlived Lit (`src/utils/css.ts`).
    plugins: [minifyTemplateLiterals()],
  },
);
