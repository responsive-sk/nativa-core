import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '../../utils/sharedStyles.js';

@customElement('dots-container')
export class DotsContainer extends LitElement {
  static styles = [sharedStyles, css`
    .container {
      aspect-ratio: 1 / 1;
      position: relative;
      height: 100%;
      width: 100%;
    }

    .inner {
      inset: 1em;
      position: absolute;
    }

    .inner > div {
      height: 5px;
      width: 5px;
      position: absolute;
      background: url("/images/icons/dot.svg");
    }

    .top {
      top: 0;
    }

    .bottom {
      bottom: 0;
    }

    .left {
      left: 0;
    }

    .right {
      right: 0;
    }
  `];

  render() {
    return html`
      <div class="container">
        <div class="inner">
          <div class="top left"></div>
          <div class="top right"></div>
          <div class="bottom left"></div>
          <div class="bottom right"></div>
        </div>
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'dots-container': DotsContainer;
  }
}
