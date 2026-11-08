/**
 * container-query.ts — ContainerQueryManager-style fallback (task 10.10).
 *
 * CSS Container Queries (@container) are supported since Chrome 105. On the
 * Chrome 70 / Android 9 baseline, @container queries are ignored, which
 * collapses responsive layouts.
 *
 * This module watches elements with [data-cq] via ResizeObserver and adds
 * CSS classes (cq--w-{bp}, cq--lt-{bp}) based on the element's width. CSS
 * fallback selectors in `@supports not (container-type: inline-size)` blocks
 * use these classes to replicate @container behaviour.
 *
 * Usage:
 *   <div class="st-features" data-cq data-cq-bp="360,560,760">
 *
 * CSS:
 *   @supports not (container-type: inline-size) {
 *     .st-features.cq--w-560 { grid-template-columns: repeat(3, 1fr); }
 *   }
 *
 * ES2015-safe: no `?.`, `??` — this source runs on Chrome 70 even without
 * transpilation (the build still transpiles to target 'es2015').
 */

interface BreakpointEntry {
  /** The breakpoint value in pixels */
  value: number;
  /** Whether this is a max-width breakpoint (cq--lt-{bp}) */
  isMax: boolean;
}

/** Whether the browser supports native CSS Container Queries */
function supportsNativeContainerQueries(): boolean {
  if (typeof CSS === 'undefined' || typeof CSS.supports !== 'function') {
    return false;
  }

  try {
    return CSS.supports('container-type: inline-size');
  } catch {
    return false;
  }
}

class ContainerQueryManager {
  private observer: ResizeObserver | null = null;

  private elements = new WeakMap<Element, BreakpointEntry[]>();

  /**
   * Initialize the fallback. No-op when native @container is supported.
   */
  init(): void {
    if (supportsNativeContainerQueries()) {
      return;
    }

    if (typeof ResizeObserver === 'undefined') {
      return;
    }

    this.observer = new ResizeObserver((entries: ResizeObserverEntry[]): void => {
      for (let i = 0; i < entries.length; i++) {
        this.handleResize(entries[i]);
      }
    });

    this.scanDom();
  }

  /**
   * Scan the DOM for [data-cq] elements and start observing them.
   */
  scanDom(): void {
    const elements = document.querySelectorAll('[data-cq]');

    for (let i = 0; i < elements.length; i++) {
      this.setupElement(elements[i]);
    }
  }

  /**
   * Set up a single element. Parses breakpoints from data-cq-bp and
   * data-cq-max attributes. Returns true if the element was observed.
   */
  setupElement(el: Element): boolean {
    if (this.observer === null) {
      return false;
    }
    if (this.elements.has(el)) {
      return false;
    }

    const breakpoints: BreakpointEntry[] = [];

    // Min-width breakpoints: data-cq-bp="360,560,760"
    const bpAttr = el.getAttribute('data-cq-bp');
    if (bpAttr !== null) {
      const vals = bpAttr.split(',');
      for (let i = 0; i < vals.length; i++) {
        const val = parseInt(vals[i].trim(), 10);
        if (!isNaN(val) && val > 0) {
          breakpoints.push({ value: val, isMax: false });
        }
      }
    }

    // Max-width breakpoints: data-cq-max="360"
    const maxAttr = el.getAttribute('data-cq-max');
    if (maxAttr !== null) {
      const vals = maxAttr.split(',');
      for (let i = 0; i < vals.length; i++) {
        const val = parseInt(vals[i].trim(), 10);
        if (!isNaN(val) && val > 0) {
          breakpoints.push({ value: val, isMax: true });
        }
      }
    }

    if (breakpoints.length === 0) {
      return false;
    }

    this.elements.set(el, breakpoints);
    this.applyClasses(el, breakpoints, el.clientWidth);
    this.observer.observe(el);

    return true;
  }

  /**
   * Handle a ResizeObserver entry for a single element.
   */
  handleResize(entry: ResizeObserverEntry): void {
    const el = entry.target;
    const breakpoints = this.elements.get(el);

    if (breakpoints === undefined || breakpoints.length === 0) {
      return;
    }

    // borderBoxSize[0].inlineSize is most accurate; fall back to contentRect.
    let width: number;
    if (entry.borderBoxSize.length > 0) {
      width = entry.borderBoxSize[0].inlineSize;
    } else {
      width = entry.contentRect.width;
    }

    this.applyClasses(el, breakpoints, width);
  }

  /**
   * Apply cq--w-{bp} (width >= bp) and cq--lt-{bp} (width < bp) classes.
   */
  applyClasses(el: Element, breakpoints: BreakpointEntry[], width: number): void {
    const classList = el.classList;

    for (let i = 0; i < breakpoints.length; i++) {
      const bp = breakpoints[i];

      if (bp.isMax) {
        const className = 'cq--lt-' + bp.value;
        if (width < bp.value) {
          if (!classList.contains(className)) classList.add(className);
        } else if (classList.contains(className)) {
          classList.remove(className);
        }
      } else {
        const className = 'cq--w-' + bp.value;
        if (width >= bp.value) {
          if (!classList.contains(className)) classList.add(className);
        } else if (classList.contains(className)) {
          classList.remove(className);
        }
      }
    }
  }

}

export const containerQueryManager = new ContainerQueryManager();
