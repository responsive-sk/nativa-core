import {css, html, LitElement} from 'lit';
import { customElement } from 'lit/decorators.js';

import {sharedStyles} from '../../utils/sharedStyles';

@customElement('horizontal-accordion')
export class HorizontalAccordion extends LitElement {
    static properties = {
        content: {type: Array},
        openIndex: {type: Number},
    };

    static styles = [sharedStyles, css`
        .accordion {
            display: flex;
            flex: 1;
            min-height: 400px;
        }
        .headline {
            text-transform: uppercase;
        }

        .element {
            border-right: 1px solid var(--color-border);
            transition-duration: 0.3s;
        }

        .elementOpen {
            flex: 1;
        }

        .elementClosed {
            width: 5em;
            cursor: pointer;
        }

        .elementClosed:hover {
            background: var(--color-bg-hover);
        }

        .elementOpen .elementContent {
            padding: 2em 3em;
        }

        .elementClosed .elementContent {
            padding: 2em 0;
        }

        .elementContent {
            box-sizing: border-box !important;
            transition-duration: 0.2s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .openTop {
            display: flex;
            align-items: center;
            gap: 3em;
            animation: appear 1s forwards;
            height: 60px;
        }

        .openTop > .headline {
            margin: 0;
        }

        .closedTop {
            height: 60px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .content {
            flex: 1;
            width: 700px;
            animation: appear 1s forwards;
            margin-left: 4.5em;
            display: flex;
            align-items: flex-end;
            line-height: 1.75;
        }

        .number {
            color: var(--color-text-brand);
            transition-duration: 0.2s;
            font-size: var(--font-size-h4);
            font-family: var(--font-mono), monospace;
            font-weight: 600;
        }

        .elementClosed .elementContent .closedTop .number {
            color: var(--color-text-secondary);
        }

        .elementClosed:hover .elementContent .closedTop .number {
            color: var(--color-text-brand);
        }

        .collapsedContent {
            flex: 1;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            filter: grayscale(100%);
            transition-duration: 0.2s;
        }

        .elementClosed:hover .elementContent .collapsedContent {
            filter: grayscale(0%);
        }

        @keyframes appear {
            0% {
                opacity: 0;
            }
            100% {
                opacity: 1;
            }
        }

        .closed-headline {
            display: none;
        }
        @media (orientation: portrait) {
            .accordion {
                flex-direction: column;
            }
            .content {
                width: unset;
            }
            .elementClosed {
                width: unset;
            }
            .elementOpen .elementContent {
                padding: 0;
            }
            .elementOpen .openTop {
                padding: 0 1em;
                height: unset;
                gap: 1em;
            }
            .elementOpen .openTop > .headline {
                margin: 0.25em 0;
            }

            .elementClosed .elementContent {
                padding: 0;
                flex-direction: row;
                margin: 0 1em;
                gap: 1em;
            }
            .collapsedContent {
                align-items: center;
                justify-content: space-between;
            }
            .closedTop {
                align-self: center;
            }
            .closed-headline {
                display: flex;
                text-transform: uppercase;
                margin: 0.8em 0 0.7em 0;
                color: var(--color-text-secondary);
            }
            .accordion > .element {
                border-right: unset;
                border-bottom: 1px solid var(--color-border);
            }
            .accordion > .element:nth-last-child(1) {
                border: none !important;
            }
        }
    `];

    content: Array<{ headline: string; text: string }> = [];

    openIndex = 0;

    constructor() {
        super();
    }

    handleElementClick(index: number) {
        this.openIndex = index;
    }

    renderElement(item: { headline: string; text: string }, index: number) {
        const isOpen = this.openIndex === index;

        return html`
            <div
                class="element ${isOpen ? 'elementOpen' : 'elementClosed'}"
                @click=${() => this.handleElementClick(index)}
            >
                <div class="elementContent">
                    ${isOpen ? html`
                        <div class="openTop">
                            <span class="number">0${index + 1}</span>
                            <h4 class="headline">${item.headline}</h4>
                        </div>
                    ` : html`
                        <div class="closedTop">
                            <span class="number">0${index + 1}</span>
                        </div>
                    `}

                    ${isOpen ? html`
                        <div class="content">
                            <p class="text">${item.text}</p>
                        </div>
                    ` : html`
                        <div class="collapsedContent">
                            <h4 class="closed-headline">${item.headline}</h4>
                            <img src="/images/icons/plus.svg" alt="plus"/>
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    render() {
        return html`
            <div class="accordion">
                ${this.content.map((item, index) => this.renderElement(item, index))}
            </div>
        `;
    }
}


declare global {
  interface HTMLElementTagNameMap {
    'horizontal-accordion': HorizontalAccordion;
  }
}
