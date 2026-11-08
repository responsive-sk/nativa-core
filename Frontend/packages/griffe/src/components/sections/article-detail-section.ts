
import { html, css, LitElement } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { unsafeHTML } from 'lit/directives/unsafe-html.js';
import { sharedStyles } from '@/utils/sharedStyles';

export interface ArticleData {
    id: string;
    title: string;
    content: string;
    excerpt: string;
    slug: string;
    status: string;
    createdAt: string;
    updatedAt?: string;
    featuredImage?: string;
    featuredImageAlt?: string;
    author?: {
        name: string;
        avatar?: string;
        role?: string;
    };
    meta?: {
        views?: number;
        likes?: number;
        shares?: number;
    };
    relatedArticles?: Array<{
        id: string;
        title: string;
        excerpt: string;
        slug: string;
        createdAt: string;
    }>;
}

@customElement('article-detail-section')
export class ArticleDetailSection extends LitElement {
    static styles = [
        sharedStyles,
        css`
      :host {
        display: block;
        width: 100%;
        background: var(--color-bg, #0d1119);
        color: var(--color-text);
      }

      /* Article Container */
      .article-container {
        max-width: min(1200px, 90vw);
        margin: 0 auto;
        padding: var(--spacing-2xl, 48px) var(--spacing-md, 20px);
      }

      /* Article Header */
      .article-header {
        text-align: center;
        margin-bottom: var(--spacing-2xl, 48px);
      }

      .article-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: clamp(2.5rem, 5vw, 3.5rem);
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: var(--spacing-md, 16px);
        color: var(--color-text);
      }

      .article-meta {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: var(--spacing-lg, 24px);
        flex-wrap: wrap;
        color: var(--color-text-secondary);
        font-size: 0.95rem;
      }

      .publish-date,
      .update-date {
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .status-published {
        background: rgba(76, 175, 80, 0.1);
        color: #4CAF50;
        border: 1px solid rgba(76, 175, 80, 0.3);
      }

      .status-draft {
        background: rgba(255, 193, 7, 0.1);
        color: #FFC107;
        border: 1px solid rgba(255, 193, 7, 0.3);
      }

      .status-archived {
        background: rgba(244, 67, 54, 0.1);
        color: #F44336;
        border: 1px solid rgba(244, 67, 54, 0.3);
      }

      /* Featured Image */
      .featured-image {
        width: 100%;
        margin: var(--spacing-2xl, 48px) 0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      }

      .featured-image img {
        width: 100%;
        height: auto;
        max-height: 600px;
        object-fit: cover;
        display: block;
      }

      /* Article Content */
      .article-content {
        font-family: var(--font-main, Inter);
        font-size: 1.125rem;
        line-height: 1.8;
        color: var(--color-text);
        max-width: 800px;
        margin: 0 auto;
      }

      .article-content > * {
        margin-bottom: var(--spacing-xl, 32px);
      }

      .article-content h1,
      .article-content h2,
      .article-content h3,
      .article-content h4 {
        font-family: var(--font-title, 'Roboto Condensed');
        font-weight: 700;
        line-height: 1.3;
        margin-top: var(--spacing-2xl, 48px);
        margin-bottom: var(--spacing-lg, 24px);
        color: var(--color-text);
      }

      .article-content h2 {
        font-size: 2rem;
      }

      .article-content h3 {
        font-size: 1.5rem;
      }

      .article-content p {
        margin-bottom: var(--spacing-lg, 24px);
      }

      .article-content a {
        color: var(--color-text-brand);
        text-decoration: none;
        border-bottom: 1px solid rgba(255, 87, 34, 0.3);
        transition: all 0.3s ease;
      }

      .article-content a:hover {
        border-bottom-color: var(--color-text-brand);
      }

      .article-content blockquote {
        border-left: 4px solid var(--color-text-brand);
        padding-left: var(--spacing-lg, 24px);
        margin: var(--spacing-xl, 32px) 0;
        font-style: italic;
        color: var(--color-text-secondary);
        font-size: 1.25rem;
        line-height: 1.6;
      }

      .article-content code {
        font-family: var(--font-mono, 'JetBrains Mono');
        font-size: 0.875em;
        background: rgba(255, 255, 255, 0.05);
        padding: 2px 6px;
        border-radius: 4px;
        color: var(--color-text);
      }

      .article-content pre {
        background: var(--color-bg-layer);
        padding: var(--spacing-lg, 24px);
        border-radius: 8px;
        overflow-x: auto;
        margin: var(--spacing-xl, 32px) 0;
        border: 1px solid rgba(255, 255, 255, 0.05);
      }

      .article-content pre code {
        background: none;
        padding: 0;
        border-radius: 0;
        font-size: 0.875rem;
        line-height: 1.5;
      }

      .article-content ul,
      .article-content ol {
        padding-left: var(--spacing-xl, 32px);
        margin: var(--spacing-lg, 24px) 0;
      }

      .article-content li {
        margin-bottom: var(--spacing-sm, 12px);
      }

      .article-content img {
        max-width: 100%;
        height: auto;
        border-radius: 8px;
        margin: var(--spacing-xl, 32px) 0;
      }

      /* Article Actions */
      .article-actions {
        display: flex;
        justify-content: center;
        gap: var(--spacing-lg, 24px);
        margin: var(--spacing-2xl, 48px) 0;
        padding-top: var(--spacing-xl, 32px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
      }

      .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 50px;
        color: var(--color-text);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
      }

      .action-btn:hover {
        background: rgba(255, 87, 34, 0.1);
        border-color: rgba(255, 87, 34, 0.3);
        transform: translateY(-2px);
      }

      /* Author Info */
      .author-info {
        display: flex;
        align-items: center;
        gap: var(--spacing-lg, 24px);
        margin: var(--spacing-2xl, 48px) auto;
        padding: var(--spacing-xl, 32px);
        background: var(--color-bg-layer);
        border-radius: 16px;
        max-width: 800px;
        border: 1px solid rgba(255, 255, 255, 0.05);
      }

      .author-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid rgba(255, 87, 34, 0.3);
      }

      .author-details h4 {
        margin: 0 0 8px 0;
        font-size: 1.25rem;
      }

      .author-role {
        color: var(--color-text-secondary);
        font-size: 0.95rem;
      }

      /* Related Articles */
      .related-articles {
        margin-top: var(--spacing-3xl, 80px);
        padding-top: var(--spacing-2xl, 48px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
      }

      .related-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: var(--spacing-xl, 32px);
        color: var(--color-text);
        text-align: center;
      }

      .related-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: var(--spacing-lg, 24px);
        max-width: 1200px;
        margin: 0 auto;
      }

      .related-article {
        background: var(--color-bg-layer);
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.05);
        text-decoration: none;
        display: block;
      }

      .related-article:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        border-color: rgba(255, 87, 34, 0.2);
      }

      .related-content {
        padding: var(--spacing-lg, 24px);
      }

      .related-article-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: 1.125rem;
        font-weight: 600;
        line-height: 1.4;
        margin-bottom: var(--spacing-sm, 12px);
        color: var(--color-text);
      }

      .related-excerpt {
        font-size: 0.875rem;
        line-height: 1.5;
        color: var(--color-text-secondary);
        margin-bottom: var(--spacing-md, 16px);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      .related-date {
        font-size: 0.75rem;
        color: var(--color-text-secondary);
      }

      /* Mobile Styles */
      @media (max-width: 768px) {
        .article-container {
          padding: var(--spacing-xl, 32px) var(--spacing-sm, 12px);
        }

        .article-meta {
          flex-direction: column;
          gap: var(--spacing-md, 16px);
        }

        .author-info {
          flex-direction: column;
          text-align: center;
          padding: var(--spacing-lg, 24px);
        }

        .related-grid {
          grid-template-columns: 1fr;
        }
      }
    `
    ];

