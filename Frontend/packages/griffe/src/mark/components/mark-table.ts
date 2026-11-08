import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('mark-table')
export class MarkTable extends LitElement {
    @property({ type: Boolean }) striped = false;
    @property({ type: Boolean }) hover = true;

    static styles = css`
    :host {
      display: block;
      width: 100%;
      overflow-x: auto;
    }

    .table-container {
      width: 100%;
      border-radius: 8px; /* Assuming card handles outer radius usually */
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.95rem;
    }

    /* Header Styles */
    ::slotted(thead) {
      background: transparent;
      border-bottom: 1px solid var(--admin-border-color, #2b2b40);
    }
    
    /* Using deep selectors or assuming standardized HTML structure because Shadow DOM blocks table styling */
    /* Best practice for wrapped tables is often Light DOM or very specific slotting */
    /* Since we want convenience, we will style standard elements if they are slotted? 
       No, ::slotted only targets top level. 
       
       Solution: Lit component that wraps the table logic often creates the table itself from data,
       OR it acts as a style wrapper helper.
       
       Given PHP renders specific columns, using <mark-table> as a WRAPPER that injects styles 
       into standard <table> is tricky with Shadow DOM isolation.
       
       Option A: Render table in Light DOM (createRenderRoot).
       Option B: Provide specific components like <mark-tr>, <mark-td>. Too verbose.
       Option C: Use Light DOM.
    */
  `;

    createRenderRoot() {
        return this; // Light DOM to allow easy styling of children and simpler PHP integration
    }

    render() {
        // We inject styles manually or rely on global CSS?
        // Since we are moving to components, let's inject a scoped style block if we use Light DOM.
        // Or better: MarkTable just adds classes?
        // Let's render the styles here.

        return html`
        <style>
            mark-table {
                display: block;
                width: 100%;
                overflow-x: auto;
            }
            mark-table table {
                width: 100%;
                border-collapse: collapse;
                color: var(--admin-text-primary, #ffffff);
            }
            
            mark-table thead th {
                text-align: left; 
                padding: 1.25rem 1.5rem; 
                color: var(--admin-text-secondary, #6c757d); 
                font-weight: 600; 
                font-size: 0.85rem; 
                text-transform: uppercase; 
                letter-spacing: 0.05em;
                border-bottom: 1px solid var(--admin-border-color, #2b2b40);
            }

            mark-table tbody tr {
                border-bottom: 1px solid var(--admin-border-color, #2b2b40); /* Dashed or solid */
                transition: background 0.2s;
            }
            
            mark-table tbody tr:last-child {
                border-bottom: none;
            }

            mark-table tbody tr:hover {
                background: var(--admin-bg-hover, rgba(255,255,255,0.02));
            }

            mark-table td {
                padding: 1rem 1.5rem;
                vertical-align: middle;
            }
        </style>
        <slot></slot>
        `;
    }
}
