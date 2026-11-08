import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('boson-search-layout')
export class SearchLayout extends LitElement {
  static styles = [
    css`
      .search-layout {
      }

      .search-content {
        width: var(--width-content);
        max-width: var(--width-max);
        margin: 0 auto;
        padding-bottom: 3em;
      }

      ::slotted(section) {
        margin: 2em 0;
      }
    `,
  ];

  render() {
    return html`
      <main class="search-layout">
        <slot></slot>

        <section class="search-content">
          <slot name="content"></slot>
        </section>
      </main>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-search-layout': SearchLayout;
  }
}
