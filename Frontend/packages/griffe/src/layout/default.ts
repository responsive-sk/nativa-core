import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('boson-default-layout')
export class DefaultLayout extends LitElement {
  static styles = [
    css`
      .default-layout {
      }
    `,
  ];

  render() {
    return html`
      <main class="default-layout">
        <slot></slot>
      </main>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-default-layout': DefaultLayout;
  }
}
