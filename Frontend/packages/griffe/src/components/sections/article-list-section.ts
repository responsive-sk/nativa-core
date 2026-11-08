import { LitElement, html } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import '../ui/article-card.ts';

@customElement('article-list-section')
export class ArticleListSection extends LitElement {
  @property({ type: Array })
  articles: any[] = [];

  render() {
    return html`
      <div class="articles">
        ${this.articles.map((a) => html`<article-card .article=${a}></article-card>`)}
      </div>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'article-list-section': ArticleListSection;
  }
}
