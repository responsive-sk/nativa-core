/**
 * boson.ts — boson theme frontend entry.
 *
 * Both sources stay in the module: `assets/critical/boson.css` is the
 * stylesheet `criticalCss()` inlines when there is no build, and
 * `assets/scripts/boson.js` is the vendored script `pageWrap()` inlines in the
 * same situation. Importing them here is what makes one pair of files serve
 * both worlds — the artifact gets a minified, hashed bundle, the module still
 * renders without one, and there is nothing to keep in sync.
 *
 * The import resolves through the CATALOGUE (`modules/Themes/Boson/`), not
 * `src/Modules/Themes/Boson/`: the catalogue is the tracked source, the
 * installed slice is derived from it and is gitignored, and the CI build step
 * runs `pnpm build` BEFORE `bin/build/install-catalogue.sh` — so at build time
 * the installed path does not exist yet and this import must not depend on it.
 *
 * `AssetManager` reads the emitted manifest: `cssUrls('boson')` is inlined in
 * `<head>` by FrontendLayout and `jsUrl('boson')` becomes the deferred
 * `<script src="/dist/boson/assets/boson-*.js">`. `pageWrap()` therefore
 * stops inlining the script once the bundle resolves, so the behaviour runs
 * once either way.
 */

import '../../../../modules/Themes/Boson/assets/critical/boson.css'
import '../../../../modules/Themes/Boson/assets/scripts/boson.js'

/* Embedded form block (Forms module), `bo-` prefix. The module's stylesheet
   styles the questions and comments blocks and predates this one, so the form
   lives here, next to the build that ships it, and the module stays a
   byte-verified copy of nativa-themes. */
import './form.css'
