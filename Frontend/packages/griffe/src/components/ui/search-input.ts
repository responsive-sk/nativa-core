import {css, html, LitElement} from 'lit';
import { customElement } from 'lit/decorators.js';

import {sharedStyles} from '../../utils/sharedStyles';

@customElement('boson-search-input')
export class BosonSearchInput extends LitElement {
    static properties = {
        action: {type: String},
        query: {type: String},
    };

    action = '/';

    query = '';

    static styles = [sharedStyles, css`
        :host {
            margin: 2em 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        form {
            display: block;
            width: 100%;
        }

        input {
            display: block;
            line-height: var(--height-ui);
            height: var(--height-ui);
            font-family: var(--font-title), sans-serif;
            font-size: var(--font-size-secondary);
            letter-spacing: 1px;
            color: var(--color-text);
            transition-duration: .1s;
            background: var(--color-bg);
            text-transform: uppercase;
            padding: 0 2em;
            white-space: nowrap;
            text-decoration: none;
            width: 100%;
            outline: none;
            border: solid 1px var(--color-bg-hover);
        }

        input:hover {
            border: solid 1px var(--color-text-brand-hover);
        }

        input:focus {
            border: solid 1px var(--color-text-brand);
        }
    `];

    render() {
        return html`
            <form method="get" action="${this.action}">
                <input
                    type="search"
                    name="q"
                    value="${this.query}"
                    placeholder="Search"
                    aria-label="Search"
                />
            </form>
        `;
    }
}


declare global {
  interface HTMLElementTagNameMap {
    'boson-search-input': BosonSearchInput;
  }
}
