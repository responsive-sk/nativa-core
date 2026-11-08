/**
 * inline-editor-fullscreen — the full-viewport sheet for the inline editor.
 *
 * Split out of `inline-editor.ts` only to stay under the 600-line theme file
 * cap; the behaviour and the no-JavaScript contract are unchanged.
 *
 * **The `<details>` is restyled, never moved.** Turning it into a fixed,
 * full-viewport sheet means the form, the textarea and the CSRF field stay
 * exactly where they were in the DOM, so submission cannot be disturbed by the
 * chrome — and when this file never runs, the disclosure still opens inline and
 * the form still posts. That fallback is the reason the editor is a plain form
 * underneath, and it is not negotiable.
 *
 * A fixed sheet also beats a modal dialog here: no `<dialog>` (whose top-layer
 * behaviour and `::backdrop` lag the Chrome 70 baseline), no scroll-lock
 * library, and no second element to keep in sync with the one that submits.
 */

/**
 * Make the open disclosure cover the whole viewport.
 *
 * @param pristine the body as loaded, for the unsaved-changes guard
 */
export function wireFullscreen(
  details: HTMLElement,
  textarea: HTMLTextAreaElement,
  closeButton: HTMLButtonElement,
  pristine: string,
): void {
  const summary = details.querySelector('summary');

  const enter = (): void => {
    details.setAttribute('data-editor-fullscreen', 'true');
    document.body.setAttribute('data-editor-open', 'true');
    textarea.focus();
  };

  const unlock = (): void => {
    details.removeAttribute('data-editor-fullscreen');
    document.body.removeAttribute('data-editor-open');
  };

  const close = (): void => {
    if (textarea.value !== pristine && !window.confirm('Zmeny neboli uložené. Zavrieť bez uloženia?')) {
      return;
    }

    unlock();
    details.removeAttribute('open');

    if (summary !== null) summary.focus();
  };

  closeButton.addEventListener('click', close);

  details.addEventListener('toggle', () => {
    if (details.hasAttribute('open')) {
      enter();
    } else {
      unlock();
    }
  });

  // Sync with the state we are ALREADY in. The first `enhance()` runs from
  // inside the very `toggle` event that opened the disclosure, and a listener
  // added while that event is being dispatched is never called for it — so
  // without this the editor opened INLINE on first click and only became a
  // sheet from the second opening onwards.
  if (details.hasAttribute('open')) enter();

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (details.getAttribute('data-editor-fullscreen') !== 'true') return;

    event.preventDefault();
    close();
  });
}
