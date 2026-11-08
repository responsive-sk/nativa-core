import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('mark-sidebar')
export class MarkSidebar extends LitElement {
    static styles = css`
    :host {
      display: flex;
      flex-direction: column;
      height: 100%;
      background-color: var(--admin-sidebar-bg, #1e1e2d);
      color: var(--admin-sidebar-text, #a1a5b7);
      border-right: 1px solid var(--admin-border-color, #2b2b40);
    }

    .logo-area {
      height: var(--admin-header-height, 70px);
      display: flex;
      align-items: center;
      padding: 0 1.5rem;
      color: #fff;
      font-weight: 700;
      font-size: 1.25rem;
      letter-spacing: 0.5px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .nav-area {
      flex: 1;
      padding: 1rem 0;
      overflow-y: auto;
    }

    ::slotted(.nav-label) {
      padding: 0.5rem 1.5rem;
      font-size: 0.75rem;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.3);
      font-weight: 600;
      letter-spacing: 0.5px;
      margin-top: 1rem;
      display: block;
    }

    ::slotted(a) {
      display: flex;
      align-items: center;
      padding: 0.75rem 1.5rem;
      color: var(--admin-sidebar-text, #a1a5b7);
      text-decoration: none;
      transition: all 0.2s;
      font-size: 0.95rem;
      font-weight: 500;
      border-left: 3px solid transparent;
      cursor: pointer;
    }

    ::slotted(a:hover) {
      color: var(--admin-sidebar-active, #ffffff);
      background: rgba(255, 255, 255, 0.03);
    }

    ::slotted(a.active) {
      color: var(--admin-sidebar-active, #ffffff);
      background: var(--admin-sidebar-active-bg, #1b1b28);
      border-left-color: #007bff;
    }
    
    ::slotted(a) .nav-icon {
        margin-right: 0.75rem;
        width: 20px;
        text-align: center;
    }
  `;

    render() {
        return html`
      <div class="logo-area">
        <slot name="logo">Mark Panel</slot>
      </div>
      <nav class="nav-area">
        <slot></slot>
      </nav>
    `;
    }
}
