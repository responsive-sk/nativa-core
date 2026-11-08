/**
 * inline-editor.ts — the author-facing Markdown editor on the article page.
 *
 * Progressive enhancement over the plain `<textarea name="body">` that
 * `Blog\...\BlogInlineEditor` renders. The textarea is the CANONICAL field and
 * stays in the DOM: it is what the form submits, what works without
 * JavaScript, what works on Chrome 70, and what the article's owner edits on a
 * phone. Everything here is an accessory to it — if this file never runs, or
 * runs and throws, the form is unchanged and still correct.
 *
 * Two things, deliberately, and not a WYSIWYG editor:
 *
 * - **A formatting toolbar** that inserts Markdown around the selection.
 *   This is the part of live editing that carries its value and none of the
 *   risk: `selectionStart`/`selectionEnd` on a textarea are exact and immune
 *   to every problem a contenteditable surface has.
 * - **A live preview**, rendered here in the browser.
 *
 * Why not a contenteditable live editor, given that is what the toolbar is
 * imitating? Because its hard part is caret mapping — the caret has to survive
 * the DOM being re-rendered underneath it — plus IME composition, which is
 * exactly where these editors break on Slovak and Czech diacritics. On a
 * WRITE PATH that edits files, "the caret jumped and the author did not
 * notice" is corruption. A textarea has none of that: the browser owns the
 * caret, and we only ever read and write plain strings.
 *
 * **The preview escapes everything.** `renderMarkdown()` below never
 * interprets input as HTML, matching `MarkdownRenderer`, which escapes every
 * raw tag the article page does not generate itself — that is why the inline
 * save route needs no sanitization pass. Two renderers can disagree about
 * which Markdown features they cover; they must not disagree about HTML, so
 * this one is escape-first by construction and `InlineEditorPreviewTest`
 * holds the invariant. The preview is an aid, never the source of truth: what
 * readers get is always rendered by the server.
 *
 * Theme-agnostic by opt-in, like `toc-scrollspy.ts`: the toolbar and preview
 * take their BEM block from the element's own class list, so the starter
 * (`st-inline-edit__*`), erik, magazine and moonshine runs of these same
 * bytes. A hardcoded `.st-` selector would have matched nothing in the other
 * three, which is how the erik TOC once sat dead.
 *
 * ES2015-safe: no `?.`, no `??`, no `Array.from` on NodeList without spread.
 * This source runs on Chrome 70 even before transpilation.
 */

import { wireFullscreen } from './inline-editor-fullscreen';
import { attachScrollSync } from './inline-editor-scroll';
import { renderMarkdown } from './inline-editor-preview';
import { highlightMarkdown } from './inline-editor-highlight';
import { mountBackdrop } from './inline-editor-backdrop';

interface FormatAction {
  /** BEM suffix, e.g. `bold` renders as `{prefix}-inline-edit__tool--bold`. */
  key: string;
  /** Visible label. Short by necessity — the toolbar has to fit a phone. */
  label: string;
  /** Accessible name; the label alone is a glyph, not a description. */
  title: string;
  /** Wraps the selection, or inserts at the caret when nothing is selected. */
  wrap?: [string, string];
  /** Inserts on its own line, at the caret. */
  line?: string;
  /** Placeholder text inserted when a wrap action has an empty selection. */
  placeholder?: string;
}

/**
 * The toolbar. `wrap` pairs are symmetric markers; `line` prefixes a block.
 * Kept to the subset an author actually reaches for mid-draft — everything
 * here is also typeable by hand, which is the point of a Markdown editor.
 */
