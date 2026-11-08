/**
 * inline-editor-scroll — keep the live preview under the author's cursor.
 *
 * The textarea is 5,465px of Markdown; the preview it renders is 4,045px. So
 * the two panes CANNOT be matched by copying a scroll percentage across: they
 * would agree near the top and disagree more and more, and by the bottom of a
 * long article the preview would be showing a different paragraph than the one
 * being written. Instead every rendered block carries the source line it came
 * from (`data-editor-line`, written by `renderMarkdown`), and this module maps
 * the author's current line onto the block that line produced.
 *
 * **One direction, and that is what stops the classic loop.** The preview's
 * `scrollTop` is only ever written from the textarea's handler; nothing here
 * listens to the preview's `scroll` at all. There is no return path, so there
 * is no feedback to damp and no timer to get wrong. The one thing that could
 * reintroduce a loop — reading the preview and writing the textarea back — is
 * simply not written.
 *
 * Chrome 70 baseline: `requestAnimationFrame` and passive listeners are well
 * inside it. Deliberately NOT used: `scrollend` (Chrome 114), `ResizeObserver`
 * to track re-renders, and any percentage shortcut.
 */

/** Matches the attribute `renderMarkdown` stamps on every top-level block. */
const LINE_ATTR = 'data-editor-line';

/**
 * The source line at the top of the textarea's viewport.
 *
 * **`scrollTop / lineHeight` is wrong here, and measurably so.** A textarea
 * lays its text out in VISUAL rows, and a source line longer than the box wraps
 * into two or three of them. The article this editor is for has 188 source
 * lines occupying 244 visual rows, so dividing by the line height over-counts
 * by a line per wrapped paragraph: measured against a mirror of the same text,
 * the naive division was already 7 rows ahead at 10% of the way down, 23 at the
 * halfway mark and 44 at 90% — the preview drifting further off exactly where
 * an author scrolling to the end of a long article most needs it to be right.
 *
 * So the row is converted to a line for real. A hidden mirror `div` copies the
 * textarea's text, its box and its typography, and each source line's position
 * is read from a `Range` over it. The map is built once per render and reused,
 * because measuring forces layout and this runs on every animation frame.
 *
 * Returns -1 when no mirror could be built (a zero-width textarea, or a browser
 * that refuses the measurement), which the caller treats as "no line known"
 * rather than as line 0.
 */
function lineAtTop(measure: RowMap | null, textarea: HTMLTextAreaElement): number {
  if (measure === null) return -1;

  const lineHeight = parseFloat(window.getComputedStyle(textarea).lineHeight);

  if (!isFinite(lineHeight) || lineHeight <= 0) return -1;

  // Both sides are offsets INTO THE TEXT: `tops` is each line's offset from the
  // mirror's own top, and `scrollTop` is the same distance into the textarea's
  // content. Adding the mirror's page position to one of them compares an
  // absolute against a relative and matches the first block every time — which
  // looks like a preview that refuses to scroll rather than like a broken map.
  const target = textarea.scrollTop;
  let found = -1;

  for (let i = 0; i < measure.tops.length; i++) {
    if (measure.tops[i] <= target) {
      found = measure.lines[i];
    } else {
      break;
    }
  }

  return found;
}

/**
 * Where each source line actually sits, measured rather than assumed.
 */
interface RowMap {
  /** Source line number per entry, ascending — never more entries than lines. */
  lines: number[];
  /** The same entries' offsetTop in the mirror, ascending. */
  tops: number[];
}

/**
 * Typography and box properties that must match for the mirror to wrap exactly
 * where the textarea wraps. Anything left at the default changes the row count
 * and silently reintroduces the drift this exists to remove.
 */
const MIRRORED_PROPERTIES = [
  'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
  'word-spacing', 'line-height', 'text-indent', 'text-transform', 'tab-size',
  'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
  'box-sizing', 'white-space', 'word-wrap', 'overflow-wrap',
];

/**
 * Measure where every source line lands in the textarea's own text layout.
 *
 * One `<br>` per newline and one text node per line, so a `Range` over the
 * first character of a line yields that line's first client rect — including
 * where a long line wrapped, where only the rect's top matters.
 */
function measureRows(textarea: HTMLTextAreaElement, source: string): RowMap | null {
  if (textarea.clientWidth === 0) return null;

  const mirror = document.createElement('div');
  const computed = window.getComputedStyle(textarea);

  for (let i = 0; i < MIRRORED_PROPERTIES.length; i++) {
    const name = MIRRORED_PROPERTIES[i];
    mirror.style.setProperty(name, computed.getPropertyValue(name));
  }

  // `position: fixed` and off-screen, never `absolute` on the body: an
  // absolutely-positioned box at the top of the document participates in the
  // scrollable overflow region, so appending it RESETS the textarea's scroll
  // position to 0 — which looked like the sync silently undoing the author's
  // scroll rather than like a measuring helper disturbing the page.
  mirror.style.position = 'fixed';
  mirror.style.visibility = 'hidden';
  mirror.style.left = '-9999px';
  mirror.style.top = '0';
  mirror.style.height = 'auto';
  mirror.style.overflow = 'hidden';
  mirror.style.pointerEvents = 'none';
  // The textarea's CONTENT width: clientWidth less its padding and borders.
  mirror.style.width = textarea.clientWidth + 'px';
  mirror.setAttribute('aria-hidden', 'true');

  const lines = source.replace(/\r\n/g, '\n').split('\n');
  const nodes: Text[] = [];

  for (let i = 0; i < lines.length; i++) {
    if (i > 0) mirror.appendChild(document.createElement('br'));

    const node = document.createTextNode(lines[i]);
    mirror.appendChild(node);
    nodes.push(node);
  }

  document.body.appendChild(mirror);

  const range = document.createRange();
  const map: RowMap = { lines: [], tops: [] };
  // Every entry is an offset INTO THE TEXT, not a page coordinate: the caller
  // compares these against the textarea's own `scrollTop`.
  const first = mirror.getBoundingClientRect().top;

  for (let i = 0; i < lines.length; i++) {
    if (lines[i].length === 0) continue;

    range.setStart(nodes[i], 0);
    range.setEnd(nodes[i], 1);

    const rects = range.getClientRects();

    if (rects.length === 0) continue;

    map.lines.push(i);
    map.tops.push(rects[0].top - first);
  }

  document.body.removeChild(mirror);

  return map.lines.length === 0 ? null : map;
}

