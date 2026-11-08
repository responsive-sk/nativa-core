import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from '@/utils/sharedStyles.js';

@customElement('hero-section')
export class HeroSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            display: flex;
            flex-direction: column;
            margin: 0 auto;
            min-height: calc(100vh - 100px);
        }

        .top {
            display: flex;
            flex-direction: row;
            align-items: center;
            flex: 1;
            gap: 2em;
            justify-content: space-between;
            margin: 0 auto;
            padding: 3em 0;
            max-width: var(--width-max);
            width: var(--width-content);
        }

        .white {
            color: var(--color-text);
        }

        .text {
            flex: 3;
            display: flex;
            flex-direction: column;
            gap: 3em;
        }

        .img {
            flex: 2;
        }

        .headlines {
            line-height: 1.1;
        }

        .headlines ::slotted(h1),
        .headlines ::slotted(h2) {
            margin: 0 !important;
            font-size: var(--font-size-h1) !important;
        }

        .headlines ::slotted(h1) {
            color: var(--color-text-brand) !important;
        }

        .description {
            width: 80%;
            color: var(--color-text-secondary);
        }

        .buttons {
            display: flex;
            flex-direction: row;
            gap: 3em;
        }

        .bottom {
            display: flex;
            align-items: center;
            border-top: 1px solid var(--color-border);
            text-transform: uppercase;
            width: 100%;
        }

        .bottom .discover {
            width: 100%;
            transition-duration: 0.2s;
            font-family: var(--font-title), sans-serif;
            font-size: var(--font-size-secondary);
            letter-spacing: .1em;
            text-decoration: none;
        }

        /* ACCESSIBILITY - Focus indicator for discover link */
        .bottom .discover:focus-visible {
            outline: 2px solid var(--color-border-focus);
            outline-offset: 2px;
            box-shadow: 0 0 0 4px var(--color-border-focus-ring, rgba(255, 87, 34, 0.1));
        }

        .bottom .discover-container {
            transition-duration: 0.2s;
            max-width: var(--width-max);
            width: var(--width-content);
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 3em 0;
        }

        .bottom .discover-icon {
            user-select: none;
        }

        .bottom .discover:hover {
            background-color: var(--color-bg-hover);
        }

        .bottom .discover:hover .discover-container {
            padding: 3em 2em;
        }

        .logo-container {
            display: flex;
            aspect-ratio: 1/1;
        }

        @media (orientation: portrait) {
            .top {
                flex-direction: column;
                padding: 5em 0;
            }

            .text {
                margin: 0 1em;
            }

            .buttons {
                flex-direction: column;
                align-items: flex-start;
                gap: 1em;
            }

            .img {
                max-width: 90vw;
            }

            .bottom {
                padding: 3em 1em;
            }
            .logo-container {
                width: 90vw;
                height: 90vw;
                align-items: center;
                justify-content: center;
            }
        }
    `];

    render() {
        return html`
            <section class="container">
                <div class="top">
                    <div class="text">
                        <div class="headlines">
                            <hgroup>
                                <slot name="title"></slot>
                            </hgroup>
                        </div>

                        <p class="description">
                            <slot name="description"></slot>
                        </p>

                        <div class="buttons">
                            <slot name="buttons"></slot>
                        </div>
                    </div>

                    <div class="img">
                        <div class="logo-container">
                            <boson-logo size="large"> SDFSD SD S</boson-logo>
                        </div>
                    </div>
                </div>

                <aside class="bottom">
                    <a href="#nativeness" class="discover">
                        <span class="discover-container">
                            <span class="discover-text">
                                <slot name="discovery"></slot>
                            </span>

                            <img class="discover-icon"
                                 src="/images/icons/arrow_down.svg" alt="down arrow"/>
                        </span>
                    </a>
                </aside>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'hero-section': HeroSection;
    }
}
