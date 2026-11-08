import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('mark-layout')
export class MarkLayout extends LitElement {
    static styles = css`
    :host {
      display: block;
      min-height: 100vh;
      background-color: var(--admin-bg, #151521);
      color: var(--admin-text-primary, #ffffff);
      font-family: 'Inter', sans-serif;
    }

    .layout-container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Area */
    .sidebar-area {
      width: var(--admin-sidebar-width-desktop, 280px);
      background-color: var(--admin-sidebar-bg, #1e1e2d);
      flex-shrink: 0;
      position: fixed;
      height: 100vh;
      left: 0;
      top: 0;
      z-index: 1000;
      transition: transform 0.3s ease;
      display: flex;
      flex-direction: column;
    }

    /* Main Content Area */
    .main-area {
      flex: 1;
      margin-left: var(--admin-sidebar-width-desktop, 280px);
      display: flex;
      flex-direction: column;
      width: calc(100% - var(--admin-sidebar-width-desktop, 280px));
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    /* Header Area */
    .header-area {
      height: var(--admin-header-height, 70px);
      background: var(--admin-card-bg, #1e1e2d);
      border-bottom: 1px solid var(--admin-border-color, #2b2b40);
      position: sticky;
      top: 0;
      z-index: 900;
    }

    /* Content Area */
    .content-area {
      padding: 2rem;
      flex: 1;
    }

    @media (max-width: 768px) {
      .sidebar-area {
        transform: translateX(-100%);
      }

      .main-area {
        margin-left: 0;
        width: 100%;
      }
    }
  `;

    render() {
        return html`
      <div class="layout-container">
        <aside class="sidebar-area">
          <slot name="sidebar"></slot>
        </aside>
        
        <div class="main-area">
          <header class="header-area">
            <slot name="header"></slot>
          </header>
          
          <main class="content-area">
            <slot></slot>
          </main>
        </div>
      </div>
    `;
    }
}
