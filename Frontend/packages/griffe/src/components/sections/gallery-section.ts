// src/components/sections/gallery-section.ts
import { html, css, LitElement } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { sharedStyles } from '../../utils/sharedStyles';

export interface GalleryImage {
    src: string;
    alt: string;
    caption?: string;
    width?: number;
    height?: number;
    category?: string;
    tags?: string[];
}

@customElement('gallery-section')
export class GallerySection extends LitElement {
    static styles = [
        sharedStyles,
        css`
      :host {
        display: block;
        width: 100%;
        --gallery-shine-color: rgba(255, 255, 255, 0.15);
      }

      .gallery {
        display: grid;
        gap: var(--spacing-xl, 48px);
        margin: 0 auto;
        max-width: var(--width-max, 1440px);
        padding: var(--spacing-3xl, 64px) var(--spacing-md, 16px);
      }

      .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: var(--spacing-lg, 24px);
      }

      @media (min-width: 768px) {
        .gallery-grid {
          grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
          gap: var(--spacing-xl, 32px);
        }
      }

      /* GALLERY ITEM WITH SHINE EFFECT */
      .gallery-item {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        background: var(--color-bg-layer, #0f131c);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        aspect-ratio: 4/3;
        isolation: isolate;
        box-shadow: 
          0 4px 12px rgba(0, 0, 0, 0.1),
          0 8px 24px rgba(0, 0, 0, 0.15),
          inset 0 1px 0 rgba(255, 255, 255, 0.05);
      }

      .gallery-item::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(
          135deg,
          transparent 0%,
          var(--gallery-shine-color) 50%,
          transparent 100%
        );
        transform: translateX(-100%) rotate(25deg);
        transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 2;
        pointer-events: none;
      }

      .gallery-item:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 
          0 16px 32px rgba(0, 0, 0, 0.25),
          0 32px 64px rgba(0, 0, 0, 0.2),
          0 0 0 1px rgba(255, 87, 34, 0.1),
          inset 0 1px 0 rgba(255, 255, 255, 0.1);
      }

      .gallery-item:hover::before {
        transform: translateX(100%) rotate(25deg);
      }

      /* Image container with gradient overlay */
      .gallery-image-container {
        position: relative;
        width: 100%;
        height: 100%;
        overflow: hidden;
        border-radius: inherit;
      }

      .gallery-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        filter: brightness(0.95) saturate(1.1);
        will-change: transform;
      }

      .gallery-item:hover .gallery-image {
        transform: scale(1.08);
        filter: brightness(1) saturate(1.2);
      }

      /* Gradient overlay for depth */
      .gallery-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(
          to bottom,
          transparent 40%,
          rgba(0, 0, 0, 0.3) 70%,
          rgba(0, 0, 0, 0.7) 100%
        );
        opacity: 0.6;
        transition: opacity 0.4s ease;
        border-radius: inherit;
        z-index: 1;
      }

      .gallery-item:hover .gallery-overlay {
        opacity: 0.8;
      }

      /* Caption with glass effect */
      .gallery-caption {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: var(--spacing-xl, 32px) var(--spacing-lg, 24px);
        background: linear-gradient(
          to top,
          rgba(15, 19, 28, 0.95) 0%,
          rgba(15, 19, 28, 0.8) 50%,
          transparent 100%
        );
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        transform: translateY(10px);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 2;
        border-bottom-left-radius: 16px;
        border-bottom-right-radius: 16px;
      }

      .gallery-item:hover .gallery-caption {
        transform: translateY(0);
        opacity: 1;
      }

      .caption-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--color-text);
        margin-bottom: var(--spacing-xs, 4px);
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      .caption-description {
        font-size: 0.875rem;
        color: var(--color-text-secondary);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }

      /* Tags */
      .gallery-tags {
        position: absolute;
        top: var(--spacing-md, 16px);
        left: var(--spacing-md, 16px);
        display: flex;
        gap: var(--spacing-xs, 4px);
        flex-wrap: wrap;
        z-index: 2;
        transform: translateY(-10px);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      }

      .gallery-item:hover .gallery-tags {
        transform: translateY(0);
        opacity: 1;
      }

      .tag {
        padding: 4px 10px;
        background: rgba(255, 87, 34, 0.9);
        color: white;
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 12px;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
      }

      /* View button */
      .view-button {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.8);
        padding: 12px 24px;
        background: rgba(255, 87, 34, 0.95);
        color: white;
        border: none;
        border-radius: 24px;
        font-weight: 500;
        font-size: 0.875rem;
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 2;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: var(--spacing-xs, 4px);
      }

      .gallery-item:hover .view-button {
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
      }

      .view-button:hover {
        background: rgba(255, 112, 67, 0.95);
        transform: translate(-50%, -50%) scale(1.05);
      }

      .view-icon {
        font-size: 1rem;
      }

      /* Gallery title and description */
      .gallery-header {
        text-align: center;
        max-width: 800px;
        margin: 0 auto var(--spacing-2xl, 48px);
      }

      .gallery-title {
        font-family: var(--font-title, 'Roboto Condensed');
        font-size: var(--font-size-h2, 64px);
        line-height: var(--font-line-height-h2, 120%);
        margin-bottom: var(--spacing-md, 16px);
        color: var(--color-text);
        background: linear-gradient(135deg, var(--color-text) 0%, var(--color-text-secondary) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
      }

      .gallery-description {
        font-size: 1.125rem;
        line-height: 1.6;
        color: var(--color-text-secondary);
        max-width: 600px;
        margin: 0 auto;
      }

      /* Filters */
      .gallery-filters {
        display: flex;
        gap: var(--spacing-sm, 8px);
        flex-wrap: wrap;
        justify-content: center;
        margin-bottom: var(--spacing-2xl, 48px);
      }

      .filter-btn {
        padding: 10px 20px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 24px;
        color: var(--color-text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
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

      /* Lightbox (zachovaný z pôvodnej verzie) */
      .lightbox {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.95);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
      }

      .lightbox.active {
        opacity: 1;
        visibility: visible;
      }

      .lightbox-content {
        position: relative;
        max-width: 90vw;
        max-height: 90vh;
      }

      .lightbox-image {
        max-width: 100%;
        max-height: 90vh;
        object-fit: contain;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
      }

      .lightbox-close {
        position: absolute;
        top: -50px;
        right: 0;
        background: rgba(255, 255, 255, 0.1);
        border: none;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        font-size: 24px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
      }

      .lightbox-close:hover {
        background: rgba(255, 87, 34, 0.8);
      }

      /* Loading animation */
      .gallery-item.loading::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(
          90deg,
          transparent 0%,
          rgba(255, 255, 255, 0.1) 50%,
          transparent 100%
        );
        animation: loading-shine 1.5s infinite;
        border-radius: inherit;
      }

      @keyframes loading-shine {
        0% { transform: translateX(-100%) skewX(-15deg); }
        100% { transform: translateX(100%) skewX(-15deg); }
      }

      /* Empty state */
      .gallery-empty {
        grid-column: 1 / -1;
        text-align: center;
        padding: var(--spacing-3xl, 64px);
        color: var(--color-text-secondary);
      }
    `
    ];

