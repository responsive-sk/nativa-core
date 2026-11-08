import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('mobile-development-section')
export class MobileDevelopmentSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            display: flex;
            justify-content: center;
            position: relative;
            border-top: 1px solid var(--color-border);
        }

        .left {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-self: stretch;
            position: relative;
            border-right: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
        }

        .wrapper {
            top: 10em;
            position: sticky;
            gap: 3em;
            display: flex;
            padding: 4em 6em;
            flex-direction: column;
            align-items: flex-start;
        }

        .right {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .red {
            color: var(--color-text-brand);
        }

        .element {
            border-bottom: 1px solid var(--color-border);
            padding: 4em;
            display: flex;
            flex-direction: column;
            gap: 1.5em;
        }

        .top {
            display: flex;
            align-items: center;
            gap: 1.5em;
        }

        .name {
            text-transform: uppercase;
        }

        .text {
            color: var(--color-text-secondary);
        }
        @media (orientation: portrait) {
            .wrapper {
                padding: 1em;
            }
            .container {
                flex-direction: column;
            }
            .element {
                padding: 1em;
                gap: 0;
            }
            .name {
                margin: 0;
            }
        }
    `];

    get elements() {
        return [{
            headline: 'Your own protocols',
            text: `You can intercept any request and process it without raising
                  the HTTP server. After all, a request is just a client event.
                  In this case, you do not necessarily need to use the "http"
                  or "https" protocol, create your own, to which your own
                  application will respond.`,
            icon: 'rocket',
        }, {
            headline: 'Real-time client information',
            text: `You can instantly get information from the client directly
                  from PHP code without any layers on JavaScript. Want to get
                  information about the scroll area? No problem! Want
                  information about all the DOM elements? One line of PHP code!`,
            icon: 'clients',
        }, {
            headline: 'You don\'t need React or Vue',
            text: `You don't need javascript frameworks when you can
                  do all this with PHP code.`,
            icon: 'case',
        }, {
            headline: 'PHP functions in HTML',
            text: `You don't need JavaScript when you can specify which
                  PHP function to call directly from HTML`,
            icon: 'convenient',
        }];
    }

    renderElement(element: { headline: any; text: any; icon: any; }) {
        return html`
            <div class="element">
                <div class="top">
                    <img class="icon" src="/images/icons/${element.icon}.svg" alt="${element.headline}"/>
                    <h5 class="name">${element.headline}</h5>
                </div>
                <p class="text">${element.text}</p>
            </div>
        `;
    }

    render() {
        return html`
            <section class="container">
                <div class="left">
                    <div class="wrapper">
                        <slot></slot>
                    </div>
                </div>
                <div class="right">
                    ${this.elements.map(element => this.renderElement(element))}
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mobile-development-section': MobileDevelopmentSection;
    }
}
