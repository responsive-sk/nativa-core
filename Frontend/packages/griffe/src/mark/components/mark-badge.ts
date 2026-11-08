import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('mark-badge')
export class MarkBadge extends LitElement {
    @property({ type: String }) variant = 'secondary';

    static styles = css`
    :host {
      display: inline-flex;
      align-items: center;
      padding: 0.35rem 0.6rem;
      border-radius: 6px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      line-height: 1;
    }

    /* Dark mode optimized colors (using semi-transparent backgrounds) */
    :host([variant="primary"]) { background: rgba(0, 158, 247, 0.15); color: #009ef7; }
    :host([variant="success"]) { background: rgba(80, 205, 137, 0.15); color: #50cd89; }
    :host([variant="danger"]) { background: rgba(241, 65, 108, 0.15); color: #f1416c; }
    :host([variant="warning"]) { background: rgba(255, 199, 0, 0.15); color: #ffc700; }
    :host([variant="secondary"]) { background: rgba(255, 255, 255, 0.1); color: #a1a5b7; }
  `;

    render() {
        return html`<slot></slot>`;
    }
}
