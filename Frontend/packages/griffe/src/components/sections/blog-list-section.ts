// src/components/sections/blog-list-section.ts
import { html, css, LitElement } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

import { sharedStyles } from '@/utils/sharedStyles';

export interface BlogPost {
  id: string;
  title: string;
  excerpt: string;
  author: string;
  authorAvatar?: string;
  date: string;
  readTime: string;
  category: string;
  tags: string[];
  image: string;
  imageAlt: string;
  slug: string;
  featured?: boolean;
  views?: number;
  comments?: number;
}

/**
 * Blog List Section Component
 * 
 * Displays a grid of blog posts with filtering, pagination, and load more functionality.
 * 
 * @example
 * // Basic usage
 * <blog-list-section .posts=${posts}></blog-list-section>
 * 
 * @example
 * // With filters and pagination
 * <blog-list-section 
 *   .posts=${posts}
 *   show-filters
 *   show-pagination
 *   posts-per-page="12"
 * ></blog-list-section>
 * 
 * @example
 * // Infinite scroll
 * <blog-list-section 
 *   .posts=${posts}
 *   show-load-more
 * ></blog-list-section>
 * 
 * @example
 * // Custom header
 * <blog-list-section 
 *   title="Our Latest Updates"
 *   subtitle="Stay informed with our newest content"
 *   .posts=${posts}
 * ></blog-list-section>
 */