const ACTIONS: FormatAction[] = [
  { key: 'h2', label: 'H2', title: 'Nadpis druhej úrovne', line: '## ' },
  { key: 'h3', label: 'H3', title: 'Nadpis tretej úrovne', line: '### ' },
  { key: 'bold', label: 'B', title: 'Tučné', wrap: ['**', '**'], placeholder: 'tučný text' },
  { key: 'italic', label: 'I', title: 'Kurzíva', wrap: ['*', '*'], placeholder: 'kurzíva' },
  { key: 'code', label: '</>', title: 'Inline kód', wrap: ['`', '`'], placeholder: 'kod' },
  { key: 'link', label: '🔗', title: 'Odkaz', wrap: ['[', '](/odkaz)'], placeholder: 'text odkazu' },
  { key: 'ul', label: '•', title: 'Nečíslovaný zoznam', line: '- ' },
  { key: 'ol', label: '1.', title: 'Číslovaný zoznam', line: '1. ' },
  { key: 'quote', label: '❝', title: 'Citát', line: '> ' },
];

/** Debounce for the preview render. Fast enough to feel live, slow enough not to thrash. */
const PREVIEW_DEBOUNCE_MS = 200;

/**
 * The BEM block of an element, e.g. `st-inline-edit st-inline-edit--open`
 * yields `st-inline-edit`.
 */
function blockOf(element: Element): string {
  const match = /(^|\s)([a-zA-Z0-9_-]+-inline-edit)(\s|$)/.exec(element.className || '');

  return match ? match[2] : '';
}

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className: string,
  text?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

/**
 * Replace the selection, or insert at the caret.
 *
 * Returns the caret position to restore afterwards. A textarea keeps its own
 * selection across `value` assignment in modern browsers, but restoring it
 * explicitly is what makes the behaviour identical on Chrome 70.
 */
function applyToSelection(
  textarea: HTMLTextAreaElement,
  replacement: string,
  caretOffset: number,
  selectLength: number,
): { start: number; end: number } {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const before = textarea.value.slice(0, start);
  const after = textarea.value.slice(end);
  const next = before + replacement + after;

  textarea.value = next;
  const caret = before.length + caretOffset;
  textarea.setSelectionRange(caret, caret + selectLength);

  return { start: caret, end: caret + selectLength };
}

