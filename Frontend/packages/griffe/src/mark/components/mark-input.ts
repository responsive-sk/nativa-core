import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('mark-input')
export class MarkInput extends LitElement {
    @property({ type: String }) type = 'text';
    @property({ type: String }) label = '';
    @property({ type: String }) name = '';
    @property({ type: String }) value = '';
    @property({ type: String }) placeholder = '';
    @property({ type: Boolean }) required = false;

    static styles = css`
    :host {
      display: block;
      margin-bottom: 1.5rem;
    }

    label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 600;
      color: var(--admin-text-secondary, #a1a5b7);
      font-size: 0.9rem;
    }

    /* We use ::slotted or assume normal input for now, but to wrap native input: */
    
    input, textarea, select {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--admin-border-color, #2b2b40);
        background-color: var(--admin-input-bg, #151521);
        color: var(--admin-text-primary, #ffffff);
        border-radius: 0.6rem;
        font-size: 1rem;
        outline: none;
        transition: border-color 0.2s;
        box-sizing: border-box;
        font-family: inherit;
    }

    input:focus, textarea:focus, select:focus {
        border-color: #009ef7;
    }
  `;

    // Note: Shadow DOM makes forms tricky.
    // For simplicity in this PHP + JS hybrid, we might render a light-dom input or use formData event.
    // BUT the user asked for *Lit components*. 
    // The best way to use Lit in a traditional form submit scenario (without full SPA) 
    // is often to just style slots or render the input but be careful with name attributes.
    // However, `mark-input` acting as a wrapper with Light DOM input is safest for PHP POSTs.

    // STRATEGY: We will Render the label in shadow DOM, but put the input in Light DOM via slot 
    // OR we render the input and use ElementInternals (too complex for this quick refactor).
    // SIMPLEST: Render attributes on an internal input, but that won't submit in a parent form easily without internals.
    // BETTER: Let's make this a styling wrapper for a slotted input.

    render() {
        return html`
      ${this.label ? html`<label>${this.label} ${this.required ? html`<span style="color: #f1416c">*</span>` : ''}</label>` : ''}
      <slot></slot>
    `;
    }
}