    @property({ type: Object })
    article?: ArticleData;

    @property({ type: Boolean })
    showAuthor = true;

    @property({ type: Boolean })
    showRelated = true;

    @property({ type: Boolean })
    showActions = true;

    @state()
    private _liked = false;

    @state()
    private _currentUrl = '';

    connectedCallback() {
        super.connectedCallback();
        this._currentUrl = window.location.href;

        // Check if article is liked
        if (this.article?.id) {
            const liked = localStorage.getItem(`article_${this.article.id}_liked`);
            if (liked) {
                this._liked = JSON.parse(liked);
            }
        }
    }

    private _toggleLike() {
        this._liked = !this._liked;

        if (this.article?.id) {
            localStorage.setItem(`article_${this.article.id}_liked`, JSON.stringify(this._liked));

            // Update likes count (in a real app, this would be an API call)
            if (this.article.meta) {
                this.article.meta.likes = (this.article.meta.likes || 0) + (this._liked ? 1 : -1);
            }

            this.requestUpdate();
        }
    }

    private _shareToTwitter() {
        const url = encodeURIComponent(this._currentUrl);
        const text = encodeURIComponent(this.article?.title || 'Check out this article!');
        window.open(`https://twitter.com/intent/tweet?url=${url}&text=${text}`, '_blank');
    }

