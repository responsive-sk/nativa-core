import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('article-card')
export class ArticleCard extends LitElement {
  @property({ type: Object })
  article: any;

  static styles = css`
    :host {
      display: block;
      padding: 1rem;
      border: 1px solid var(--color-border);
      border-radius: 8px;
    }
    .status {
      font-size: 0.8rem;
    }
  `;

  _handleClick() {
    this.dispatchEvent(new CustomEvent('article-selected', {
      detail: { article: this.article },
      bubbles: true,
      composed: true
    }));
  }

  render() {
    if (!this.article) return html``;

    return html`
      <article @click=${this._handleClick}>
        <h3>${this.article.title}</h3>
        <p>${this.article.content?.substring(0, 150)}...</p>
        <footer>
          <span class="status status-${this.article.status}">
            ${this.article.status}
          </span>
          <time>
            ${new Date(this.article.created_at).toLocaleDateString('sk-SK')}
          </time>
        </footer>
      </article>
    `;
  }
}


declare global {
  interface HTMLElementTagNameMap {
    'article-card': ArticleCard;
  }
}