@customElement('blog-list-section')
export class BlogListSection extends LitElement {
  static styles = [
    sharedStyles,
    css`
      :host {
        display: block;
        width: 100%;
        background: var(--color-bg, #0d1119);
      }

      /* Section Container */
      .blog-section {
        max-width: var(--width-max, 1440px);
        margin: 0 auto;
        padding: var(--spacing-3xl, 80px) var(--spacing-md, 20px);
      }

      /* Section Header */
      .section-header {
        text-align: center;
        margin-bottom: var(--spacing-3xl, 64px);
      }

      .section-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: var(--font-size-h2, 48px);
        font-weight: 700;
        line-height: 1.2;
        color: var(--color-text);
        margin-bottom: var(--spacing-md, 16px);
        background: linear-gradient(135deg, #FFFFFF 0%, var(--color-text-secondary) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
      }

      .section-subtitle {
        font-size: 1.125rem;
        line-height: 1.6;
        color: var(--color-text-secondary);
        max-width: 600px;
        margin: 0 auto;
      }

      /* Filter Controls */
      .blog-filters {
        display: flex;
        flex-wrap: wrap;
        gap: var(--spacing-sm, 8px);
        justify-content: center;
        margin-bottom: var(--spacing-2xl, 48px);
      }

      .filter-btn {
        padding: 10px 20px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 24px;
        color: var(--color-text-secondary);
        font-family: var(--font-main, Inter);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(10px);
      }

      .filter-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
      }

      .filter-btn.active {
        background: rgba(255, 87, 34, 0.2);
        border-color: rgba(255, 87, 34, 0.4);
        color: var(--color-text-brand);
      }

      /* Blog Grid */
      .blog-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: var(--spacing-xl, 32px);
        margin-bottom: var(--spacing-2xl, 48px);
      }

      @media (max-width: 768px) {
        .blog-grid {
          grid-template-columns: 1fr;
          gap: var(--spacing-lg, 24px);
        }
      }

      /* Blog Card */
      .blog-card {
        background: var(--color-bg-layer, #0f131c);
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        height: 100%;
        display: flex;
        flex-direction: column;
        border: 1px solid rgba(255, 255, 255, 0.05);
      }

      .blog-card:hover {
        transform: translateY(-8px);
        box-shadow: 
          0 20px 40px rgba(0, 0, 0, 0.3),
          0 0 0 1px rgba(255, 87, 34, 0.1);
        border-color: rgba(255, 87, 34, 0.2);
      }

      .blog-card.featured {
        grid-column: span 2;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--spacing-lg, 24px);
      }

      @media (max-width: 992px) {
        .blog-card.featured {
          grid-column: span 1;
          grid-template-columns: 1fr;
        }
      }

      /* Card Image */
      .card-image {
        position: relative;
        overflow: hidden;
        aspect-ratio: 16/9;
        background: linear-gradient(90deg, #1a1a2e 25%, #2a2a3e 50%, #1a1a2e 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
      }

      .blog-card.featured .card-image {
        height: 100%;
        aspect-ratio: auto;
      }

      .image-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to top, rgba(0, 0, 0, 0.8) 0%, transparent 50%);
        opacity: 0;
        transition: opacity 0.3s ease;
      }

      .blog-card:hover .image-overlay {
        opacity: 1;
      }

      .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
      }

      .blog-card:hover .card-image img {
        transform: scale(1.05);
      }

      /* Category Badge */
      .category-badge {
        position: absolute;
        top: var(--spacing-md, 16px);
        left: var(--spacing-md, 16px);
        padding: 6px 12px;
        background: rgba(255, 87, 34, 0.9);
        color: white;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 12px;
        z-index: 2;
        backdrop-filter: blur(10px);
      }

      /* Card Content */
      .card-content {
        padding: var(--spacing-lg, 24px);
        flex: 1;
        display: flex;
        flex-direction: column;
      }

      .card-meta {
        display: flex;
        align-items: center;
        gap: var(--spacing-sm, 12px);
        margin-bottom: var(--spacing-md, 16px);
        font-size: 0.875rem;
        color: var(--color-text-secondary);
      }

      .author-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
      }

      .author-name {
        font-weight: 500;
        color: var(--color-text);
      }

      .meta-divider {
        opacity: 0.5;
      }

      .read-time {
        display: flex;
        align-items: center;
        gap: 4px;
      }

      /* Card Title */
      .card-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.3;
        color: var(--color-text);
        margin-bottom: var(--spacing-md, 16px);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      .blog-card.featured .card-title {
        font-size: 2rem;
        -webkit-line-clamp: 3;
      }

      /* Card Excerpt */
      .card-excerpt {
        font-size: 1rem;
        line-height: 1.6;
        color: var(--color-text-secondary);
        margin-bottom: var(--spacing-lg, 24px);
        flex: 1;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      /* Tags */
      .card-tags {
        display: flex;
        flex-wrap: wrap;
        gap: var(--spacing-xs, 6px);
        margin-bottom: var(--spacing-lg, 24px);
      }

      .tag {
        padding: 4px 10px;
        background: rgba(255, 255, 255, 0.05);
        color: var(--color-text-secondary);
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 12px;
        transition: all 0.2s ease;
      }

      .tag:hover {
        background: rgba(255, 87, 34, 0.1);
        color: var(--color-text-brand);
      }

      /* Card Footer */
      .card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
        padding-top: var(--spacing-md, 16px);
        border-top: 1px solid rgba(255, 255, 255, 0.05);
      }

      .stats {
        display: flex;
        gap: var(--spacing-lg, 20px);
        color: var(--color-text-secondary);
        font-size: 0.875rem;
      }

      .stat {
        display: flex;
        align-items: center;
        gap: 4px;
      }

      .read-more {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--color-text-brand);
        font-weight: 500;
        text-decoration: none;
        font-size: 0.875rem;
        transition: all 0.3s ease;
      }

      .read-more:hover {
        gap: 12px;
      }

      .read-more-icon {
        transition: transform 0.3s ease;
      }

      .read-more:hover .read-more-icon {
        transform: translateX(4px);
      }

      /* Featured Badge */
      .featured-badge {
        position: absolute;
        top: var(--spacing-md, 16px);
        right: var(--spacing-md, 16px);
        padding: 6px 12px;
        background: linear-gradient(135deg, #FF5722, #FF7043);
        color: white;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 12px;
        z-index: 2;
        backdrop-filter: blur(10px);
      }

      /* Loading Skeleton */
      .skeleton {
        background: linear-gradient(90deg, #1a1a2e 25%, #2a2a3e 50%, #1a1a2e 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
        border-radius: 8px;
      }

      .skeleton-image {
        aspect-ratio: 16/9;
        width: 100%;
      }

      .skeleton-title {
        height: 24px;
        width: 80%;
        margin-bottom: 12px;
      }

      .skeleton-text {
        height: 16px;
        width: 100%;
        margin-bottom: 8px;
      }

      .skeleton-text.short {
        width: 60%;
      }

      @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
      }

      /* Load More Button */
      .load-more {
        text-align: center;
        margin-top: var(--spacing-2xl, 48px);
      }

      .load-more-btn {
        padding: 16px 40px;
        background: rgba(255, 87, 34, 0.1);
        border: 1px solid rgba(255, 87, 34, 0.3);
        border-radius: 50px;
        color: var(--color-text-brand);
        font-family: var(--font-main, Inter);
        font-size: 0.875rem;
        font-weight: 500;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(10px);
      }

      .load-more-btn:hover {
        background: rgba(255, 87, 34, 0.2);
        border-color: rgba(255, 87, 34, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(255, 87, 34, 0.2);
      }

      /* Empty State */
      .empty-state {
        grid-column: 1 / -1;
        text-align: center;
        padding: var(--spacing-3xl, 80px) 0;
        color: var(--color-text-secondary);
      }

      .empty-icon {
        font-size: 3rem;
        margin-bottom: var(--spacing-lg, 24px);
        opacity: 0.5;
      }

      /* Pagination */
      .blog-pagination {
        display: flex;
        justify-content: center;
        gap: var(--spacing-sm, 8px);
        margin-top: var(--spacing-2xl, 48px);
      }

      .pagination-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: var(--color-text-secondary);
        font-family: var(--font-main, Inter);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .pagination-btn:hover {
        background: rgba(255, 87, 34, 0.1);
        border-color: rgba(255, 87, 34, 0.3);
        color: var(--color-text-brand);
      }

      .pagination-btn.active {
        background: rgba(255, 87, 34, 0.2);
        border-color: rgba(255, 87, 34, 0.4);
        color: var(--color-text-brand);
      }

      .pagination-btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .pagination-btn.disabled:hover {
        background: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.1);
        color: var(--color-text-secondary);
      }
    `
  ];

