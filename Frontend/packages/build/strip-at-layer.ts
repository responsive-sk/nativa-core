/**
 * strip-at-layer.ts — Vite/Rollup plugin to strip CSS `@layer` syntax.
 *
 * **Problem:** Chrome 70 (common on Android 9) doesn't support CSS `@layer`
 * (added in Chrome 99). When the browser encounters `@layer ... { ... }` or
 * `@layer name, name;` it ignores the entire construct, causing all CSS
 * inside layer blocks to be dropped — the page appears unstyled.
 *
 * **Solution:** This plugin post-processes every emitted `.css` file in the
 * `closeBundle` hook (after all files are written to disk) and:
 *   1. Removes standalone `@layer name, name, …;` definitions
 *   2. Converts `@import '…' layer(name);` → `@import '…';`
 *   3. Unwraps `@layer name {…}` blocks, keeping only their inner content
 *
 * Uses `closeBundle` instead of `generateBundle` for compatibility with
 * Vite 8+ / rolldown, where `generateBundle` doesn't reliably process
 * CSS assets.
 *
 * Usage:
 *   import { stripAtLayerPlugin } from './strip-at-layer.ts';
 *   plugins: [stripAtLayerPlugin(distDir)],
 */

import type { Plugin } from 'vite';
import { readdirSync, readFileSync, writeFileSync } from 'fs';
import { resolve, extname } from 'path';

/**
 * Recursively find all .css files in a directory tree.
 */
function findCssFiles(dir: string): string[] {
  const result: string[] = [];
  try {
    const entries = readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const fullPath = resolve(dir, entry.name);
      if (entry.isDirectory()) {
        result.push(...findCssFiles(fullPath));
      } else if (entry.isFile() && extname(entry.name) === '.css') {
        result.push(fullPath);
      }
    }
  } catch {
    // Directory doesn't exist — skip
  }
  return result;
}

/**
 * Unwrap `@layer name {…}` blocks, returning just the inner content.
 * Uses a character-by-character state machine to handle nested `{ }`.
 */
function unwrapLayerBlocks(css: string): string {
  const parts: string[] = [];
  let i = 0;
  const len = css.length;

  while (i < len) {
    // Skip CSS comments — prevents `@layer` inside `/* … */` from being treated as a real rule
    if (css[i] === '/' && i + 1 < len && css[i + 1] === '*') {
      const commentEnd = css.indexOf('*/', i + 2);
      if (commentEnd === -1) {
        // Unclosed comment — keep rest as-is
        parts.push(css.slice(i));
        break;
      }
      parts.push(css.slice(i, commentEnd + 2));
      i = commentEnd + 2;
      continue;
    }

    // Look for `@layer` — check after whitespace, at start of line, or after `{`/`;`/`}`
    if (
      css[i] === '@' &&
      i + 6 <= len &&
      css.slice(i, i + 6) === '@layer'
    ) {
      const afterLayer = i + 6;
      // @layer must be followed by whitespace, `{`, `;`, or end-of-file — not part of another word
      if (afterLayer < len && css[afterLayer] !== ' ' && css[afterLayer] !== '\n' && css[afterLayer] !== '\t' && css[afterLayer] !== '{' && css[afterLayer] !== ';') {
        parts.push(css[i]);
        i++;
        continue;
      }
      const blockStart = i;
      i += 6; // skip '@layer'

      // Skip whitespace after `@layer`
      while (i < len && (css[i] === ' ' || css[i] === '\n' || css[i] === '\t')) {
        i++;
      }

      // Skip layer name(s) and whitespace until `{` or `;` or `}`
      while (i < len && css[i] !== '{' && css[i] !== ';' && css[i] !== '}') {
        i++;
      }

      if (i < len && css[i] === '{') {
        // @layer name {…} — unwrap, keep only inner content
        const openBrace = i;
        let depth = 1;
        i = openBrace + 1;
        while (i < len && depth > 0) {
          if (css[i] === '{') depth++;
          if (css[i] === '}') depth--;
          if (depth > 0) i++;
        }
        // i is now at the closing `}`
        const innerContent = css.slice(openBrace + 1, i);
        parts.push(innerContent);
        i++; // skip the `}`
      } else if (i < len && css[i] === ';') {
        // @layer name; or @layer name, name; — standalone definition, remove entirely
        i++; // skip the `;`
      } else {
        // Not a valid @layer construct — keep as-is
        parts.push(css.slice(blockStart, i));
      }
    } else {
      parts.push(css[i]);
      i++;
    }
  }

  return parts.join('');
}

/**
 * Strip @layer syntax from CSS content.
 *
 * Runs in a loop because `@layer` blocks can be nested (e.g. `@layer pages {
 * @layer cards { … } }`). Each pass unwraps only the outermost layer blocks,
 * exposing inner ones for the next pass.
 */
function stripAtLayerFromCSS(css: string): string {
  let result = css;

  for (let pass = 0; pass < 10; pass++) {
    if (!result.includes('@layer')) break;

    // 1. Remove standalone @layer definitions: `@layer name, name, …;`
    let passResult = result.replace(
      /@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*;/g,
      '',
    );

    // 2. Convert `@import '…' layer(name);` → `@import '…';`
    passResult = passResult.replace(
      /(@import\s+(['"])(?:(?!\2).)*\2)\s*layer\s*\([^)]*\)\s*;/g,
      '$1;',
    );

    // 3. Unwrap `@layer name {…}` blocks
    passResult = unwrapLayerBlocks(passResult);

    if (passResult === result) break; // no change — done
    result = passResult;
  }

  // Clean up excessive blank lines left by removals
  result = result.replace(/\n{3,}/g, '\n\n');

  return result;
}

export function stripAtLayerPlugin(outDir: string): Plugin {
  return {
    name: 'strip-at-layer',
    closeBundle() {
      const cssFiles = findCssFiles(outDir);

      if (cssFiles.length === 0) {
        this.warn(`strip-at-layer: No CSS files found in ${outDir}`);
        return;
      }

      let strippedCount = 0;
      for (const filePath of cssFiles) {
        const original = readFileSync(filePath, 'utf-8');

        if (!original.includes('@layer')) continue;

        const stripped = stripAtLayerFromCSS(original);
        writeFileSync(filePath, stripped, 'utf-8');
        strippedCount++;
      }

      if (strippedCount > 0) {
        this.warn(
          `strip-at-layer: Stripped @layer from ${strippedCount} CSS file(s) in ${outDir}`,
        );
      }
    },
  };
}
