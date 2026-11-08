/**
 * `css` — a no-dependency tag for stylesheet strings.
 *
 * Lit used to own this tag: `css` returned a `CSSResult` that a LitElement
 * adopted into its shadow root. The runtime went with the components (see
 * `src/layout/hero-layout.ts`), but the tag stays because the build keys on it:
 * `rollup-plugin-minify-html-literals` runs a real CSS minifier over `css`
 * literals (and an HTML minifier over `html` ones) and copies every other
 * string verbatim. Keeping the tag keeps the readable, commented stylesheet in
 * the source and the minified bytes in the bundle — without a component
 * framework in between.
 *
 * The function itself is a join: the components that use it have no runtime
 * interpolation in their CSS.
 */
export const css = (strings: TemplateStringsArray): string => strings.join('');
