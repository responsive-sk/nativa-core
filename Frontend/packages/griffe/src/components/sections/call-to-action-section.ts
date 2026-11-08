import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('call-to-action-section')
export class CallToActionSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            padding-bottom: 8em;
            background-size: 900px 900px;
            background: url("/images/hero.svg") no-repeat 115% 0;
        }

        .wrapper {
            width: var(--width-content);
            max-width: var(--width-max);
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 3em;
            align-items: flex-start;
        }

        ::slotted(.red) {
            color: var(--color-text-brand) !important;
        }

        @media (orientation: portrait) {
            .container {
                padding: 5em 1em;
                background: url("/images/hero.svg") no-repeat 40vw 27vh;
            }
        }
    `];

    render() {
        return html`
            <section class="container">
                <div class="wrapper">
                    <div class="text">
                        <slot></slot>
                    </div>

                    <slot name="footer"></slot>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'call-to-action-section': CallToActionSection;
    }
}
