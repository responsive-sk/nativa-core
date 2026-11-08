import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('mark-button')
export class MarkButton extends LitElement {
    @property({ type: String }) variant = 'primary'; // primary, secondary, danger, icon
    @property({ type: String }) href = '';
    @property({ type: String }) type = 'button'; // button, submit

    static styles = css`
    :host {
      display: inline-block;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem;
      border-radius: 0.6rem;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      border: none;
      text-decoration: none;
      transition: filter 0.2s, background 0.2s;
      font-family: inherit;
      line-height: normal;
    }

    .btn:hover {
        filter: brightness(1.1);
    }

    /* Variants */
    .btn-primary {
        background: #009ef7;
        color: white;
    }

    .btn-secondary {
        background: #f5f8fa; /* Light mode */
        color: #7e8299;
    }
    
    /* Dark mode override for secondary */
    :host-context(mark-layout) .btn-secondary, 
    :host-context([theme="dark"]) .btn-secondary {
        background: #323248;
        color: #fff;
    }

    .btn-danger {
        background: #f1416c; /* or transparent with red text/border depending on style */
        color: white;
    }
    
    /* Icon Button Variant */
    .btn-icon {
        padding: 0;
        width: 36px;
        height: 36px;
        background: #323248; /* Dark default */
        color: #a1a5b7;
    }
    .btn-icon:hover {
        color: #fff;
    }
    
    .btn-icon.danger {
        background: rgba(241, 65, 108, 0.1);
        color: #f1416c;
    }
    .btn-icon.danger:hover {
        background: rgba(241, 65, 108, 0.2);
    }

  `;

    render() {
        const classes = `btn btn-${this.variant.replace('icon-', 'icon ')}`;

        if (this.href) {
            return html`<a href="${this.href}" class="${classes}"><slot></slot></a>`;
        }

        return html`
        <button type="${this.type}" class="${classes}">
            <slot></slot>
        </button>
    `;
    }
}
