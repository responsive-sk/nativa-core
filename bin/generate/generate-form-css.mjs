#!/usr/bin/env node
/**
 * generate-form-css.mjs — one embedded-form block, one copy per theme.
 *
 * `FormBlockRenderer` (the Forms module) builds every class from the ACTIVE
 * THEME'S PREFIX, so `form.css` has to exist under each prefix: a theme
 * without one renders the contact form as browser defaults inside an
 * otherwise designed page. The rules come from the starter; the palette and
 * the tail are per theme. The shared half lives in `theme-css.mjs`, which
 * `generate-questions-css.mjs` uses too.
 *
 * `tail` is the part a generator would otherwise delete: each theme's own
 * override block (a raised panel, a radius, a square poll bar) plus the one
 * responsive rule that belongs to it. It is carried here as data because it
 * outlives the shared body, and a theme files no separate sheet to keep it in.
 *
 * Usage:
 *
 *   node bin/generate/generate-form-css.mjs           # write every copy
 *   node bin/generate/generate-form-css.mjs --check   # exit 1 on drift
 *
 * Boson is absent on purpose: its copy is hand-written and carries no
 * generated header. Mono has no copy at all, which is a known gap rather than
 * a decision: its frozen bundle has ~500 B of headroom under
 * `MonoBuildFreezeTest`'s budget.
 */
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { generate } from './theme-css.mjs';

const SCRIPT = 'bin/generate/generate-form-css.mjs';
const SOURCE = 'Frontend/packages/starter/src/form.css';

/**
 * Where the shared body stops. The starter's small-screen section is copied
 * per theme instead, because every theme wrote its own: an override block and
 * the one responsive rule that belongs to it, in that order.
 */
const SMALL_SCREENS = `/* ${'-'.repeat(75)}\n * Small screens`;

