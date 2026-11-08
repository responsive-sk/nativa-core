import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '@/utils/sharedStyles.js';

@customElement('boson-blog-layout')
export class BlogLayout extends LitElement {
  static styles = [
    sharedStyles,
    css`
      .blog-layout {
        display: grid;
        grid-template-columns: 1fr 4fr 1fr;
        margin: 0 auto;
        width: var(--width-content);
        max-width: var(--width-max);
      }

      .empty {
        /* Empty column for left spacing */
      }

      .sidebar {
        margin: 0;
        width: 300px;
        max-width: 300px;
        min-width: 300px;
        border-left: solid 1px var(--color-border);
      }

      .sidebar-content {
        flex: 1;
        width: 100%;
        top: 70px;
        display: flex;
        flex-direction: column;
        position: sticky;
        max-height: calc(100vh - 100px);
      }

      .sidebar-categories {
        width: 100%;
        padding: 2em 0;
        display: flex;
        flex-direction: column;
        gap: 0.5em;
        position: relative;
      }

      ::slotted(strong),
      ::slotted(a) {
        padding: 0.3em 0.5em;
      }

      ::slotted(strong) {
        background: var(--color-primary);
        color: var(--color-bg);
        border-radius: var(--border-radius);
      }

      ::slotted(a) {
        color: var(--color-text);
        text-decoration: none;
        border-radius: var(--border-radius);
        transition: background-color 0.2s ease;
      }

      ::slotted(a:hover) {
        background: var(--color-border);
      }

      .content {
        padding: 0 2em;
        min-height: 100vh;
      }

      .content-wrapper {
        padding: 20px 0;
      }

      @media (max-width: 1024px) {
        .blog-layout {
          grid-template-columns: 1fr;
          gap: 2em;
        }

        .sidebar {
          width: 100%;
          max-width: 100%;
          min-width: 100%;
          border-left: none;
          border-bottom: solid 1px var(--color-border);
        }

        .sidebar-content {
          position: static;
          max-height: none;
        }

        .content {
          padding: 0 1em;
        }
      }
    `,
  ];

  render() {
    return html`
      <main class="blog-layout">
        <div class="empty"></div>

        <section class="content">
          <div class="content-wrapper">
            <slot></slot>
          </div>
        </section>

        <aside class="sidebar">
          <div class="sidebar-content">
            <nav class="sidebar-categories">
              <slot name="sidebar"></slot>
            </nav>
          </div>
        </aside>
      </main>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'boson-blog-layout': BlogLayout;
  }
}
