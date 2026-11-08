import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '../../utils/sharedStyles.js';

@customElement('boson-page-title')
export class BosonPageTitle extends LitElement {
  static styles = [sharedStyles, css`
    .page-title {
      background: url(/images/icons/dots.svg) center center repeat;
      border-bottom: solid 1px var(--color-border);
    }

    .page-title-container {
      width: var(--width-content);
      max-width: var(--width-max);
      margin: 0 auto;
      display: flex;
      flex-direction: row;
      justify-content: flex-start;
      align-items: center;
    }

    ::slotted(*) {
      display: inline-block;
      background: var(--color-bg);
      margin: 0 !important;
      padding: .5em 1em !important;
    }
  `];

  render() {
    return html`
      <hgroup class="page-title">
        <span class="page-title-container">
          <slot></slot>
        </span>
      </hgroup>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-page-title': BosonPageTitle;
  }
}
