import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('solves-section')
export class SolvesSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            display: flex;
            flex-direction: column;
            gap: 4em;
        }

        .content {
            display: flex;
            border-bottom: 1px solid var(--color-border);
            border-top: 1px solid var(--color-border);
        }

        .dots {
            min-width: 120px;
        }

        .content .dots:nth-child(1) {
            border-right: 1px solid var(--color-border);
        }

        .inner {
            display: flex;
            flex: 1;
        }

        .solves {
            flex: 1;
            border-right: 1px solid var(--color-border);
            padding: 4em;
            gap: 1.25em;
            display: flex;
            line-height: 1.75;
            flex-direction: column;
        }

        .solves img {
            align-self: flex-start;
        }

        .solves h5 {
            text-transform: uppercase;
        }

        @media (orientation: portrait) {
            .top {
                flex-direction: column;
                margin: 0 1em;
                gap: 3em;
            }
            .dots {
                display: none;
            }
            .inner {
                flex-direction: column;
            }
            .solves {
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 3em 2em;
                text-align: center;
                border-bottom: 1px solid var(--color-border);
            }
            .solves:nth-last-child(1) {
                border-bottom: 1px solid transparent;
            }
            .solves > img {
                align-self: center;
            }
        }
    `];

    render() {
        return html`
            <section class="container">
                <div class="content">
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                    <div class="inner">
                        <div class="solves">
                            <img src="/images/icons/terminal.svg" alt="terminal"/>
                            <h5>For developers</h5>
                            <p>Pride in your favorite language, which is not dying! A real desire to create something
                                useful and interesting. Boson will allow you to create applications from scratch, as a
                                framework.</p>
                        </div>
                        <div class="solves">
                            <img src="/images/icons/lock.svg" alt="lock"/>
                            <h5>For business</h5>
                            <p>Desktop application – getting different variants of working applications.</p>
                        </div>
                        <div class="solves">
                            <img src="/images/icons/web.svg" alt="web"/>
                            <h5>For web studios</h5>
                            <p>No need to expand your staff to make applications for different platforms, work with
                                Boson and increase your income.</p>
                        </div>
                    </div>
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'solves-section': SolvesSection;
    }
}
