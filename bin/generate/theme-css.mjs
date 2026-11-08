#!/usr/bin/env node
/**
 * theme-css.mjs — the shared half of the per-theme generated stylesheets.
 *
 * `questions.css` and `form.css` are built the same way and by the same rules,
 * so the machinery lives here once and each generator is only its table:
 *
 *   bin/generate/generate-questions-css.mjs
 *   bin/generate/generate-form-css.mjs
 *
 * What is shared and what is per theme:
 *
 *   - the documentation comment and every rule come from the starter package,
 *     byte for byte, with the starter prefix swapped textually. A rule fixed
 *     there reaches every copy.
 *   - the palette block is the theme's design decision, so it is data in the
 *     table. It is the ONLY per-theme part of the file, which is why a theme
 *     cannot silently drift on the rules: it would have no file of its own
 *     left to drift in.
 *
 * Usage is the caller's: `node <generator>` writes, `--check` exits 1 on
 * drift without touching a file. The starter is the source and is never
 * written here.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { basename, resolve } from 'node:path';

/** The prefix every starter stylesheet is written in; swapped in each copy. */
const SOURCE_PREFIX = 'st-';

/**
 * Split a starter stylesheet at its own palette block and its first rule, so a
 * copy inherits the documentation and the rules without ever carrying the
 * starter's palette.
 */
function splitSource(source, sourcePath, firstRule, splitAt) {
    const paletteStart = source.indexOf(':root {');
    const paletteEnd = source.indexOf('/* --- end palette --- */');
    const rulesStart = source.indexOf(firstRule);
    const cut = undefined === splitAt ? -1 : source.indexOf(splitAt, rulesStart);

    if (-1 === paletteStart || -1 === paletteEnd || -1 === rulesStart) {
        throw new Error(
            `${sourcePath} is not the shape this generator expects: it needs ":root {", `
            + `the "/* --- end palette --- */" marker and a "${firstRule}" rule.`,
        );
    }

    if (undefined !== splitAt && -1 === cut) {
        throw new Error(`${sourcePath} no longer contains the split marker ${JSON.stringify(splitAt)}.`);
    }

    const bodyStart = source.indexOf('}', paletteEnd) + 1;

    return {
        doc: source.slice(0, paletteStart),
        rules: source.slice(bodyStart, -1 === cut ? undefined : cut).replace(/^\s*\n/, '').trimEnd(),
    };
}

/**
 * Render one copy: header, shared doc, the theme's palette, shared rules.
 *
 * The prefix swap is anchored to a token boundary on purpose: a plain
 * `replaceAll('st-', …)` also rewrites `list-style` into `lier-style`, which
 * is a valid CSS file no test in this tree reads. The guard after the swap
 * fails loudly instead of writing that file.
 */
function renderCopy({ doc, rules }, theme, { script, source }) {
    const prefixPattern = /(?<![\w-])st-/gu;
    const swap = (text) => text.replace(prefixPattern, theme.prefix);
    const rulesOut = swap(rules).trimEnd();
    const tail = (theme.tail ?? []).join('\n');

    if (/(?<![\w-])st-/u.test(rulesOut)) {
        throw new Error(`${theme.name}: a starter prefix survived the swap — check ${theme.target}.`);
    }

    return `/*
 * ${basename(theme.target)} — GENERATED, do not edit.
 * Source: ${source}
 * Regenerate: node ${script}
 * Prefix: ${theme.prefix}
 */

${swap(doc)}:root {
  /* --- palette (generated) --- */
${theme.palette.join('\n')}
  /* --- end palette --- */
}

${rulesOut}
${'' === tail ? '' : `\n${tail}\n`}`;
}

/**
 * Run one generator: write every copy, or (`check`) report the ones that no
 * longer match and exit 1.
 *
 * @param {object} options
 * @param {string} options.asset      the stylesheet's name, for the summary line
 * @param {string} options.script     the generator path, written into each header
 * @param {string} options.source     starter stylesheet, repo-relative
 * @param {string} options.firstRule  the first rule the source must contain
 * @param {Array}  options.themes     the table: name, prefix, target, palette
 * @param {string} [options.splitAt]  marker in the source where the shared body
 *                                    ends; everything after it belongs to the
 *                                    theme's own `tail`
 * @param {boolean} options.check     report instead of write
 */
export function generate({ asset, script, source, firstRule, splitAt, themes, check, root }) {
    const sourcePath = resolve(root, source);
    const split = splitSource(readFileSync(sourcePath, 'utf8'), source, firstRule, splitAt);

    let identical = 0;
    const diverged = [];

    for (const theme of themes) {
        const target = resolve(root, theme.target);
        const expected = renderCopy(split, theme, { script, source });

        if (!check) {
            writeFileSync(target, expected);
            continue;
        }

        let actual;

        try {
            actual = readFileSync(target, 'utf8');
        } catch {
            diverged.push(`${theme.target} (missing)`);
            continue;
        }

        if (actual === expected) {
            identical += 1;
            continue;
        }

        diverged.push(theme.target);

        const left = actual.split('\n');
        const right = expected.split('\n');
        const first = left.findIndex((line, index) => line !== right[index]);

        console.error(`DIVERGED ${theme.target}`);
        console.error(`  line ${first + 1}`);
        console.error(`  on disk:  ${JSON.stringify(left[first] ?? '<end of file>')}`);
        console.error(`  expected: ${JSON.stringify(right[first] ?? '<end of file>')}`);
    }

    if (check) {
        console.log(`${asset} --check: identical=${identical} diverged=${diverged.length}`);

        if (0 !== diverged.length) {
            console.error(`${asset}: run \`node ${script}\` without --check to regenerate the copies above.`);
            process.exit(1);
        }

        return;
    }

    console.log(`${asset}: wrote ${themes.length} copies from ${source}`);
}
