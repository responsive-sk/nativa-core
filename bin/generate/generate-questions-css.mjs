#!/usr/bin/env node
/**
 * generate-questions-css.mjs — one questions block, one copy per theme.
 *
 * `QuestionBlockRenderer` (the Questions module) builds every class from the
 * ACTIVE THEME'S PREFIX, so `questions.css` has to exist under each prefix.
 * The rules come from the starter; only the palette is per theme. The shared
 * half lives in `theme-css.mjs`, which `generate-form-css.mjs` uses too.
 *
 * Usage:
 *
 *   node bin/generate/generate-questions-css.mjs           # write every copy
 *   node bin/generate/generate-questions-css.mjs --check   # exit 1 on drift
 *
 * `--check` is what a gate would run. It is not wired into `make gates` today:
 * a make target is registry-owned (`toolchain.json`), and this script earns one
 * by being stable first, not by being written.
 *
 * Boson and Mono have no copy: their questions rules are inlined in their
 * critical stylesheet, which is the file their `criticalCss()` serves when
 * there is no build.
 */
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { generate } from './theme-css.mjs';

const SCRIPT = 'bin/generate/generate-questions-css.mjs';
const SOURCE = 'Frontend/packages/starter/src/questions.css';

const THEMES = [
    {
        name: 'basic',
        prefix: 'ba-',
        target: 'Frontend/packages/basic/src/questions.css',
        palette: [
            '  --questions-ink: var(--ba-ink);',
            '  --questions-muted: var(--ba-muted);',
            '  --questions-border: var(--ba-border);',
            '  --questions-surface: var(--ba-surface);',
            '  --questions-accent: var(--ba-accent);',
            '  --questions-accent-bg: var(--ba-accent-strong);',
            '  --questions-accent-hover: var(--ba-accent-hover);',
            '  --questions-error-bg: rgba(239, 68, 68, 0.12);',
            '  --questions-error-ink: var(--ba-error-ink);',
            '  --questions-sent-bg: var(--ba-tag-bg);',
            '  --questions-accent-ink: #ffffff;',
            '  --questions-radius: 6px;',
            '  --questions-width: 720px;',
            '  --questions-form-width: 520px;',
        ],
    },
    {
        name: 'erik',
        prefix: 'er-',
        target: 'Frontend/packages/erik/src/erik/questions.css',
        palette: [
            '  --questions-ink: var(--er-ink);',
            '  --questions-muted: var(--er-muted);',
            '  --questions-border: var(--er-border);',
            '  --questions-surface: var(--er-surface);',
            '  --questions-accent: var(--er-accent-soft);',
            '  --questions-accent-bg: var(--er-accent-strong);',
            '  --questions-accent-hover: var(--er-accent-hover);',
            '  --questions-error-bg: var(--er-error-bg);',
            '  --questions-error-ink: var(--er-error-ink);',
            '  --questions-sent-bg: var(--er-surface-raised);',
            '  --questions-accent-ink: var(--er-accent-ink);',
            '  --questions-radius: 8px;',
            '  --questions-width: 720px;',
            '  --questions-form-width: 520px;',
        ],
    },
    {
        name: 'griffe',
        prefix: 'gr-',
        target: 'Frontend/packages/griffe/src/griffe/questions.css',
        palette: [
            '  --questions-ink: var(--gr-ink);',
            '  --questions-muted: var(--gr-muted);',
            '  --questions-border: var(--gr-line);',
            '  --questions-surface: var(--gr-paper);',
            '  --questions-accent: var(--gr-accent);',
            '  --questions-accent-bg: var(--gr-ink);',
            '  --questions-accent-hover: var(--gr-accent);',
            '  --questions-error-bg: rgba(239, 68, 68, 0.12);',
            '  --questions-error-ink: var(--gr-ink);',
            '  --questions-sent-bg: var(--gr-line);',
            '  --questions-accent-ink: var(--gr-paper);',
            '  --questions-radius: var(--gr-radius);',
            '  --questions-width: 720px;',
            '  --questions-form-width: 520px;',
        ],
    },
    {
        name: 'jednotka',
        prefix: 'je-',
        target: 'Frontend/packages/jednotka/src/questions.css',
        palette: [
            '  --questions-ink: var(--je-ink);',
            '  --questions-muted: var(--je-muted);',
            '  --questions-border: var(--je-border);',
            '  --questions-surface: var(--je-surface);',
            '  --questions-accent: var(--je-accent);',
            '  --questions-accent-bg: var(--je-accent-strong);',
            '  --questions-accent-hover: var(--je-accent-hover);',
            '  --questions-error-bg: rgba(239, 68, 68, 0.12);',
            '  --questions-error-ink: var(--je-error-ink);',
            '  --questions-sent-bg: var(--je-tag-bg);',
            '  --questions-accent-ink: #ffffff;',
            '  --questions-radius: 2px;',
            '  --questions-width: 720px;',
            '  --questions-form-width: 520px;',
        ],
    },
    {
        name: 'magazine',
        prefix: 'mag-',
        target: 'Frontend/packages/magazine/src/magazine/questions.css',
        palette: [
            // The neutrals are the shared starter base this package ships in
            // `src/base/tokens.css`; only the accent family is magazine's own.
            '  --questions-ink: var(--st-ink);',
            '  --questions-muted: var(--st-muted);',
            '  --questions-border: var(--st-border);',
            '  --questions-surface: var(--st-surface);',
            '  --questions-accent: var(--mag-accent-soft);',
            '  --questions-accent-bg: var(--mag-accent-strong);',
            '  --questions-accent-hover: var(--mag-accent-hover);',
            '  --questions-error-bg: rgba(239, 68, 68, 0.12);',
            '  --questions-error-ink: var(--st-error-ink);',
            '  --questions-sent-bg: var(--mag-tag-bg);',
            '  --questions-accent-ink: #ffffff;',
            '  --questions-radius: 4px;',
            '  /* Full width, not 720px: magazine runs a sidebar layout and this block',
            '     spans the content column. */',
            '  --questions-width: none;',
            '  --questions-form-width: 520px;',
        ],
    },
    {
        name: 'moonshine',
        prefix: 'ms-',
        target: 'Frontend/packages/moonshine/src/moonshine/questions.css',
        palette: [
            '  --questions-ink: var(--m-ink);',
            '  --questions-muted: var(--m-muted);',
            '  --questions-border: var(--m-border);',
            '  --questions-surface: var(--m-surface);',
            '  --questions-accent: var(--m-primary);',
            '  --questions-accent-bg: var(--m-primary);',
            '  --questions-accent-hover: var(--m-primary-hover);',
            '  --questions-error-bg: var(--m-error-bg);',
            '  --questions-error-ink: var(--m-error-ink);',
            '  --questions-sent-bg: var(--m-success-bg);',
            '  --questions-accent-ink: #ffffff;',
            '  --questions-radius: 8px;',
            '  --questions-width: 720px;',
            '  --questions-form-width: 520px;',
        ],
    },
];

generate({
    asset: 'generate-questions-css',
    script: SCRIPT,
    source: SOURCE,
    firstRule: '.st-questions {',
    themes: THEMES,
    check: process.argv.includes('--check'),
    root: resolve(dirname(fileURLToPath(import.meta.url)), '..', '..'),
});
