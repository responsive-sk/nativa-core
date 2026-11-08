import { css, html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { sharedStyles } from '@/utils/sharedStyles';

@customElement('segment-section')
export class SegmentSection extends LitElement {
  @property({ type: String })
  type: string = 'horizontal';

  static styles = [
    sharedStyles,
    css`
      .container {
        display: flex;
        flex-direction: row;
        margin: var(--landing-layout-gap) auto 0 auto;
        gap: 3em;
        max-width: min(var(--width-max), 90vw);
      }

      .segment-title {
        display: flex;
        flex-direction: column;
        flex: 3;
        align-items: flex-start;
        gap: 2em;
      }

      .segment-content {
        flex: 2;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        color: var(--color-text-secondary);
      }

      .segment-title .segment-subtitle {
        display: flex;
        gap: 1em;
        justify-content: center;
        align-items: center;
      }

      .segment-title .segment-subtitle .segment-name {
        font-size: var(--font-size-secondary);
        margin: 0;
        text-transform: uppercase;
        font-weight: 400;
      }

      .segment-title .segment-subtitle svg {
        user-select: none;
      }

      .segment-title .segment-subtitle path {
        fill: var(--color-text-brand);
      }

      ::slotted(.anchor) {
        position: relative;
        top: -250px;
      }

      ::slotted(boson-button) {
        margin-top: 1em;
      }

      ::slotted(ul) {
        list-style-image: url(/images/icons/check.svg);
      }

      /** VERTICAL TYPE */

      .container.container-vertical {
        flex-direction: column;
      }

      /** CENTER TYPE */

      .container.container-center {
        align-items: center;
        flex-direction: column;
      }

      .container.container-center ::slotted(span),
      .container.container-center .title,
      .container.container-center .segment-title {
        margin: 0;
        text-align: center;
        align-items: center;
      }
      @media (orientation: portrait) {
        .container {
          flex-direction: column;
        }
        .title {
          margin: 0;
        }
        .segment-title .segment-subtitle .segment-name {
          font-size: var(--font-size-h5);
          margin: 0;
        }
        .segment-title .segment-subtitle svg {
          height: 16px;
          width: 16px;
        }
      }
    `,
  ];

  render() {
    return html`
      <section class="container container-${this.type}">
        <hgroup class="segment-title">
          <div class="segment-subtitle">
            <svg width="12" height="14" viewBox="0 0 12 14" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4.20167 0L1.03888 14H0L3.15125 0H4.20167Z" />
              <path d="M12 0L8.8372 14H7.79833L10.9496 0H12Z" />
            </svg>

            <h3 class="segment-name">
              <slot name="section"></slot>
            </h3>
          </div>

          <h4 class="title">
            <slot name="title"></slot>
          </h4>
        </hgroup>

        <aside class="segment-content">
          <slot></slot>
        </aside>
      </section>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'segment-section': SegmentSection;
  }
}
