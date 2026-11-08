/**
 * minify-literals.ts — minify griffe's tagged `html`/`css` template literals.
 *
 * Replaces `rollup-plugin-minify-html-literals`, whose only HTML minifier is
 * `html-minifier@4.0.0`: deprecated, unpatched, and flagged by Dependabot for
 * CVE-2022-37620 (ReDoS in `reCustomIgnore`). Its maintained fork,
 * `html-minifier-terser`, exposes an ASYNC `minify()` — the old plugin calls it
 * synchronously and would receive a Promise — so the fork cannot be aliased in.
 * This plugin does the same job with the async API: parse the tagged templates,
 * minify their literal text, and overwrite only those ranges.
 *
 * The tag matching mirrors the plugin it replaces: `html`/`svg` for markup,
 * `css` for stylesheets. Expressions are replaced by a placeholder before
 * minification and split back afterwards, so `${...}` is never touched.
 */
import { createRequire } from 'node:module';
import MagicString from 'magic-string';
import type { Plugin } from 'vite';

interface TemplatePart {
  text: string;
  start: number;
  end: number;
}

interface Template {
  tag?: string;
  parts: TemplatePart[];
}

const require = createRequire(import.meta.url);
const CleanCSS = require('clean-css') as new (options: object) => {
  minify: (source: string) => { styles: string };
};
const { parseLiterals } = require('parse-literals') as {
  parseLiterals: (source: string, options?: { fileName?: string }) => Template[];
};
const { minify: minifyHTML } = require('html-minifier-terser') as {
  minify: (html: string, options: object) => Promise<string>;
};

/**
 * The option set the replaced plugin used (`defaultMinifyOptions`), so the
 * output keeps the same shape: collapsed whitespace, removed comments, case
 * preserved, no attribute-quote removal.
 */
const HTML_OPTIONS = {
  caseSensitive: true,
  collapseWhitespace: true,
  decodeEntities: true,
  minifyCSS: {},
  minifyJS: true,
  processConditionalComments: true,
  removeAttributeQuotes: false,
  removeComments: true,
  removeEmptyAttributes: true,
  removeScriptTypeAttributes: true,
  removeStyleLinkTypeAttributes: true,
  useShortDoctype: true,
};

function isHtmlTemplate(template: Template): boolean {
  const tag = template.tag?.toLowerCase();

  return !!tag && (tag.includes('html') || tag.includes('svg'));
}

function isCssTemplate(template: Template): boolean {
  return !!template.tag?.toLowerCase().includes('css');
}

/**
 * A placeholder that cannot collide with the literal's own text. `();` keeps
 * clean-css from dropping a trailing semicolon before the expression, which
 * would break the split-back.
 */
function placeholderFor(parts: TemplatePart[]): string {
  let placeholder = '@TEMPLATE_EXPRESSION();';

  while (parts.some(part => part.text.includes(placeholder))) {
    placeholder += '_';
  }

  return placeholder;
}

export function minifyTemplateLiterals(): Plugin {
  return {
    name: 'nativa:minify-template-literals',

    async transform(source, id) {
      if (!/\.[cm]?[jt]sx?$/.test(id) || id.includes('/node_modules/')) {
        return null;
      }

      const templates = parseLiterals(source, { fileName: id });
      const ms = new MagicString(source);
      let changed = false;

      for (const template of templates) {
        const asCss = isCssTemplate(template);

        if (!asCss && !isHtmlTemplate(template)) {
          continue;
        }

        const placeholder = placeholderFor(template.parts);
        const combined = template.parts.map(part => part.text).join(placeholder);
        const minified = asCss
          ? new CleanCSS({}).minify(combined).styles
          : await minifyHTML(combined, HTML_OPTIONS);
        const minParts = minified.split(placeholder);

        if (minParts.length !== template.parts.length) {
          throw new Error(
            `[minify-literals] ${id}: minified template split into ${minParts.length} parts, expected ${template.parts.length}`,
          );
        }

        template.parts.forEach((part, index) => {
          if (part.start < part.end) {
            ms.overwrite(part.start, part.end, minParts[index]);
            changed = true;
          }
        });
      }

      if (!changed) {
        return null;
      }

      return {
        code: ms.toString(),
        map: ms.generateMap({ file: `${id}.map`, hires: true, source: id }),
      };
    },
  };
}
