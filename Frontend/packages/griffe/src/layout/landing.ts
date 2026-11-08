import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('boson-landing-layout')
export class LandingLayout extends LitElement {
  static styles = [
    css`
      .landing-layout {
        display: flex;
        flex-direction: column;
        gap: var(--landing-layout-gap);
      }
    `,
  ];

  render() {
    return html`
      <main class="landing-layout">
        <slot></slot>
      </main>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-landing-layout': LandingLayout;
  }
}
