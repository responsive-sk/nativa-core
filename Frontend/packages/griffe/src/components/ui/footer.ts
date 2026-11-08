import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('boson-footer')
export class BosonFooter extends LitElement {
  static styles = [css`
    .container {
      display: flex;
      flex-direction: column;
    }

    .content {
      border-top: 1px solid var(--color-border);
      border-bottom: 1px solid var(--color-border);
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .top {
      display: flex;
      border-bottom: 1px solid var(--color-border);
    }

    .bottom {
      display: flex;
    }

    .dots-left, .dots-right {
      min-width: 120px;
      max-width: 120px;
      position: absolute;
      top: 0;
      bottom: 0;
    }

    .dots-left {
      left: 0;
    }

    .dots-right {
      right: 0;
    }

    .holder {
      min-width: 120px;
      max-width: 120px;
    }

    .holder:nth-child(1) {
      border-right: 1px solid var(--color-border);
    }

    ::slotted(a) {
      padding: 3.5em 0;
      display: flex !important;
      justify-content: center;
      align-items: center;
      width: 230px;
      border-right: 1px solid var(--color-border);
      transition-duration: 0.2s;
      text-transform: uppercase;
      font-family: var(--font-title), sans-serif;
    }

    ::slotted(a:hover) {
      background: var(--color-bg-hover);
    }

    [name="secondary-link"]::slotted(a) {
      color: var(--color-text-secondary) !important;
    }

    [name="secondary-link"]::slotted(a:hover) {
      background: var(--color-bg-hover) !important;
    }

    .dots-main {
      flex: 1;
      border-right: 1px solid var(--color-border);
      padding: 1em;
    }

    .dots-inner {
      height: 100%;
      width: 100%;
      background: url("/images/icons/dots.svg");
    }

    .copyright {
      flex: 1;
      border-right: 1px solid var(--color-border);
      display: flex;
      align-items: center;
      margin-left: 3em;
      color: var(--color-text-secondary);
    }

    .credits {
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 2em;
    }

    .credits img {
      height: 24px;
    }

    .credits-link {
      display: flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition-duration: 0.2s;
    }
    .credits-link:hover {
      opacity: 0.7;
    }

    @media (orientation: portrait) {
      .dots-left, .dots-right, .holder {
        display: none;
      }
      .top {
        flex-direction: row-reverse;
        flex-wrap: wrap;
      }
      .top > a {
        background: red;
      }
      ::slotted(a) {
        width: unset;
        flex: 34%;
      }
      .bottom {
        flex-direction: column-reverse;
      }
      ::slotted(.social) {
        flex: 21%;
      }
      [name="secondary-link"]::slotted(a) {
        flex: 1;
        padding: 1.5em 0;
        border-bottom: 1px solid var(--color-border);
      }
      .copyright {
        padding: 1.5em 0;
        margin-left: 0;
        text-align: center;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .dots-main {
        flex: 1;
        min-width: 90vw;
        height: 100px;
        border-top: 1px solid var(--color-border);
        border-bottom: 1px solid var(--color-border);
      }
    }
  `];

  render() {
    return html`
      <footer class="container">
        <div class="content">
          <div class="top">
            <div class="holder"></div>

            <slot name="main-link"></slot>

            <div class="dots-main">
              <div class="dots-inner"></div>
            </div>

            <slot name="aside-link"></slot>

            <div class="holder"></div>
          </div>

          <div class="bottom">
            <div class="holder"></div>

            <div class="copyright">
              <slot name="copyright"></slot>
            </div>

            <slot name="secondary-link"></slot>

            <div class="holder"></div>
          </div>

          <div class="dots-left">
            <dots-container></dots-container>
          </div>

          <div class="dots-right">
            <dots-container></dots-container>
          </div>
        </div>

      </footer>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-footer': BosonFooter;
  }
}
