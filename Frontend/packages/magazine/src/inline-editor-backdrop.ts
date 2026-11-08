/**
 * inline-editor-backdrop — the layer the highlight is painted on.
 *
 * A `<textarea>` cannot hold markup, so the editor stacks two elements: this
 * backdrop carries the coloured source, and the textarea sits on top with a
 * transparent background and a visible caret. They must agree on every metric
 * that decides where a glyph lands — font, size, line height, padding, border,
 * wrapping — or the colours drift out from under the author's text.
 *
 * **Why a layer and not a contenteditable.** Everything here is decoration over
 * a field the form already submits. The textarea keeps the caret, the IME
 * composition, the undo stack, the selection and the accessibility semantics
 * that the browser gives it for free; this file contributes colour and nothing
 * else. A highlight that desynchronises shows wrong colours — an author can see
 * that and fix it. A contenteditable whose caret jumps during Slovak IME
 * composition silently corrupts the article.
 *
 * Chrome 70 baseline: no `ResizeObserver` to react to a reflow, no
 * `IntersectionObserver`, no `?.`.
 */

/** Style properties that decide where a glyph lands, and must match exactly. */
const METRIC_PROPERTIES = [
  'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
  'letter-spacing', 'word-spacing', 'line-height', 'text-indent', 'text-transform',
  'tab-size', 'white-space', 'word-wrap', 'overflow-wrap',
  'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
  'box-sizing', 'text-align',
];

/**
 * Wrap the textarea's row in a positioned backdrop and paint into it.
 *
 * Returns a `paint(text)` function rather than painting immediately: the caller
 * owns the debounce already used for the preview, and two layers re-rendering on
 * their own timers would drift against each other.
 */
export function mountBackdrop(textarea: HTMLTextAreaElement): (text: string) => void {
  const backdrop = document.createElement('div');
  backdrop.className = textarea.className.replace(/__textarea\b/, '') + '__backdrop';
  backdrop.setAttribute('aria-hidden', 'true');

  // Hidden from assistive technology: the textarea already exposes the text,
  // and announcing a second copy of the article would read every paragraph
  // twice.
  backdrop.setAttribute('role', 'presentation');

  const parent = textarea.parentNode;

  if (parent === null) return () => {};

  // The wrapper carries `position: relative`, so the textarea and the backdrop
  // are positioned against the SAME box — the only way their glyphs can line up.
  //
  // The wrapper is a NEW element rather than the caller's existing row: that row
  // is already the textarea's parent, and `insertBefore(wrapper, textarea)`
  // against a node that is not one of its children throws NotFoundError — the
  // same class of mistake as the `details.insertBefore(bar, split)` bug this
  // project already found once. The textarea is lifted out, the wrapper takes
  // its place, and the row keeps its own grid duties.
  const wrapper = document.createElement('div');
  // The theme prefix comes off the textarea's own class (`st-inline-edit__textarea`
  // -> `st`), so the backdrop is styled by the same per-theme CSS block as
  // everything else in the editor rather than by a class nothing can target.
  wrapper.className = textarea.className.replace(/__textarea\b/, '') + '__field';
  wrapper.setAttribute('data-editor-field', '');
  parent.insertBefore(wrapper, textarea);
  wrapper.appendChild(textarea);
  wrapper.appendChild(backdrop);

  const paint = (text: string): void => {
    backdrop.innerHTML = text;
  };

  const copyMetrics = (): void => {
    const computed = window.getComputedStyle(textarea);

    for (let i = 0; i < METRIC_PROPERTIES.length; i++) {
      const name = METRIC_PROPERTIES[i];

      backdrop.style.setProperty(name, computed.getPropertyValue(name));
    }

    // Width: the two layers must wrap at the same column, and a couple of
    // pixels is enough to change which word falls onto the next line.
    //
    // `clientWidth` is the textarea's PADDING box (content + padding, borders
    // excluded). Its content width is that less the padding, and this backdrop
    // carries the SAME padding — so it is given that content width and
    // `content-box`, which makes its own padding box identical to the
    // textarea's. The textarea's own borders are deliberately NOT subtracted:
    // the wrapper draws those on the outside, so the backdrop sits inside them.
    const padding = parseFloat(computed.paddingLeft) + parseFloat(computed.paddingRight);

    backdrop.style.boxSizing = 'content-box';
    backdrop.style.width = Math.max(0, textarea.clientWidth - padding) + 'px';
  };

  // The field is still being laid out on the first call — it has just been
  // inserted, and reading clientWidth now returns the pre-layout width. Sizing
  // to a hard value captured at that moment leaves the backdrop at half the
  // field's real width, so every wrapped line disagrees with the textarea's.
  // Re-measure once the browser has finished.
  copyMetrics();

  // Height needs no arithmetic: the backdrop is absolutely positioned inside the
  // wrapper, which the textarea's own box sizes, so `100%` is that box exactly.
  backdrop.style.height = '100%';

  const refresh = (): void => {
    copyMetrics();
    backdrop.scrollTop = textarea.scrollTop;
    backdrop.scrollLeft = textarea.scrollLeft;
  };

  if (typeof window.requestAnimationFrame === 'function') {
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(refresh);
    });
  }

  // Follow the textarea: scrolling moves the text inside the field, and without
  // this the colours stay put while the author's own text slides under them.
  // Cheap enough to do on every scroll event — one style write, no layout read.
  textarea.addEventListener('scroll', () => {
    backdrop.scrollTop = textarea.scrollTop;
    backdrop.scrollLeft = textarea.scrollLeft;
  }, { passive: true });

  // The resize handle changes clientWidth, which changes where every glyph
  // wraps, so the backdrop is re-measured rather than stretched. The field ships
  // `resize: vertical`, so the height case never arises; the width case is the
  // window resize below.
  window.addEventListener('resize', refresh);

  return paint;
}
