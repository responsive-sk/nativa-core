/**
 * jednotka theme-init — restore the colour mode before first paint.
 *
 * jednotka is DARK-FIRST by construction: `:root` in tokens.css IS the dark
 * palette and `:root[data-theme="light"]` is the override. The shared
 * FrontendLayout init cannot serve that reading, because it resolves a
 * missing preference with `window.matchMedia(...)` unconditionally — on a
 * browser (or an environment) where matchMedia is missing or throws, the
 * ternary never runs, the whole block is swallowed by the catch, and no
 * attribute is written at all.
 *
 * The resolution order here is therefore explicit about its last step:
 *
 *   1. the visitor's own choice (persisted by the shared toggle handler)
 *   2. the OS preference, when the browser can actually be asked
 *   3. DARK — the theme's own default, the one its `:root` already is
 *
 * Plain ES5 for the Chrome 70 baseline. Runs before first paint; the nonce
 * tag stays in PHP (FrontendLayout / the theme module owns the wrapper).
 */
(function () {
    try {
        var theme = localStorage.getItem('nativa-theme');

        if (!theme) {
            var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
            theme = query && typeof query.matches === 'boolean' ? (query.matches ? 'dark' : 'light') : 'dark';
        }

        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        // No localStorage, no document — the stylesheet's own :root is dark,
        // which is the same answer step 3 gives, so there is nothing to do.
    }
})();
