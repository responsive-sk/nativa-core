import { css, html, LitElement, type TemplateResult } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '../utils/sharedStyles.js';

interface Heading {
  id: number;
  level: number;
  title: string;
  href: string;
  node: Element;
}

@customElement('boson-docs-layout')
export class DocsLayout extends LitElement {
  static styles = [
    sharedStyles,
    css`
      .docs-layout {
        display: grid;
        grid-template-columns: 1fr 4fr 1fr;
        margin: 0 auto;
        width: var(--width-content);
        max-width: var(--width-max);
      }

      .menu {
        margin: 0;
        width: 300px;
        max-width: 300px;
        min-width: 300px;
        border-right: solid 1px var(--color-border);
      }

      .menu-content {
        flex: 1;
        width: 100%;
        top: 70px;
        display: flex;
        flex-direction: column;
        position: sticky;
        max-height: calc(100vh - 100px);
      }

      .menu-pages,
      .menu-categories {
        width: 100%;
        padding: 2em 0;
        display: flex;
        flex-direction: column;
        gap: 0.5em;
        position: relative;
      }

      ::slotted(strong),
      ::slotted(a) {
        padding: 0.3em 0.5em;
      }

      [name='menu']::slotted(strong) {
        background: var(--color-bg-button);
        color: var(--color-text);
        font-weight: unset;
      }

      .menu-pages {
        margin-top: -1px;
        border-top: solid 1px var(--color-border);
        background: var(--color-bg-layer);
      }

      .menu-pages::before {
        content: '';
        width: 100vw;
        height: 100%;
        user-select: none;
        position: absolute;
        background: var(--color-bg-layer);
        border-top: solid 1px var(--color-border);
        border-bottom: solid 1px var(--color-border);
        bottom: -1px;
        right: 300px;
      }

      .menu-categories {
        position: relative;
        border-top: solid 1px var(--color-border);
        font-size: var(--font-size-secondary);
        overflow-y: auto;
        overflow-x: visible;
      }

      .content {
        padding: 2em;
        overflow: auto;
      }

      [name='category']::slotted(strong) {
        color: var(--color-text-brand);
      }

      /** RIGHT COLUMN */

      .navigation {
        padding-top: 1em;
        width: 200px;
        max-width: 200px;
        min-width: 200px;
        font-size: var(--font-size-secondary);
      }

      .navigation-content {
        max-height: calc(100vh - 100px);
        overflow: auto;
        width: 100%;
        max-width: 100%;
        position: sticky;
        display: flex;
        flex-direction: column;
        top: 100px;
        padding: 2em 0 2em 16px;
        border-left: solid 1px var(--color-border);
      }

      .navigation-content a {
        line-height: 1.2;
        position: relative;
        display: inline-block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
      }

      .navigation-content a::before {
        content: '';
        width: 2px;
        height: 100%;
        transform-origin: 0 100%;
        transform: scaleY(0);
        top: 0;
        left: -17px;
        position: absolute;
      }

      .navigation-content a.active::before {
        width: 2px;
        height: 100%;
        background: var(--color-text-brand);
        transform-origin: 0 0;
        transform: scaleY(1);
      }

      .navigation-item-2 {
        padding: 0.5em 0 0.3em 0;
      }

      .navigation-item-3 {
        font-size: 90%;
        padding: 0.3em 0 0.2em 0.8em;
        color: var(--color-text-secondary);
      }

      @media (max-width: 1200px) {
        .docs-layout {
          grid-template-columns: 1fr 4fr;
        }

        .navigation {
          display: none;
        }
      }

      @media (max-width: 900px) {
        .menu-pages::before,
        .menu-categories {
          display: none;
        }

        .docs-layout {
          display: flex;
          flex-direction: column;
          margin: 0;
          width: 100%;
        }

        .menu {
          width: 100%;
          min-width: 100%;
          position: relative;
          border: none;
        }

        .menu-pages {
          border-bottom: solid 1px var(--color-border);
        }
      }
    `,
  ];

  constructor() {
    super();
    this.onScroll = this.onScroll.bind(this);
  }

  get headings(): Heading[] {
    const content = this.querySelectorAll('h2, h3');
    let id = 0;

    return Array.from(content).map((heading) => {
      return {
        id: id++,
        level: (heading.tagName.slice(1) as any) | 0,
        title: heading.textContent?.slice(1) || '',
        href: (heading.childNodes[0] as Element | undefined)?.getAttribute?.('href') ?? '#',
        node: heading,
      };
    });
  }

  renderNavigationItem(data: Heading): TemplateResult {
    return html`
      <a
        href="${data.href}"
        data-navigation-item="${data.id}"
        class="navigation-item-${data.level}"
        title="${data.title}"
        >${data.title}</a
      >
    `;
  }

  connectedCallback(): void {
    super.connectedCallback();
    window.addEventListener('scroll', this.onScroll);
    setTimeout(() => this.onScroll(), 100);
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    window.removeEventListener('scroll', this.onScroll);
  }

  onScroll(): void {
    let breaks = false;
    let nav: Element | null = null;

    for (let data of this.headings.reverse()) {
      let rect = data.node.getBoundingClientRect();
      nav = this.shadowRoot?.querySelector(`[data-navigation-item="${data.id}"]`) || null;

      if (breaks === false && rect.top - 120 < 0) {
        nav?.classList.add('active');
        breaks = true;
      } else {
        nav?.classList.remove('active');
      }
    }

    if (breaks === false) {
      nav?.classList.add('active');
    }
  }

  render() {
    return html`
      <main class="docs-layout">
        <aside class="menu">
          <div class="menu-content">
            <nav class="menu-pages">
              <slot name="menu"></slot>
            </nav>

            <nav class="menu-categories">
              <slot name="category"></slot>
            </nav>
          </div>
        </aside>

        <section class="content" data-id="content">
          <slot></slot>
        </section>

        <aside
          class="navigation"
          style="${this.headings.length === 0 ? 'display:none' : ''}"
        >
          <div class="navigation-content">
            ${this.headings.map((heading) => this.renderNavigationItem(heading))}
          </div>
        </aside>
      </main>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-docs-layout': DocsLayout;
  }
}
