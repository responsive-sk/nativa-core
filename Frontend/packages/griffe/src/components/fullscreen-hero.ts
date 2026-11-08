// src/components/fullscreen-hero.ts
import { html, css, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('fullscreen-hero')
export class FullscreenHero extends LitElement {
  static styles = css`
    :host {
      display: block;
      width: 100vw;
      height: 100vh;
      position: relative;
    }
  `;

  render() {
    return html`
      <slot name="hero"></slot>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'fullscreen-hero': FullscreenHero;
  }
}
