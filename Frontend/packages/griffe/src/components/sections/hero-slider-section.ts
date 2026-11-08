// src/components/sections/hero-slider-section.ts
import { html, css, LitElement } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { classMap } from 'lit/directives/class-map.js';
import { sharedStyles } from '@/utils/sharedStyles';

export interface HeroSlide {
    titleLine1: string;
    titleLine2?: string;
    subtitle?: string;
    tag?: string;
    link: string;
    backgroundImage: string;
    backgroundImageMobile?: string;
    themeColorLight: string;
    themeColorDark: string;
    dataOrder?: number;
}

@customElement('hero-slider-section')
export class HeroSliderSection extends LitElement {
    static styles = [
        sharedStyles,
        css`
      :host {
        display: block;
        position: relative;
        width: 100%;
        height: 100vh;
        min-height: 700px;
        overflow: hidden;
        background: var(--color-bg, #0d1119);
      }

      /* Slider Container */
      .slider {
        position: relative;
        width: 100%;
        height: 100%;
      }

      /* Slides List */
      .slides {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        list-style: none;
      }

      .slides_inner {
        position: relative;
        width: 100%;
        height: 100%;
      }

      /* Individual Slide */
      .slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        visibility: hidden;
        transition: opacity 1s cubic-bezier(0.4, 0, 0.2, 1),
                  visibility 1s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1;
      }

      .slide.active {
        opacity: 1;
        visibility: visible;
        z-index: 2;
      }

      .slide_inner {
        display: flex;
        align-items: center;
        height: 100%;
        padding: 0 5%;
      }

      /* Slide Content Grid */
      .slide_content {
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 80px;
        align-items: center;
      }

      @media (max-width: 992px) {
        .slide_content {
          grid-template-columns: 1fr;
          gap: 40px;
          text-align: center;
        }
        
        .slider--home_img {
          order: -1;
          max-height: 40vh;
        }
      }

      /* Text Content Styles */
      .slider--home_text {
        position: relative;
        z-index: 3;
      }

      .slider--home_text_inner {
        max-width: 600px;
      }

      /* Animated Title Blocks */
      .anim-block_outer {
        display: block;
        overflow: hidden;
        margin: 0;
        line-height: 1;
      }

      .anim-block {
        display: block;
        overflow: hidden;
        margin-bottom: 8px;
      }

      .anim-block_line {
        display: block;
        transform: translateY(100%);
        opacity: 0;
        transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.1s,
                  opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.1s;
      }

      .slide.active .anim-block_line {
        transform: translateY(0);
        opacity: 1;
      }

      .anim-block_inner {
        display: block;
        font-family: var(--font-title, 'Roboto Condensed');
        font-weight: 700;
        color: var(--color-text, rgba(255, 255, 255, 0.9));
      }

      /* Title Sizes */
      .h1-slider {
        font-size: clamp(3rem, 8vw, 5.5rem);
        margin-bottom: 1.5rem;
      }

      .h2-slider {
        font-size: clamp(1.25rem, 3vw, 1.75rem);
        line-height: 1.4;
        color: var(--color-text-secondary, rgba(255, 255, 255, 0.6));
        margin-bottom: 3rem;
        max-width: 500px;
      }

      .h3-slider {
        font-size: 0.875rem;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--color-text-brand, #FF5722);
        margin-bottom: 1rem;
      }

      .anim-desc_outer {
        display: block;
        overflow: hidden;
      }

      .anim-desc {
        display: block;
        transform: translateY(30px);
        opacity: 0;
        transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.3s,
                  opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1) 0.3s;
      }

      .slide.active .anim-desc {
        transform: translateY(0);
        opacity: 1;
      }

      /* Link Styling */
      .link--none {
        text-decoration: none;
        color: inherit;
        display: block;
      }

      /* Background Image */
      .slider--home_img {
        position: relative;
        height: 80vh;
        max-height: 800px;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      }

      .slider--home_img--inner {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        transition: transform 1.2s cubic-bezier(0.4, 0, 0.2, 1);
      }

      .slide.active .slider--home_img--inner {
        transform: scale(1.05);
      }

      /* Spinner Navigation Button */
      .loader--js {
        position: relative;
        display: inline-block;
      }

      .spinner {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: var(--color-text);
        font-family: var(--font-main, Inter);
        font-size: 0.875rem;
        font-weight: 500;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        padding: 16px 24px;
        border-radius: 50px;
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
      }

      .spinner:hover {
        background: rgba(255, 87, 34, 0.1);
        border-color: rgba(255, 87, 34, 0.3);
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(255, 87, 34, 0.2);
      }

      .spinner svg {
        position: absolute;
        width: 50px;
        height: 50px;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-90deg);
      }

      .spinner circle {
        fill: none;
        stroke: var(--color-text-brand, #FF5722);
        stroke-width: 2;
        stroke-dasharray: 628;
        stroke-dashoffset: 628;
        transition: stroke-dashoffset 0.3s ease;
      }

      .spinner:hover circle {
        stroke-dashoffset: 0;
      }

      .spinner-icon {
        font-size: 1.25rem;
        transition: transform 0.3s ease;
      }

      .spinner:hover .spinner-icon {
        transform: translateY(2px);
      }

      .spinner-text {
        position: relative;
        z-index: 1;
      }

      /* Pagination Navigation */
      .slider_pagination_container {
        position: absolute;
        bottom: 40px;
        left: 0;
        width: 100%;
        z-index: 10;
      }

      .slider_pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
      }

      .pagination-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        border: none;
        cursor: pointer;
        padding: 0;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
      }

      .pagination-dot::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: var(--color-text-brand, #FF5722);
        transform: scale(0);
        border-radius: 50%;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }

      .pagination-dot.active::before {
        transform: scale(1);
      }

      .pagination-dot:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.2);
      }

      /* Previous/Next Buttons */
      .slider_nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        font-size: 24px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 10;
        backdrop-filter: blur(10px);
      }

      .slider_nav:hover {
        background: rgba(255, 87, 34, 0.2);
        border-color: rgba(255, 87, 34, 0.4);
        transform: translateY(-50%) scale(1.1);
      }

      .slider_prev {
        left: 40px;
      }

      .slider_next {
        right: 40px;
      }

      @media (max-width: 768px) {
        .slider_prev {
          left: 20px;
        }
        .slider_next {
          right: 20px;
        }
        .slider_nav {
          width: 50px;
          height: 50px;
          font-size: 20px;
        }
      }

      /* Slide Background Overlay */
      .slide_bg_overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(
          to right,
          rgba(13, 17, 25, 0.9) 0%,
          rgba(13, 17, 25, 0.7) 30%,
          rgba(13, 17, 25, 0.4) 100%
        );
        z-index: 1;
      }

      /* Animation Delay Classes */
      .anim-delay-1 { transition-delay: 0.1s; }
      .anim-delay-2 { transition-delay: 0.2s; }
      .anim-delay-3 { transition-delay: 0.3s; }
      .anim-delay-4 { transition-delay: 0.4s; }
      .anim-delay-5 { transition-delay: 0.5s; }

      /* Loading State */
      .slide.loading .slider--home_img--inner {
        background: linear-gradient(90deg, #1a1a2e 25%, #2a2a3e 50%, #1a1a2e 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
      }

      @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
      }
    `
    ];