    @property({ type: Array })
    images: GalleryImage[] = [];

    @property({ type: String })
    title = 'Gallery';

    @property({ type: String })
    description = '';

    @property({ type: Boolean })
    showFilters = false;

    @property({ type: Boolean })
    showTags = true;

    @property({ type: Boolean })
    showViewButton = true;

    @state()
    private _currentIndex = 0;

    @state()
    private _showLightbox = false;

    @state()
    private _filter = 'all';

    @property({ type: Array })
    categories: string[] = [];

    get allTags(): string[] {
        const tags = new Set<string>();
        this.images.forEach(img => {
            if (img.tags) {
                img.tags.forEach(tag => tags.add(tag));
            }
            if (img.category) {
                tags.add(img.category);
            }
        });
        return Array.from(tags);
    }

    connectedCallback() {
        super.connectedCallback();
        this._setupKeyboardNavigation();
    }

    disconnectedCallback() {
        this._cleanupKeyboardNavigation();
        super.disconnectedCallback();
    }

    private _setupKeyboardNavigation() {
        document.addEventListener('keydown', this._handleKeyDown);
    }

    private _cleanupKeyboardNavigation() {
        document.removeEventListener('keydown', this._handleKeyDown);
    }

    private _handleKeyDown = (e: KeyboardEvent) => {
        if (!this._showLightbox) return;

        switch (e.key) {
            case 'Escape':
                this._closeLightbox();
                break;
            case 'ArrowLeft':
                this._prevImage();
                break;
            case 'ArrowRight':
                this._nextImage();
                break;
        }
    };

    private _openLightbox(index: number) {
        this._currentIndex = index;
        this._showLightbox = true;
        document.body.style.overflow = 'hidden';
    }

    private _closeLightbox() {
        this._showLightbox = false;
        document.body.style.overflow = '';
    }

    private _prevImage() {
        this._currentIndex = this._currentIndex > 0
            ? this._currentIndex - 1
            : this.images.length - 1;
    }

