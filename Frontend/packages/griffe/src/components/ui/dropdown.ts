import {css, html, LitElement} from 'lit';
import { customElement } from 'lit/decorators.js';

import {sharedStyles} from "../../utils/sharedStyles.js";

@customElement('boson-dropdown')
export class BosonDropdown extends LitElement {
    static properties = {};

    static styles = [sharedStyles, css`
        :host {
            display: inline-block;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
            height: 100%;
        }

        .dropdown {
            padding-inline-start: 0;
            display: block;
            line-height: var(--height-ui);
            position: relative;
            height: 100%;
        }

        .dropdown-list {
            position: absolute;
            background: var(--color-bg-layer);
            border: 2px solid var(--color-border);
            pointer-events: none;
            transition: 0s ease;
            transform-origin: 0 0;
            opacity: 0;
            transform: scaleY(.5) scaleX(.5);
            min-width: max(100%, 200px);
            z-index: 99;
        }

        .dropdown-list::after {
            position: absolute;
            display: block;
            content: '';
            background: var(--color-border);
            top: 8px;
            left: 8px;
            z-index: -1;
            height: 100%;
            width: 100%;
        }

        .dropdown-list-content {
            display: flex;
            width: 100%;
            flex-direction: column;
            flex-wrap: nowrap;
            padding: 4px;
            background: var(--color-bg-layer);
        }

        .dropdown:hover .dropdown-list {
            pointer-events: all;
            opacity: 1;
            transform: scaleY(1) scaleX(1);
            transition: .1s ease;
        }

        .dropdown-list ::slotted(boson-button) {
            justify-content: flex-start;
            height: var(--height-ui-small);
            line-height: var(--height-ui-small);
        }

        .dropdown-list ::slotted(strong) {
            display: block;
            text-align: center;
            font-size: 70%;
            text-transform: uppercase;
            font-weight: 300;
            color: var(--color-text-secondary);
        }

        .dropdown-summary {
            height: 100%;
        }

        .dropdown-summary ::slotted(boson-button) {
            height: 100%;
        }

        .dropdown:hover > .dropdown-summary ::slotted(boson-button) {
            background: var(--color-border);
        }
    `];

    

    onMouseEnter(e: MouseEvent) {
        (e.target as Element).setAttribute('open', 'open');
    }

    onMouseLeave(e: MouseEvent) {
        (e.target as Element).removeAttribute('open');
    }

    render() {
        return html`
            <menu class="dropdown"
                     @mouseenter="${this.onMouseEnter}"
                     @mouseleave="${this.onMouseLeave}">

                <hgroup class="dropdown-summary">
                    <slot name="summary"></slot>
                </hgroup>

                <nav class="dropdown-list">
                    <div class="dropdown-list-content">
                        <slot></slot>
                    </div>
                </nav>
            </menu>
        `;
    }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-dropdown': BosonDropdown;
  }
}
