import { css, html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('boson-header')
export class BosonHeader extends LitElement {
  @property({ type: Boolean })
  isScrolled: boolean = false;

  static styles = [
    css`
      :host {
        --header-height: 100px;
        --header-height-scrolled: 70px;
      }

      header {
        height: var(--header-height, 100px);
        line-height: var(--header-height, 100px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--color-border);
        transition-duration: 0.2s;
        background: var(--color-bg-opacity);
        backdrop-filter: blur(14px);
        z-index: 10;
      }

      header.scrolled {
        height: var(--header-height-scrolled, 70px);
        line-height: var(--header-height-scrolled, 70px);
      }

      .header-padding {
        width: 100%;
        height: var(--header-height, 100px);
      }

      .dots,
      ::slotted(*) {
        height: 100% !important;
        max-height: 100% !important;
        line-height: inherit !important;
      }

      ::slotted(boson-dropdown) {
        display: flex;
      }

      ::slotted(boson-search-input) {
        border: none;
        margin-left: auto !important;
        order: 2 !important;
        padding: 0 !important;
      }

      ::slotted(.logo) {
        border-right: solid 1px var(--color-border);
      }

      .dots:nth-child(1) {
        border-right: 1px solid var(--color-border);
      }

      .nav {
        flex: 1;
        padding: 0 3em;
        display: flex;
        gap: 1em;
        border-right: 1px solid var(--color-border);
        align-self: stretch;
        align-items: center;
      }

      .aside {
        display: flex;
      }

      .aside ::slotted(*) {
        border-right: 1px solid var(--color-border) !important;
      }

      ::slotted([mobile='true']) {
        display: none;
      }

      ::slotted(mobile-header-menu) {
        display: none;
        border-right: none !important;
      }

      @media (orientation: portrait) {
        ::slotted([pc='true']) {
          display: none;
        }
        ::slotted(.logo) {
          flex: 1;
        }
        ::slotted([mobile='true']) {
          display: flex;
          align-self: stretch;
        }
        ::slotted(mobile-header-menu) {
          display: flex;
          align-self: stretch;
          min-height: var(--header-height-scrolled);
          max-height: var(--header-height-scrolled);
        }
        header {
          height: var(--header-height-scrolled, 70px);
          line-height: var(--header-height-scrolled, 70px);
        }
        .dots {
          display: none;
        }

        .nav {
          display: none;
        }
      }
    `,
  ];

  constructor() {
    super();
    this.handleScroll = this.handleScroll.bind(this);
  }

  connectedCallback(): void {
    super.connectedCallback();
    window.addEventListener('scroll', this.handleScroll);
    this.handleScroll();
  }

  disconnectedCallback(): void {
    super.disconnectedCallback();
    window.removeEventListener('scroll', this.handleScroll);
  }

  handleScroll(): void {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    this.isScrolled = scrollTop > 0;
  }

  render() {
    return html`
      <header class="${this.isScrolled ? 'scrolled' : ''}">
        <div class="dots">
          <dots-container></dots-container>
        </div>

        <slot name="logo"></slot>

        <div class="nav">
          <slot></slot>
        </div>

        <aside class="aside">
          <slot style="display: flex" name="aside"></slot>
          <slot name="mobile-menu"></slot>
        </aside>

        <div class="dots">
          <dots-container></dots-container>
        </div>
      </header>
      <div class="header-padding"></div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-header': BosonHeader;
  }
}