const THEMES = [
    {
        name: 'basic',
        prefix: 'ba-',
        target: 'Frontend/packages/basic/src/form.css',
        palette: [
            '  --form-page-bg: var(--ba-bg);',
            '  --form-surface: var(--ba-surface);',
            '  --form-ink: var(--ba-ink);',
            '  --form-hint: var(--ba-muted);',
            '  --form-border: var(--ba-border);',
            '  --form-accent: var(--ba-accent);',
            '  --form-accent-bg: var(--ba-accent-strong);',
            '  --form-accent-hover: var(--ba-accent-hover);',
            '  --form-error-bg: rgba(239, 68, 68, 0.12);',
            '  --form-error-ink: var(--ba-error-ink);',
            '  --form-accent-ink: #ffffff;',
            '  --form-radius: 6px;',
        ],
        tail: [
            '/* basic override — none. The caricature keeps the starter geometry. */',
            '',
            '@media (max-width: 560px) {',
            '  .ba-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
    {
        name: 'erik',
        prefix: 'er-',
        target: 'Frontend/packages/erik/src/erik/form.css',
        palette: [
            '  --form-page-bg: var(--er-bg);',
            '  --form-surface: var(--er-surface);',
            '  --form-ink: var(--er-ink);',
            '  --form-hint: var(--er-muted);',
            '  --form-border: var(--er-border);',
            '  --form-accent: var(--er-accent-soft);',
            '  --form-accent-bg: var(--er-accent-strong);',
            '  --form-accent-hover: var(--er-accent-hover);',
            '  --form-error-bg: var(--er-error-bg);',
            '  --form-error-ink: var(--er-error-ink);',
            '  --form-accent-ink: var(--er-accent-ink);',
            '  --form-radius: .5rem;',
        ],
        tail: [
            '/*',
            ' * erik override — the only geometry that differs from the shared body: its',
            ' * form is a raised panel rather than a bordered card, matching the article',
            ' * surface it lands on.',
            ' */',
            '.er-form {',
            '  background: var(--er-surface-raised);',
            '  box-shadow: var(--er-shadow);',
            '  border: 0;',
            '  padding: 28px;',
            '}',
            '',
            '.er-form__submit {',
            '  color: var(--er-accent-ink);',
            '}',
            '',
            '@media (max-width: 560px) {',
            '  .er-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
    {
        name: 'griffe',
        prefix: 'gr-',
        target: 'Frontend/packages/griffe/src/griffe/form.css',
        palette: [
            // Griffe ships no raised surface token, so the relationship the
            // starter palette states is inverted here: the panel is the paper
            // and the recessed fill (inputs, bar track, thanks) is the hairline
            // colour, which flips with `[data-theme="dark"]`.
            '  --form-page-bg: var(--gr-line);',
            '  --form-surface: var(--gr-paper);',
            '  --form-ink: var(--gr-ink);',
            '  --form-hint: var(--gr-muted);',
            '  --form-border: var(--gr-line);',
            '  --form-accent: var(--gr-accent);',
            '  --form-accent-bg: var(--gr-ink);',
            '  --form-accent-hover: var(--gr-accent);',
            '  --form-error-bg: rgba(239, 68, 68, 0.12);',
            '  --form-error-ink: var(--gr-ink);',
            '  --form-accent-ink: var(--gr-paper);',
            '  --form-radius: var(--gr-radius);',
        ],
        tail: [
            '/* griffe override: none. The shared body already sits inside the',
            '   ruled, hairline vocabulary this theme uses everywhere else. */',
            '',
            '@media (max-width: 560px) {',
            '  .gr-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
    {
        name: 'jednotka',
        prefix: 'je-',
        target: 'Frontend/packages/jednotka/src/form.css',
        palette: [
            '  --form-page-bg: var(--je-bg);',
            '  --form-surface: var(--je-surface);',
            '  --form-ink: var(--je-ink);',
            '  --form-hint: var(--je-muted);',
            '  --form-border: var(--je-border);',
            '  --form-accent: var(--je-accent);',
            '  --form-accent-bg: var(--je-accent-strong);',
            '  --form-accent-hover: var(--je-accent-hover);',
            '  --form-error-bg: rgba(239, 68, 68, 0.12);',
            '  --form-error-ink: var(--je-error-ink);',
            '  --form-accent-ink: #ffffff;',
            '  --form-radius: 6px;',
        ],
        tail: [
            '/* jednotka override — none. The caricature keeps the starter geometry. */',
            '',
            '@media (max-width: 560px) {',
            '  .je-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
    {
        name: 'magazine',
        prefix: 'mag-',
        target: 'Frontend/packages/magazine/src/magazine/form.css',
        palette: [
            // Neutrals come from the shared starter base in `src/base/tokens.css`.
            '  --form-page-bg: var(--st-bg);',
            '  --form-surface: var(--st-surface);',
            '  --form-ink: var(--st-ink);',
            '  --form-hint: var(--st-muted);',
            '  --form-border: var(--st-border);',
            '  --form-accent: var(--mag-accent-soft);',
            '  --form-accent-bg: var(--mag-accent-strong);',
            '  --form-accent-hover: var(--mag-accent-hover);',
            '  --form-error-bg: rgba(239, 68, 68, 0.12);',
            '  --form-error-ink: var(--st-error-ink);',
            '  --form-accent-ink: #ffffff;',
            '  --form-radius: 4px;',
        ],
        tail: [
            '/*',
            ' * magazine override — the editorial rules run thin and square, and the poll',
            ' * bar is a solid amber rule rather than a pill.',
            ' */',
            '.mag-form {',
            '  border-radius: 4px;',
            '  border-width: 1px;',
            '}',
            '',
            '.mag-form__bar-fill {',
            '  background: var(--mag-accent-strong);',
            '}',
            '',
            '.mag-form__bar-track {',
            '  border-radius: 0;',
            '}',
            '',
            '@media (max-width: 560px) {',
            '  .mag-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
    {
        name: 'moonshine',
        prefix: 'ms-',
        target: 'Frontend/packages/moonshine/src/moonshine/form.css',
        palette: [
            '  --form-page-bg: var(--m-bg, var(--st-bg));',
            '  --form-surface: var(--m-surface);',
            '  --form-ink: var(--m-ink);',
            '  --form-hint: var(--m-muted);',
            '  --form-border: var(--m-border);',
            '  --form-accent: var(--m-primary);',
            '  --form-accent-bg: var(--m-primary);',
            '  --form-accent-hover: var(--m-primary-hover);',
            '  --form-error-bg: var(--m-error-bg);',
            '  --form-error-ink: var(--m-error-ink);',
            '  --form-accent-ink: #ffffff;',
            '  --form-radius: 8px;',
        ],
        tail: [
            '/* moonshine override — MoonShine radii and its own muted token scale. */',
            '.ms-form__submit {',
            '  border-radius: 8px;',
            '}',
            '',
            '.ms-form__question {',
            '  background: var(--m-bg, var(--st-bg));',
            '}',
            '',
            '@media (max-width: 560px) {',
            '  .ms-form {',
            '    margin: 24px 0;',
            '    padding: 16px;',
            '  }',
            '}',
        ],
    },
];

generate({
    asset: 'generate-form-css',
    script: SCRIPT,
    source: SOURCE,
    firstRule: '.st-form {',
    splitAt: SMALL_SCREENS,
    themes: THEMES,
    check: process.argv.includes('--check'),
    root: resolve(dirname(fileURLToPath(import.meta.url)), '..', '..'),
});