    @property({ type: Array })
    slides: HeroSlide[] = [];

    @property({ type: Number })
    autoplayInterval = 5000; // 5 seconds

    @state()
    private _currentSlide = 0;

    @state()
    private _isAnimating = false;

    @state()
    private _autoplayTimer: any = null;

    connectedCallback() {
        super.connectedCallback();
        this._startAutoplay();
        this._setupKeyboardNavigation();
    }

    disconnectedCallback() {
        this._stopAutoplay();
        this._cleanupKeyboardNavigation();
        super.disconnectedCallback();
    }

    private _startAutoplay() {
        if (this.autoplayInterval > 0 && this.slides.length > 1) {
            this._autoplayTimer = setInterval(() => {
                this._nextSlide();
            }, this.autoplayInterval);
        }
    }

    private _stopAutoplay() {
        if (this._autoplayTimer) {
            clearInterval(this._autoplayTimer);
            this._autoplayTimer = null;
        }
    }

    private _setupKeyboardNavigation() {
        document.addEventListener('keydown', this._handleKeyDown);
    }

    private _cleanupKeyboardNavigation() {
        document.removeEventListener('keydown', this._handleKeyDown);
    }

    private _handleKeyDown = (e: KeyboardEvent) => {
        if (this._isAnimating) return;

        switch (e.key) {
            case 'ArrowLeft':
                e.preventDefault();
                this._prevSlide();
                break;
            case 'ArrowRight':
            case ' ':
                e.preventDefault();
                this._nextSlide();
                break;
            case 'Home':
                e.preventDefault();
                this._goToSlide(0);
                break;
            case 'End':
                e.preventDefault();
                this._goToSlide(this.slides.length - 1);
                break;
        }
    };

    private _goToSlide(index: number) {
        if (this._isAnimating || index < 0 || index >= this.slides.length || index === this._currentSlide) {
            return;
        }

        this._isAnimating = true;
        this._currentSlide = index;

        // Restart autoplay timer
        this._stopAutoplay();
        this._startAutoplay();

        // Reset animation flag after transition
        setTimeout(() => {
            this._isAnimating = false;
        }, 1000);
    }

    private _nextSlide() {
        const nextIndex = (this._currentSlide + 1) % this.slides.length;
        this._goToSlide(nextIndex);
    }