/**
 * The last block that starts at or above `line`, or null when the pane carries
 * no tagged blocks at all.
 *
 * Binary search, not a linear scan: a long article renders hundreds of blocks
 * and this runs on every animation frame of a scroll gesture. The blocks are
 * in document order, which is source order, so their line numbers ascend.
 */
function blockFor(pane: HTMLElement, line: number): HTMLElement | null {
  const blocks = pane.querySelectorAll('[' + LINE_ATTR + ']');

  if (blocks.length === 0) return null;

  let low = 0;
  let high = blocks.length - 1;
  let found: HTMLElement | null = null;

  while (low <= high) {
    const mid = (low + high) >> 1;
    const block = blocks[mid] as HTMLElement;
    const start = parseInt(block.getAttribute(LINE_ATTR) as string, 10);

    if (isNaN(start) || start > line) {
      high = mid - 1;
    } else {
      found = block;
      low = mid + 1;
    }
  }

  return found;
}

/**
 * The preview position matching the author's position, clamped to what the
 * pane can actually scroll to.
 *
 * `block.offsetTop` is relative to the offset parent, which inside the pane is
 * the pane itself, so the two subtract directly. Clamping matters: a block near
 * the end of a long article may be the last thing in the pane, and asking for
 * its exact top would leave the preview showing blank space below the article.
 */
function previewScrollFor(pane: HTMLElement, target: number): number {
  const max = pane.scrollHeight - pane.clientHeight;

  if (max <= 0) return 0;
  if (target < 0) return 0;
  if (target > max) return max;

  return target;
}

/**
 * Keep the preview aligned with the textarea.
 *
 * Engages only inside the fullscreen sheet: the preview gets its own scrollbar
 * only there (`max-height: 78vh; overflow: auto` lives under
 * `[data-editor-fullscreen]`), and inline it grows to fit its content and does
 * not scroll — so there is nothing to synchronise and the handler does nothing.
 * That is the same script-only rule the sheet itself follows, and the reason
 * the no-JS check can assert the fallback.
 *
 * `onRendered` is invoked after every preview re-render. Re-rendering replaces
 * the pane's contents, which resets its `scrollTop` to 0 — without re-applying
 * the position, the preview would jump to the top of the article on every
 * keystroke that crossed the debounce, while the author kept typing in place.
 * It is given as an argument rather than returned, because the caller has to
 * call it from inside its own debounced `render()` and there is no other moment
 * at which the pane's contents have just changed.
 */
export function attachScrollSync(
  textarea: HTMLTextAreaElement,
  pane: HTMLElement,
  details: HTMLElement,
  onRendered: (afterRender: () => void) => void,
): void {
  let frame = 0;
  let measure: RowMap | null = null;

  const sync = (): void => {
    frame = 0;

    if (details.getAttribute('data-editor-fullscreen') !== 'true') return;

    // Nothing to scroll: a short preview has no scrollbar, and writing its
    // scrollTop would be a no-op that still costs a forced layout.
    if (pane.scrollHeight <= pane.clientHeight) return;

    const line = lineAtTop(measure, textarea);
    const block = line < 0 ? null : blockFor(pane, line);

    // Either the pane carries no tagged blocks (rendered by an older bundle)
    // or the row map could not be built — a hidden textarea, a browser that
    // refused the measurement. Fall back to the proportional mapping, which
    // drifts, so the preview still tracks the author instead of freezing.
    const target = block === null
      ? ratioFor(textarea, pane)
      : previewScrollFor(pane, block.offsetTop - pane.offsetTop);

    pane.scrollTop = target;
  };

  // One write per frame however fast the gesture. A scroll event fires far more
  // often than the display refreshes, and each one otherwise forces a layout
  // read on a pane that may have just re-rendered.
  const schedule = (): void => {
    if (frame !== 0) return;

    frame = window.requestAnimationFrame(sync);
  };

  textarea.addEventListener('scroll', schedule, { passive: true });

  // Re-measure after every render, then re-apply. The text has changed, so the
  // row map from the previous render no longer describes the textarea — and
  // reusing it would put the preview at the line the OLD text had there.
  // Measuring forces layout, which is why it happens here, once per render,
  // rather than inside `sync` on every frame of a scroll gesture.
  onRendered(() => {
    measure = measureRows(textarea, textarea.value);
    window.requestAnimationFrame(sync);
  });
}

/**
 * Proportional fallback for a pane with no source-line tags.
 *
 * Known to drift — it is exactly the 0.74 ratio the line mapping exists to
 * avoid — and kept only so a preview from an older bundle degrades to "roughly
 * right" instead of "stuck at the top".
 */
function ratioFor(textarea: HTMLTextAreaElement, pane: HTMLElement): number {
  const from = textarea.scrollHeight - textarea.clientHeight;

  if (from <= 0) return 0;

  const ratio = textarea.scrollTop / from;

  return previewScrollFor(pane, ratio * (pane.scrollHeight - pane.clientHeight));
}