/** A `wrap` action over the current selection, or its placeholder. */
function wrapSelection(textarea: HTMLTextAreaElement, action: FormatAction): void {
  const open = (action.wrap || ['', ''])[0];
  const close = (action.wrap || ['', ''])[1];
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const selected = textarea.value.slice(start, end);
  const inner = selected === '' ? (action.placeholder || '') : selected;

  // Already wrapped? Unwrap instead, so the button toggles like a toggle.
  const already = selected !== '' &&
    selected.slice(0, open.length) === open &&
    selected.slice(selected.length - close.length) === close;

  if (already) {
    applyToSelection(textarea, selected.slice(open.length, selected.length - close.length), 0, 0);
  } else {
    applyToSelection(textarea, open + inner + close, open.length, inner.length);
  }

  textarea.focus();
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

/** A `line` action at the caret, re-prefixing every line the selection spans. */
function prefixLines(textarea: HTMLTextAreaElement, prefix: string): void {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const lineStart = textarea.value.lastIndexOf('\n', start - 1) + 1;
  const lineEndIndex = textarea.value.indexOf('\n', end);
  const lineEnd = lineEndIndex === -1 ? textarea.value.length : lineEndIndex;
  const block = textarea.value.slice(lineStart, lineEnd);
  const alreadyPrefixed = block.split('\n').every((line) => line === '' || line.indexOf(prefix) === 0);
  const next = alreadyPrefixed
    ? block.split('\n').map((line) => (line.indexOf(prefix) === 0 ? line.slice(prefix.length) : line)).join('\n')
    : block.split('\n').map((line) => (line === '' ? line : prefix + line)).join('\n');

  // Splice the LINE range directly. Going through applyToSelection() here was
  // wrong: that helper replaces the *selection*, while `next` was computed for
  // the whole line — so with the caret mid-line it rewrote only the selected
  // part with the line-wide replacement and left the prefix in place. Toggling
  // a heading on from the start of the line worked, which is why it survived;
  // toggling it off with the caret anywhere else silently did nothing.
  const before = textarea.value.slice(0, lineStart);
  const after = textarea.value.slice(lineEnd);

  textarea.value = before + next + after;
  textarea.setSelectionRange(lineStart, lineStart + next.length);
  textarea.focus();
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Escape the five characters that can change meaning in HTML text or an
 * attribute value. Applied BEFORE any tag is emitted, which is why this
 * renderer can never be talked into producing live HTML from its input.
 */
function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Inline spans: code first, so `**` inside backticks is never emphasised. */
function renderInline(text: string): string {
  const codes: string[] = [];
  let out = text.replace(/`([^`]+)`/g, (_m, code: string) => {
    codes.push('<code>' + escapeHtml(code) + '</code>');
    return CODE_SENTINEL + (codes.length - 1) + CODE_SENTINEL;
  });

  out = escapeHtml(out);

  // Images are NOT rendered here. `MarkdownRenderer` does not handle
  // `![alt](url)` at all — it stays literal text on the article page — so a
  // preview that drew an image would show the author something the article
  // will not, which is the one thing a preview must never do.

  // Links carry the SAME allowlist the server applies: http(s), root-relative
  // or an anchor. Without it `[ a ](javascript:alert(1))` became a live
  // `<a href="javascript:...">` in the author's own page — an XSS surface the
  // article page does not have. Two renderers may disagree about which
  // Markdown they cover; they must not disagree about what is safe to emit.
  out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (match: string, label: string, url: string) =>
    isSafeUrl(url) ? '<a href="' + url + '">' + label + '</a>' : match);
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  out = out.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
  out = out.replace(/~~([^~]+)~~/g, '<del>$1</del>');

  return out.replace(
    new RegExp(CODE_SENTINEL + '(\\d+)' + CODE_SENTINEL, 'g'),
    (_m, index: string) => {
      const code = codes[Number(index)];
      return code === undefined ? '' : code;
    },
  );
}

/**
 * A fenced code block, escaped and tagged with its language.
 *
 * The language goes on the `<code>` element as a class rather than being
 * interpolated into an attribute: it comes from the author's own text, so it
 * is escaped like everything else. `MarkdownRenderer` and the published
 * highlighter read `language-*`, and matching that here is the point — the
 * preview should show the same highlighting the article will.
 */
function codeBlock(code: string, lang: string): string {
  const language = /^[A-Za-z0-9_+-]{1,20}$/.test(lang) ? ' class="language-' + lang + '"' : '';
  return '<pre><code' + language + '>' + escapeHtml(code) + '</code></pre>';
}

/**
 * Placeholder standing in for a code span while the rest of the line is
 * escaped and formatted.
 *
 * `String.fromCharCode(0)`, not a literal NUL byte and not a space-delimited
 * digit: the placeholder is spliced into text the author may have typed
 * anything into, so it has to be a character that cannot occur in an article.
 * Writing it as a raw byte would also make this file binary to every tool
 * that reads it, including the parity guard.
 */
const CODE_SENTINEL = String.fromCharCode(0);

/**
 * The URL allowlist `MarkdownRenderer` enforces on links: http(s),
 * root-relative, or an anchor. Everything else — `javascript:`, `data:`,
 * `vbscript:`, a bare scheme — stays plain text.
 *
 * Mirrors the rule in
 * `src/Modules/Core/Application/Services/MarkdownRenderer.php`; change one,
 * change both, or the preview starts promising safety the article does not
 * keep.
 */
function isSafeUrl(url: string): boolean {
  const value = url.trim().toLowerCase();

  if (/^https?:\/\//.test(value)) return true;
  if (value.charAt(0) === '/' || value.charAt(0) === '#') return true;

  // A scheme before any slash or colon is the whole attack surface.
  return !/^[a-z][a-z0-9+.-]*:/.test(value);
}

/**
 * The source line each rendered block came from, so the scroll sync can put the
 * paragraph the author is TYPING at the top of the preview — 5,465px of
 * Markdown renders to 4,045px, so a percentage copy drifts the deeper they go.
 * A loop counter, never the author's text, so there is nothing to escape.
 */
const LINE_ATTR = 'data-editor-line';

const lineTag = (line: number): string => ' ' + LINE_ATTR + '="' + line + '"';

/**
 * Stamp a block built outside the loop. `codeBlock` returns a `<pre>` in one
 * expression, so its tag is spliced on afterwards; anything that is not a
 * `<pre`, or already tagged, is returned untouched rather than corrupted.
 */
const withLine = (html: string, line: number): string => {
  if (html.indexOf('<pre') !== 0 || html.indexOf(LINE_ATTR) !== -1) return html;

  return html.replace('<pre', '<pre' + lineTag(line));
};

/** Build the toolbar for one block and attach it above the textarea. */
function buildToolbar(block: string, textarea: HTMLTextAreaElement): HTMLElement {
  const bar = el('div', block + '__toolbar');
  bar.setAttribute('role', 'toolbar');
  // Stable hooks. The class prefix differs per theme (`st-`, `er-`, `mag-`,
  // `ms-`), so a test cannot select the toolbar by name — and matching it by
  // substring is a trap: `[class*="__preview"]` also matches the preview
  // TOGGLE, which is how an earlier version of the browser check spent an
  // evening asserting on a button.
  bar.setAttribute('data-editor-toolbar', '');
  bar.setAttribute('aria-label', 'Formátovanie Markdownu');
  bar.setAttribute('aria-controls', textarea.id);

  for (const action of ACTIONS) {
    const button = el('button', block + '__tool ' + block + '__tool--' + action.key, action.label);
    button.type = 'button';
    button.title = action.title;
    button.setAttribute('aria-label', action.title);
    button.setAttribute('data-editor-tool', action.key);
    button.addEventListener('click', (event) => {
      event.preventDefault();
      if (action.wrap !== undefined) {
        wrapSelection(textarea, action);
      } else if (action.line !== undefined) {
        prefixLines(textarea, action.line);
      }
    });
    bar.appendChild(button);
  }

  return bar;
}

/**
 * Enhance one `<details id="inline-edit">`. Idempotent: the `data-enhanced`
 * flag means a second call (the toggle firing after a theme swap, a restored
 * bfcache page) cannot build a second toolbar over the first.
 */
function enhance(details: HTMLElement): void {
  if (details.getAttribute('data-enhanced') === 'true') return;

  const textarea = details.querySelector('textarea[name="body"]') as HTMLTextAreaElement | null;
  if (textarea === null) return;

  const block = blockOf(details);
  if (block === '') return;

  const toolbar = buildToolbar(block, textarea);

  const split = el('div', block + '__split');
  split.setAttribute('data-editor-split', '');
  const pane = el('div', block + '__preview');
  pane.setAttribute('aria-live', 'polite');
  pane.setAttribute('data-editor-pane', '');
  const previewToggle = el('button', block + '__preview-toggle', 'Náhľad');
  previewToggle.type = 'button';
  previewToggle.setAttribute('aria-pressed', 'true');
  previewToggle.setAttribute('data-editor-preview-toggle', '');

  // The textarea is the field; the toolbar sits directly above it and the
  // preview pane after it, so the author's caret is never moved by the
  // toolbar being built under them.
  const host = textarea.parentNode;
  if (host === null) return;
  host.insertBefore(split, textarea);
  // The preview toggle rides at the end of the toolbar rather than floating
  // over the pane: it is a control like the others, and a control that only
  // exists when the thing it controls is already visible is not a control.
  toolbar.appendChild(previewToggle);
  split.appendChild(toolbar);

  // The body as it was loaded, so closing can tell "the author changed
  // nothing" from "the author is about to lose an edit".
  const pristine = textarea.value;

  // The summary is the title the author already clicked, so the sheet carries
  // the same words rather than a second, differently-worded heading.
  const summaryEl = details.querySelector('summary');
  const title = summaryEl === null ? '' : (summaryEl.textContent || '').trim();

  const bar = el('div', block + '__bar');
  bar.setAttribute('data-editor-bar', '');
  bar.appendChild(el('span', block + '__bar-title', title));

  const closeButton = el('button', block + '__bar-close', 'Zavrieť');
  closeButton.type = 'button';
  closeButton.setAttribute('data-editor-close', '');
  bar.appendChild(closeButton);

  // Inserted before the FORM, not before `split`: `split` went into the
  // textarea's parent (the form), and asking a node to insert before one of
  // its own children is a NotFoundError. That exception killed the rest of
  // enhance() — no input listener, no preview, no sheet — and the only thing
  // that noticed was the `data-enhanced` assertion in the browser check.
  const form = details.querySelector('form');

  if (form === null) {
    details.appendChild(bar);
  } else {
    details.insertBefore(bar, form);
  }

  const row = el('div', block + '__row');
  row.appendChild(textarea);
  split.appendChild(row);

  // Appended AFTER the row: the split is a two-column grid whose first column
  // is the textarea, and reading order and column order are the same thing here
  // — appending the pane first put the preview on the left and the editor, the
  // thing the author came to write in, on the right.
  split.appendChild(pane);

  let timer = 0;
  const render = (): void => {
    pane.innerHTML = renderMarkdown(textarea.value);
  };
  // The preview keeps its place across a re-render: replacing the pane's
  // contents resets its scrollTop to 0, which would throw the reader back to
  // the top of the article on every keystroke that crossed the debounce, and
  // leaves the scroll map describing the PREVIOUS text.
  // The coloured source under the textarea. Mounted before the first paint so
  // the two layers exist from the same moment, and painted in `remeasure` so it
  // can never lag a keystroke behind the preview.
  const paintSource = mountBackdrop(textarea);

  let afterRender = (): void => {};
  const remeasure = (): void => {
    paintSource(highlightMarkdown(textarea.value));
    render();
    afterRender();
  };

  const schedule = (): void => {
    if (timer !== 0) window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      timer = 0;
      remeasure();
    }, PREVIEW_DEBOUNCE_MS);
  };

  textarea.addEventListener('input', schedule);

  wireFullscreen(details, textarea, closeButton, pristine);

  attachScrollSync(textarea, pane, details, (hook) => {
    afterRender = hook;
  });

  previewToggle.addEventListener('click', (event) => {
    event.preventDefault();
    const showing = split.getAttribute('data-preview') !== 'hidden';
    split.setAttribute('data-preview', showing ? 'hidden' : 'shown');
    previewToggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    if (!showing) remeasure();
    else afterRender();
  });

  // Render once immediately so the pane is never briefly empty, then keep it
  // in step. A disclosure that is closed still renders — it costs one pass
  // over a few kilobytes and means the author sees the result on opening.
  remeasure();

  // Flagged LAST, once the DOM work has actually happened. Claiming the
  // editor before building it would make a failed enhancement permanent:
  // the idempotency guard would refuse to try again for the rest of the page.
  details.setAttribute('data-enhanced', 'true');
}

/**
 * Wire every inline editor on the page.
 *
 * The enhancement is built on first open rather than on load: the editor is
 * inside a collapsed `<details>`, and only the owner ever opens it, so a
 * reader's article page pays nothing for a control they will not see.
 */
export function initInlineEditor(): void {
  const attach = (): void => {
    const details = document.querySelectorAll('[id="inline-edit"]');
    for (let i = 0; i < details.length; i++) {
      const node = details[i] as HTMLElement;

      if (node.getAttribute('data-enhanced') === 'true') continue;

      if (node.hasAttribute('open')) {
        enhance(node);
        continue;
      }

      node.addEventListener('toggle', () => {
        if (node.hasAttribute('open')) enhance(node);
      });
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attach);
  } else {
    attach();
  }
}

export {
  CODE_SENTINEL,
  codeBlock,
  escapeHtml,
  lineTag,
  renderInline,
  withLine,
};