    private _prevSlide() {
        const prevIndex = this._currentSlide === 0
            ? this.slides.length - 1
            : this._currentSlide - 1;
        this._goToSlide(prevIndex);
    }

    private _onDotClick(index: number, e: Event) {
        e.preventDefault();
        this._goToSlide(index);
    }

    private _onMouseEnter() {
        this._stopAutoplay();
    }

    private _onMouseLeave() {
        this._startAutoplay();
    }

    renderSlide(slide: HeroSlide, index: number) {
        const isActive = index === this._currentSlide;
        const slideClasses = {
            'slide': true,
            'active': isActive,
            'loading': false
        };

        const backgroundImage = window.innerWidth < 992 && slide.backgroundImageMobile
            ? slide.backgroundImageMobile
            : slide.backgroundImage;

        return html`
      <li class=${classMap(slideClasses)} 
          data-order=${slide.dataOrder || index}
          @click=${() => this._goToSlide(index)}>
        
        <div class="slide_bg_overlay"></div>
        
        <div class="slide_inner container container--big">
          <div class="slide_content">
            
            <!-- Text Content -->
            <div class="slider--home_text">
              <div class="slider--home_text_inner">
                <a href=${slide.link} class="link--none">
                  ${slide.tag ? html`
                    <h5 class="h3-slider anim-block_outer">
                      <span class="anim-block">
                        <span class="anim-block_line"></span>
                        <span class="anim-block_inner">
                          <span>${slide.tag}</span>
                        </span>
                      </span>
                    </h5>
                  ` : ''}
                  
                  <h1 class="h1-slider anim-block_outer">
                    <span class="anim-block">
                      <span class="anim-block_line anim-delay-1"></span>
                      <span class="anim-block_inner">
                        <span>${slide.titleLine1}</span>
                      </span>
                    </span>
                    ${slide.titleLine2 ? html`
                      <span class="anim-block">
                        <span class="anim-block_line anim-delay-2"></span>
                        <span class="anim-block_inner">
                          <span>${slide.titleLine2}</span>
                        </span>
                      </span>
                    ` : ''}
                  </h1>
                  
                  ${slide.subtitle ? html`
                    <h2 class="h2-slider anim-desc_outer">
                      <span class="anim-desc anim-delay-3">
                        ${slide.subtitle}
                      </span>
                    </h2>
                  ` : ''}
                </a>
                
                <!-- Spinner Button -->
                <div class="loader--js">
                  <a href=${slide.link} class="spinner">
                    <svg viewBox="0 0 250 250" preserveAspectRatio="xMinYMin meet">
                      <circle cx="120" cy="120" r="100" stroke-dasharray="628" stroke-dashoffset="628" pathLength="628"/>
                    </svg>
                    <svg viewBox="0 0 250 250" preserveAspectRatio="xMinYMin meet">
                      <circle cx="120" cy="120" r="100" stroke-dasharray="628" stroke-dashoffset="628" pathLength="628"/>
                    </svg>
                    <span class="spinner-icon icon-chevron-down">↓</span>
                    <span class="spinner-text">Read More</span>
                  </a>
                </div>
              </div>
            </div>
            
            <!-- Background Image -->
            <div class="slider--home_img">
              <div class="slider--home_img--inner"
                   style="background-image: url('${backgroundImage}')">
              </div>
            </div>
            
          </div>
        </div>
      </li>
    `;
    }

    render() {
        return html`
      <section class="slider slider--home"
               @mouseenter=${this._onMouseEnter}
               @mouseleave=${this._onMouseLeave}>
        
        <!-- Slides -->
        <div class="slides">
          <ul class="slides_inner">
            ${this.slides.map((slide, index) =>
            this.renderSlide(slide, index)
        )}
          </ul>
        </div>
        
        <!-- Navigation Arrows -->
        ${this.slides.length > 1 ? html`
          <button class="slider_nav slider_prev" 
                  @click=${(e: Event) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this._prevSlide();
                }}
                  aria-label="Previous slide">
            ←
          </button>
          <button class="slider_nav slider_next" 
                  @click=${(e: Event) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this._nextSlide();
                }}
                  aria-label="Next slide">
            →
          </button>
        ` : ''}
        
        <!-- Pagination Dots -->
        ${this.slides.length > 1 ? html`
          <div class="slider_pagination_container">
            <nav class="slider_pagination">
              ${this.slides.map((_, index) => html`
                <button class="pagination-dot ${index === this._currentSlide ? 'active' : ''}"
                        @click=${(e: Event) => this._onDotClick(index, e)}
                        aria-label=${`Go to slide ${index + 1}`}
                        aria-current=${index === this._currentSlide ? 'true' : 'false'}>
                </button>
              `)}
            </nav>
          </div>
        ` : ''}
        
      </section>
    `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'hero-slider-section': HeroSliderSection;
    }
}
