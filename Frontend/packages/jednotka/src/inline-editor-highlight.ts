/**
 * inline-editor-highlight — Markdown source colouring behind the textarea.
 *
 * A `<textarea>` cannot contain markup, so the colours live in a backdrop layer
 * and the textarea sits on top of it with a transparent background and a
 * visible caret. Both layers hold the SAME text in the SAME font metrics, so
 * the glyphs sit on top of each other: what the author reads is the highlight,
 * what the form submits is untouched.
 *
 * **The failure mode is deliberately one-sided.** If the two layers ever drift
 * apart — a font loads late, the box is resized, an IME composition mis-measures
 * — the author sees wrong colours over correct text. Nothing is lost and nothing
 * is corrupted, because the textarea remains the only source of truth and the
 * backdrop is never read back. That is the whole reason this is a backdrop and
 * not a contenteditable: on a write path, a jumped caret is a corrupted article,
 * and this design cannot jump a caret.
 *
 * This highlights the MARKDOWN SOURCE — headings, emphasis, links, quotes,
 * fences, inline code — not the code inside a fence. Highlighting the inner
 * language too is a separate concern with its own tokenizer, and mixing the two
 * on one line makes both harder to read.
 *
 * Chrome 70 baseline: no `?.`, no `??`, no lookbehind. Every pattern below is
 * written to run on the engine this ships to.
 */

import { escapeHtml } from './inline-editor';

/**
 * `escapeHtml` is shared with the preview renderer rather than
 * re-implemented here: two copies of an escaper drift, and the copy that
 * drifts is the one nobody tests. It lives in `inline-editor` next to the
 * other source-text helpers (`renderInline`, `codeBlock`).
 *
 * Escaping matters twice over: the author's text goes into `innerHTML`, and
 * this layer sits in the same document as the preview — an unescaped
 * `<img onerror>` typed into the editor would execute here even though the
 * preview refuses to render it.
 */

/** Emitted as `<span class="tok-…">`, reusing the article's own token colours. */
type TokenKind =
  | 'heading' | 'emphasis' | 'strong' | 'link' | 'quote'
  | 'fence' | 'code' | 'list' | 'rule' | 'plain';

const span = (kind: TokenKind, value: string): string =>
  '<span class="tok-' + kind + '">' + escapeHtml(value) + '</span>';

/**
 * One line of source, coloured.
 *
 * Line-at-a-time rather than one pass over the whole document: the backdrop is
 * rebuilt on every keystroke, and per-line state is what lets a fence span many
 * lines while everything outside one is coloured as prose.
 */
function highlightLine(line: string, inFence: boolean): { html: string; fence: boolean } {
  // Inside a fence nothing is Markdown any more — it is somebody else's
  // language, and colouring its brackets as headings would be a lie.
  if (inFence) {
    return { html: span('code', line), fence: line.trim() !== '' };
  }

  const fence = /^\s*```/.test(line);

  if (fence) return { html: span('fence', line), fence: true };

  if (/^\s*(?:---|\*\*\*|___)\s*$/.test(line)) return { html: span('rule', line), fence: false };

  const heading = /^(\s*)(#{1,6})(\s+)(.*)$/.exec(line);

  if (heading !== null) {
    return {
      html: escapeHtml(heading[1]) + span('heading', heading[2] + heading[3] + heading[4]),
      fence: false,
    };
  }

  const quote = /^(\s*)(>\s?)(.*)$/.exec(line);

  if (quote !== null) {
    return { html: escapeHtml(quote[1]) + span('quote', quote[2]) + highlightProse(quote[3]), fence: false };
  }

  const bullet = /^(\s*)([-*+]|\d+\.)(\s+)(.*)$/.exec(line);

  if (bullet !== null) {
    return {
      html: escapeHtml(bullet[1]) + span('list', bullet[2] + bullet[3]) + highlightProse(bullet[4]),
      fence: false,
    };
  }

  return { html: highlightProse(line), fence: false };
}

/**
 * Prose: inline code first, so a `**` inside backticks is never emphasised,
 * then links, then emphasis.
 *
 * Ordered deliberately. Inline code is the only construct whose CONTENT is not
 * prose, so consuming it first is what stops every later pattern from seeing
 * the inside of a code span.
 */
function highlightProse(text: string): string {
  const pieces: string[] = [];
  const code = /`[^`\n]*`/g;
  let cursor = 0;
  let match = code.exec(text);

  while (match !== null) {
    if (match.index > cursor) pieces.push(highlightEmphasis(text.slice(cursor, match.index)));

    pieces.push(span('code', match[0]));
    cursor = match.index + match[0].length;
    match = code.exec(text);
  }

  if (cursor < text.length) pieces.push(highlightEmphasis(text.slice(cursor)));

  return pieces.join('');
}

/** Links and emphasis, in that order — a link label may contain emphasis. */
function highlightEmphasis(text: string): string {
  const link = /(\[[^\]\n]*\])(\([^)\n]*\))/g;

  return text.replace(link, (whole) => span('link', whole)).replace(
    /(\*\*|__)(?=\S)([\s\S]*?\S)\1/g,
    (whole) => span('strong', whole),
  ).replace(
    /(\*|_)(?=\S)([^*_\n]*?\S)\1/g,
    (whole) => span('emphasis', whole),
  );
}

/**
 * The whole source, coloured line by line.
 *
 * A trailing newline is preserved as a trailing `<br>`: a textarea shows a
 * final empty line the author can put the caret on, and a backdrop that does
 * not reserve that line makes the last row of text sit half a line too high.
 */
export function highlightMarkdown(source: string): string {
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  const out: string[] = [];
  let inFence = false;

  for (let i = 0; i < lines.length; i++) {
    const line = highlightLine(lines[i], inFence);

    inFence = line.fence;
    out.push(line.html);
  }

  return out.join('\n') + '\n';
}