    private _nextImage() {
        this._currentIndex = this._currentIndex < this.images.length - 1
            ? this._currentIndex + 1
            : 0;
    }

    private _setFilter(filter: string) {
        this._filter = filter;
    }

    get filteredImages() {
        if (this._filter === 'all') {
            return this.images;
        }
        return this.images.filter(img =>
            img.tags?.includes(this._filter) ||
            img.category === this._filter
        );
    }

    renderImage(image: GalleryImage, index: number) {
        const originalIndex = this.images.indexOf(image);

        return html`
      <div class="gallery-item">
        <div class="gallery-image-container">
          <img 
            src=${image.src}
            alt=${image.alt}
            class="gallery-image"
            loading="lazy"
            width=${image.width || 400}
            height=${image.height || 300}
            @load=${() => {
                // Remove loading class when image loads
                const item = this.shadowRoot?.querySelector(`[data-index="${index}"]`);
                if (item) item.classList.remove('loading');
            }}
            @error=${(e: Event) => {
                const img = e.target as HTMLImageElement;
                img.style.opacity = '0';
            }}
          />
          <div class="gallery-overlay"></div>
        </div>

        ${this.showTags && (image.tags?.length || image.category) ? html`
          <div class="gallery-tags">
            ${image.category ? html`
              <span class="tag">${image.category}</span>
            ` : ''}
            ${image.tags?.slice(0, 2).map(tag => html`
              <span class="tag">${tag}</span>
            `)}
          </div>
        ` : ''}

        ${this.showViewButton ? html`
          <button class="view-button" @click=${(e: Event) => {
                    e.stopPropagation();
                    this._openLightbox(originalIndex);
                }}>
            <span class="view-icon">👁️</span>
            View
          </button>
        ` : ''}

        ${image.caption ? html`
          <div class="gallery-caption">
            <div class="caption-title">${image.alt}</div>
            <div class="caption-description">${image.caption}</div>
          </div>
        ` : ''}
      </div>
    `;
    }

    render() {
        const currentImage = this.images[this._currentIndex];
        const displayImages = this.filteredImages;
        const tags = this.allTags;

        return html`
      <section class="gallery">
        <div class="gallery-header">
          ${this.title ? html`
            <h2 class="gallery-title">${this.title}</h2>
          ` : ''}
          
          ${this.description ? html`
            <p class="gallery-description">${this.description}</p>
          ` : ''}
        </div>

        ${this.showFilters && tags.length > 0 ? html`
          <div class="gallery-filters">
            <button 
              class="filter-btn ${this._filter === 'all' ? 'active' : ''}"
              @click=${() => this._setFilter('all')}
            >
              All
            </button>
            ${tags.map(tag => html`
              <button 
                class="filter-btn ${this._filter === tag ? 'active' : ''}"
                @click=${() => this._setFilter(tag)}
              >
                ${tag}
              </button>
            `)}
          </div>
        ` : ''}

        <div class="gallery-grid">
          ${displayImages.length > 0
                ? displayImages.map((image, index) =>
                    this.renderImage(image, index)
                )
                : html`
                <div class="gallery-empty">
                  <h3>No images found</h3>
                  <p>Try selecting a different filter</p>
                </div>
              `
            }
        </div>

        <!-- Lightbox -->
        <div class="lightbox ${this._showLightbox ? 'active' : ''}" 
             @click=${(e: Event) => {
                if (e.target === e.currentTarget) this._closeLightbox();
            }}>
          ${this._showLightbox && currentImage ? html`
            <div class="lightbox-content">
              <button class="lightbox-close" @click=${this._closeLightbox}>
                &times;
              </button>
              
              ${this.images.length > 1 ? html`
                <button class="filter-btn lightbox-prev" 
                        style="position: absolute; left: 20px; top: 50%; transform: translateY(-50%)"
                        @click=${this._prevImage}>
                  ←
                </button>
                <button class="filter-btn lightbox-next" 
                        style="position: absolute; right: 20px; top: 50%; transform: translateY(-50%)"
                        @click=${this._nextImage}>
                  →
                </button>
                <div style="position: absolute; top: -45px; left: 0; color: white; font-size: 14px;">
                  ${this._currentIndex + 1} / ${this.images.length}
                </div>
              ` : ''}

              <img 
                src=${currentImage.src}
                alt=${currentImage.alt}
                class="lightbox-image"
              />
              
              ${currentImage.caption ? html`
                <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); color: white; padding: 16px; text-align: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                  ${currentImage.caption}
                </div>
              ` : ''}
            </div>
          ` : ''}
        </div>
      </section>
    `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'gallery-section': GallerySection;
    }
}