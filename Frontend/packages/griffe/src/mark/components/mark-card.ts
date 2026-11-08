import { LitElement, html, css } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('mark-card')
export class MarkCard extends LitElement {
    static styles = css`
    :host {
      display: block;
      background: var(--admin-card-bg, #1e1e2d);
      border: 1px solid var(--admin-border-color, #2b2b40);
      border-radius: 0.85rem;
      /* padding needs to be configurable or default */
      padding: 1.5rem; 
      box-shadow: 0 0.1rem 1rem 0.25rem rgba(0,0,0,0.03);
      color: var(--admin-text-primary, #ffffff);
    }
    
    :host([no-padding]) {
        padding: 0;
    }
  `;

    render() {
        return html`<slot></slot>`;
    }
}