  @property({ type: Array })
  posts: BlogPost[] = [];

  @property({ type: String })
  title = 'Latest Articles';

  @property({ type: String })
  subtitle = 'Discover insights, tutorials, and updates from our team';

  @property({ type: Boolean, attribute: 'show-filters' })
  showFilters = true;

  @property({ type: Boolean, attribute: 'show-load-more' })
  showLoadMore = false;

  @property({ type: Boolean, attribute: 'show-pagination' })
  showPagination = false;

  @property({ type: Number, attribute: 'posts-per-page' })
  postsPerPage = 6;

  @state()
  private _filter = 'all';

  @state()
  private _currentPage = 1;

  @state()
  private _isLoading = false;

  @state()
  private _allCategories: string[] = [];

  connectedCallback() {
    super.connectedCallback();
    this._extractCategories();
  }

  updated(changedProperties: Map<string, any>) {
    if (changedProperties.has('posts')) {
      this._extractCategories();
    }
  }

  private _extractCategories() {
    const categories = new Set<string>();
    this.posts.forEach(post => {
      categories.add(post.category);
      post.tags?.forEach(tag => categories.add(tag));
    });
    this._allCategories = Array.from(categories);
  }

  private _setFilter(category: string) {
    this._filter = category;
    this._currentPage = 1; // Reset to first page when filter changes
  }

  get filteredPosts() {
    if (this._filter === 'all') {
      return this.posts;
    }
    return this.posts.filter(post =>
      post.category === this._filter ||
      post.tags?.includes(this._filter)
    );
  }

  get paginatedPosts() {
    const start = (this._currentPage - 1) * this.postsPerPage;
    const end = start + this.postsPerPage;
    return this.filteredPosts.slice(start, end);
  }

  get totalPages() {
    return Math.ceil(this.filteredPosts.length / this.postsPerPage);
  }

  private _loadMore() {
    if (this._isLoading) return;

    this._isLoading = true;

    // Simulate API call
    setTimeout(() => {
      this._currentPage++;
      this._isLoading = false;
    }, 1000);
  }

  private _goToPage(page: number) {
    if (page < 1 || page > this.totalPages || page === this._currentPage) return;
    this._currentPage = page;

    // Scroll to top of blog section
    this.scrollIntoView({ behavior: 'smooth' });
  }

  renderSkeleton(count: number = 3) {
    return Array(count).fill(0).map(() => html`
      <div class="blog-card">
        <div class="card-image skeleton skeleton-image"></div>
        <div class="card-content">
          <div class="card-meta">
            <div class="skeleton skeleton-text" style="width: 100px;"></div>
          </div>
          <div class="skeleton skeleton-title"></div>
          <div class="skeleton skeleton-text"></div>
          <div class="skeleton skeleton-text"></div>
          <div class="skeleton skeleton-text short"></div>
        </div>
      </div>
    `);
  }

