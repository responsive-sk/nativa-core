import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('mobile-header-menu')
export class MobileHeaderMenu extends LitElement {
    static properties = {
        isOpen: { type: Boolean },
        expandedSections: { type: Object },
    };

    isOpen = false;

    expandedSections: Record<string, boolean> = {};

    static styles = [css`
        :host {
            display: none;
        }

        @media (orientation: portrait) {
            :host {
                align-self: stretch;
                display: flex;
            }
        }

        .menu-toggle {
            cursor: pointer;
            align-self: stretch;
            display: flex;
            align-items: center;
            justify-content: center;
            width: var(--header-height-scrolled);
        }

        .menu-content {
            position: absolute;
            top: calc(var(--header-height-scrolled) + 1px);
            left: 0;
            right: 0;
            background: var(--color-bg);
            border-bottom: 1px solid var(--color-border);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease, height 0.3s ease;
            max-height: 600px;
            height: 0;
            overflow-y: auto;
            interpolate-size: allow-keywords;
        }

        .menu-content.open {
            opacity: 1;
            pointer-events: all;
            height: auto;
        }

        .menu-icon , .close-icon {
            height: 24px;
            width: 24px;
            background-position: center;
        }

        .menu-icon {
            background-image: url("/images/icons/burger.svg");
        }
        .close-icon {
            background-image: url("/images/icons/burger-close.svg");
        }

        .menu-inner {
            padding: 2em;
        }
        ::slotted([slot="references"]), ::slotted([slot="blog"]) {
            display: flex;
            align-items: flex-start;
            flex-direction: column;
        }

        ::slotted(boson-button[slot="references"]), ::slotted(boson-button[slot="blog"]) {
            align-self: stretch;
            justify-content: flex-start;
        }

        .menu-section {
            margin-bottom: 0;
        }

        .menu-title.clickable {
            color: var(--color-text);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: color 0.2s ease;
            text-transform: uppercase;
        }

        .menu-title.clickable::after {
            content: '';
            height: 6px;
            width: 6px;
            border-top: 1px solid var(--color-text-button);
            border-right: 1px solid var(--color-text-button);
            transition: transform 0.3s ease;
            transform: rotate(-45deg);
        }

        .menu-title.clickable.expanded::after {
            transform: rotate(135deg);
        }

        .collapsible-content {
            display: flex;
            flex-direction: column;
            height: 0;
            overflow: hidden;
            transition: height 0.3s ease;
            interpolate-size: allow-keywords;
        }

        .collapsible-content.expanded {
            height: auto;
        }

        ::slotted(boson-button),
        ::slotted(a) {
            display: block !important;
            width: 100% !important;
            text-align: left !important;
            padding: 0.75em 0 !important;
            border: none !important;
            border-bottom: 1px solid var(--color-border) !important;
        }

        ::slotted(boson-button:last-child),
        ::slotted(a:last-child) {
            border-bottom: none !important;
        }

        .collapsible-content ::slotted(boson-button),
        .collapsible-content ::slotted(a) {
            padding-left: 1em !important;
        }
    `];

    constructor() {
        super();
        this.handleClickOutside = this.handleClickOutside.bind(this);
        this.handleEscape = this.handleEscape.bind(this);
    }

    connectedCallback() {
        super.connectedCallback();
        document.addEventListener('click', this.handleClickOutside);
        document.addEventListener('keydown', this.handleEscape);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        document.removeEventListener('click', this.handleClickOutside);
        document.removeEventListener('keydown', this.handleEscape);
        if (this.isOpen) {
            document.body.style.overflow = '';
        }
    }

    handleClickOutside(e: MouseEvent) {
        if (this.isOpen && !this.contains(e.target as Node)) {
            this.isOpen = false;
            this.toggleBodyScroll();
        }
    }

    handleEscape(e: KeyboardEvent) {
        if (this.isOpen && e.key === 'Escape') {
            this.isOpen = false;
            this.toggleBodyScroll();
        }
    }

    toggleMenu() {
        this.isOpen = !this.isOpen;
        this.toggleBodyScroll();
    }

    toggleBodyScroll() {
        if (this.isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    toggleSection(sectionName: string) {
        this.expandedSections = {
            ...this.expandedSections,
            [sectionName]: !this.expandedSections[sectionName]
        };
        this.requestUpdate();
    }

    render() {
        return html`
            <div class="menu-toggle" @click="${this.toggleMenu}">
                ${this.isOpen
                ? html`<div class="close-icon"></div>`
                : html`<div class="menu-icon"></div>`
            }
            </div>

            <div class="menu-content ${this.isOpen ? 'open' : ''}">
                <div class="menu-inner">
                    <div class="menu-section collapsible">
                        <div class="menu-title clickable ${this.expandedSections['references'] ? 'expanded' : ''}"
                             @click="${() => this.toggleSection('references')}">
                            <span>References</span>
                        </div>
                        <div class="collapsible-content ${this.expandedSections['references'] ? 'expanded' : ''}">
                            <slot name="references"></slot>
                        </div>
                    </div>
                    <div class="menu-section collapsible">
                        <div class="menu-title clickable ${this.expandedSections['blog'] ? 'expanded' : ''}"
                             @click="${() => this.toggleSection('blog')}">
                            <span>Blog</span>
                        </div>
                        <div class="collapsible-content ${this.expandedSections['blog'] ? 'expanded' : ''}">
                            <slot name="blog"></slot>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
}


declare global {
  interface HTMLElementTagNameMap {
    'mobile-header-menu': MobileHeaderMenu;
  }
}
