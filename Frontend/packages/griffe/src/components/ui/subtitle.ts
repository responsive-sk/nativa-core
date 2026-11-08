import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '../../utils/sharedStyles.js';

@customElement('boson-subtitle')
export class Subtitle extends LitElement {
  static styles = [sharedStyles, css`
    .container {
      display: flex;
      gap: 1em;
      justify-content: center;
      align-items: center;
    }

    .img {
      height: 16px;
      user-select: none;
    }
  `];

  render() {
    return html`
      <div class="container">
        <img class="img" src="/images/icons/subtitle.svg" alt="subtitle"/>

        <h6 class="name">
          <slot></slot>
        </h6>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-subtitle': Subtitle;
  }
}