  renderPost(post: BlogPost, index: number) {
    const isFeatured = post.featured || index === 0;

    return html`
      <article class="blog-card ${isFeatured ? 'featured' : ''}">
        ${isFeatured ? html`
          <div class="featured-badge">Featured</div>
        ` : ''}
        
        <div class="card-image">
          <div class="category-badge">${post.category}</div>
          <img 
            src="${post.image}" 
            alt="${post.imageAlt}" 
            loading="lazy"
            @load=${(e: Event) => {
        const img = e.target as HTMLImageElement;
        img.style.animation = 'none';
      }}
            @error=${(e: Event) => {
        const img = e.target as HTMLImageElement;
        img.style.display = 'none';
      }}
          />
          <div class="image-overlay"></div>
        </div>
        
        <div class="card-content">
          <div class="card-meta">
            ${post.authorAvatar ? html`
              <img src="${post.authorAvatar}" alt="${post.author}" class="author-avatar">
            ` : ''}
            <span class="author-name">${post.author}</span>
            <span class="meta-divider">•</span>
            <span class="post-date">${post.date}</span>
            <span class="meta-divider">•</span>
            <span class="read-time">⏱️ ${post.readTime}</span>
          </div>
          
          <h3 class="card-title">
            <a href="/blog/${post.slug}" class="link--none" data-pjax>
              ${post.title}
            </a>
          </h3>
          
          <p class="card-excerpt">${post.excerpt}</p>
          
          ${post.tags?.length ? html`
            <div class="card-tags">
              ${post.tags.slice(0, 3).map(tag => html`
                <span class="tag">#${tag}</span>
              `)}
            </div>
          ` : ''}
          
          <div class="card-footer">
            <div class="stats">
              ${post.views ? html`
                <span class="stat">👁️ ${post.views}</span>
              ` : ''}
              ${post.comments ? html`
                <span class="stat">💬 ${post.comments}</span>
              ` : ''}
            </div>
            
            <a href="/blog/${post.slug}" class="read-more" data-pjax>
              Read More
              <span class="read-more-icon">→</span>
            </a>
          </div>
        </div>
      </article>
    `;
  }

  render() {
    const displayPosts = this.showPagination ? this.paginatedPosts : this.filteredPosts;
    const isLoading = this._isLoading;

    return html`
      <section class="blog-section">
        <!-- Section Header -->
        <header class="section-header">
          <h2 class="section-title">${this.title}</h2>
          ${this.subtitle ? html`
            <p class="section-subtitle">${this.subtitle}</p>
          ` : ''}
        </header>
        
        <!-- Filters -->
        ${this.showFilters && this._allCategories.length > 0 ? html`
          <div class="blog-filters">
            <button 
              class="filter-btn ${this._filter === 'all' ? 'active' : ''}"
              @click=${() => this._setFilter('all')}
            >
              All
            </button>
            ${this._allCategories.map(category => html`
              <button 
                class="filter-btn ${this._filter === category ? 'active' : ''}"
                @click=${() => this._setFilter(category)}
              >
                ${category}
              </button>
            `)}
          </div>
        ` : ''}
        
        <!-- Blog Grid -->
        <div class="blog-grid">
          ${isLoading ? this.renderSkeleton(this.postsPerPage) : ''}
          
          ${!isLoading && displayPosts.length > 0
        ? displayPosts.map((post, index) => this.renderPost(post, index))
        : html`
                <div class="empty-state">
                  <div class="empty-icon">📝</div>
                  <h3>No articles found</h3>
                  <p>Try selecting a different category or check back later.</p>
                </div>
              `
      }
        </div>
        
        <!-- Load More Button -->
        ${this.showLoadMore && this._currentPage < this.totalPages ? html`
          <div class="load-more">
            <button 
              class="load-more-btn"
              @click=${this._loadMore}
              ?disabled=${isLoading}
            >
              ${isLoading ? 'Loading...' : 'Load More Articles'}
            </button>
          </div>
        ` : ''}
        
        <!-- Pagination -->
        ${this.showPagination && this.totalPages > 1 ? html`
          <nav class="blog-pagination" aria-label="Blog pagination">
            <button 
              class="pagination-btn ${this._currentPage === 1 ? 'disabled' : ''}"
              @click=${() => this._goToPage(this._currentPage - 1)}
              ?disabled=${this._currentPage === 1}
              aria-label="Previous page"
            >
              ←
            </button>
            
            ${Array.from({ length: Math.min(5, this.totalPages) }, (_, i) => {
        let pageNum = i + 1;

        // Smart page numbering for many pages
        if (this.totalPages > 5) {
          if (this._currentPage <= 3) {
            pageNum = i + 1;
          } else if (this._currentPage >= this.totalPages - 2) {
            pageNum = this.totalPages - 4 + i;
          } else {
            pageNum = this._currentPage - 2 + i;
          }
        }

        if (pageNum < 1 || pageNum > this.totalPages) return '';

        return html`
                <button 
                  class="pagination-btn ${this._currentPage === pageNum ? 'active' : ''}"
                  @click=${() => this._goToPage(pageNum)}
                  aria-label=${`Page ${pageNum}`}
                  aria-current=${this._currentPage === pageNum ? 'page' : 'false'}
                >
                  ${pageNum}
                </button>
              `;
      })}
            
            <button 
              class="pagination-btn ${this._currentPage === this.totalPages ? 'disabled' : ''}"
              @click=${() => this._goToPage(this._currentPage + 1)}
              ?disabled=${this._currentPage === this.totalPages}
              aria-label="Next page"
            >
              →
            </button>
          </nav>
        ` : ''}
      </section>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'blog-list-section': BlogListSection;
  }
}