    private _formatDate(dateString: string): string {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    renderArticleMeta() {
        if (!this.article) return '';

        return html`
      <div class="article-meta">
        <div class="publish-date">
          <span>📅</span>
          <time datetime=${this.article.createdAt}>
            ${this._formatDate(this.article.createdAt)}
          </time>
        </div>

        ${this.article.updatedAt && this.article.updatedAt !== this.article.createdAt ? html`
          <div class="update-date">
            <span>🔄</span>
            <time datetime=${this.article.updatedAt}>
              Updated: ${this._formatDate(this.article.updatedAt)}
            </time>
          </div>
        ` : ''}

        <div class="status-badge status-${this.article.status}">
          ${this.article.status}
        </div>
      </div>
    `;
    }

    renderFeaturedImage() {
        if (!this.article?.featuredImage) return '';

        return html`
      <div class="featured-image">
        <img 
          src="${this.article.featuredImage}" 
          alt="${this.article.featuredImageAlt || this.article.title}" 
          loading="eager"
        />
      </div>
    `;
    }

    renderAuthorInfo() {
        if (!this.showAuthor || !this.article?.author) return '';

        return html`
      <div class="author-info">
        ${this.article.author.avatar ? html`
          <img 
            src="${this.article.author.avatar}" 
            alt="${this.article.author.name}" 
            class="author-avatar"
          />
        ` : ''}
        <div class="author-details">
          <h4>${this.article.author.name}</h4>
          ${this.article.author.role ? html`
            <p class="author-role">${this.article.author.role}</p>
          ` : ''}
        </div>
      </div>
    `;
    }

    renderArticleActions() {
        if (!this.showActions || !this.article) return '';

        return html`
      <div class="article-actions">
        <button 
          class="action-btn"
          @click=${this._toggleLike}
          aria-label=${this._liked ? 'Unlike article' : 'Like article'}
        >
          <span>${this._liked ? '❤️' : '🤍'}</span>
          ${this.article.meta?.likes || 0}
        </button>
        
        <button 
          class="action-btn"
          @click=${this._shareToTwitter}
          aria-label="Share on Twitter"
        >
          <span>𝕏</span>
          Share
        </button>
        
        <a 
          href="/blog" 
          class="action-btn"
          data-pjax
        >
          ← Back to Blog
        </a>
      </div>
    `;
    }

    renderRelatedArticles() {
        if (!this.showRelated || !this.article?.relatedArticles?.length) return '';

        return html`
      <div class="related-articles">
        <h3 class="related-title">Related Articles</h3>
        <div class="related-grid">
          ${this.article.relatedArticles.map(article => html`
            <a 
              href="/blog/${article.slug}" 
              class="related-article"
              data-pjax
            >
              <div class="related-content">
                <h4 class="related-article-title">${article.title}</h4>
                <p class="related-excerpt">${article.excerpt}</p>
                <div class="related-date">
                  ${this._formatDate(article.createdAt)}
                </div>
              </div>
            </a>
          `)}
        </div>
      </div>
    `;
    }

    render() {
        if (!this.article) {
            return html`
        <div class="article-container">
          <div style="text-align: center; padding: 4rem 0;">
            <h2>Article not found</h2>
            <p>The article you're looking for doesn't exist or has been removed.</p>
            <a href="/blog" class="action-btn" data-pjax>
              ← Back to Blog
            </a>
          </div>
        </div>
      `;
        }

        return html`
      <article class="article-container">
        <!-- Article Header -->
        <header class="article-header">
          <h1 class="article-title">${this.article.title}</h1>
          ${this.renderArticleMeta()}
        </header>

        <!-- Featured Image -->
        ${this.renderFeaturedImage()}

        <!-- Article Content -->
        <div class="article-content">
          ${unsafeHTML(this.article.content)}
        </div>

        <!-- Author Info -->
        ${this.renderAuthorInfo()}

        <!-- Article Actions -->
        ${this.renderArticleActions()}

        <!-- Related Articles -->
        ${this.renderRelatedArticles()}
      </article>
    `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'article-detail-section': ArticleDetailSection;
    }
}
