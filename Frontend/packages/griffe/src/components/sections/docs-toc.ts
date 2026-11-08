import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('boson-docs-toc')
export class DocsToc extends LitElement {
    static styles = [sharedStyles, css`
        :host {
            display: flex;
            flex-direction: column;
        }

        .toc-content-container {
            width: var(--width-content);
            max-width: var(--width-max);
            margin: 0 auto;
        }

        .toc-content {
            border: solid 1px var(--color-border);
        }

        .toc-content-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
        }

        .toc-content-container ::slotted(article) {
            padding: 3em;
        }

        @media (max-width: 1440px) {
            .toc-content-container {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .toc-content-container {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .toc-content-container {
                display: grid;
                grid-template-columns: 1fr;
            }
        }
    `];

    render() {
        return html`
            <section class="toc-content">
                <span class="toc-content-container">
                    <slot></slot>
                </span>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'boson-docs-toc': DocsToc;
    }
}
