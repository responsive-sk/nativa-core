import { css, html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { sharedStyles } from "../../utils/sharedStyles.js";

@customElement('slider-component')
export class Slider extends LitElement {
    @property({ type: Number }) currentIndex = 0;
    @property({ type: Number }) slidesPerView = 1;

    private autoplayInterval: number | null = null;
    private slides = []; // Pridajte vaše slide data

    static styles = [sharedStyles, css`
        .container {
            display: flex;
            max-width: 100vw;
            overflow: hidden;
            border-top: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
            background: var(--color-bg);
        }

        .sliderContent {
            width: calc(100vw - 240px - 12px);
            overflow: hidden;
        }

        // ... zvyšok CSS ...
    `];

    connectedCallback(): void {
        super.connectedCallback();
        this.updateSlidesPerView();
        this.startAutoplay();
        window.addEventListener('resize', this.updateSlidesPerView.bind(this));
    }

    disconnectedCallback(): void {
        super.disconnectedCallback();
        this.stopAutoplay();
        window.removeEventListener('resize', this.updateSlidesPerView.bind(this));
    }

    updateSlidesPerView() {
        this.slidesPerView = window.innerWidth >= 768 ? 3 : 1;
        this.requestUpdate();
    }

    startAutoplay() {
        this.stopAutoplay();
        this.autoplayInterval = window.setInterval(() => {
            this.slideNext();
        }, 3000);
    }

    stopAutoplay() {
        if (this.autoplayInterval) {
            clearInterval(this.autoplayInterval);
            this.autoplayInterval = null;
        }
    }

    slidePrev() {
        this.currentIndex = this.currentIndex <= 0 ? this.slides.length - this.slidesPerView : this.currentIndex - 1;
        this.requestUpdate();
    }

    slideNext() {
        this.currentIndex = this.currentIndex >= this.slides.length - this.slidesPerView ? 0 : this.currentIndex + 1;
        this.requestUpdate();
    }

    getTransform() {
        const slideWidth = 100 / this.slidesPerView;
        return `translateX(-${this.currentIndex * slideWidth}%)`;
    }

    renderSlide(slide: any) {
        return html`
            <div class="slideWrapper">
                <div class="slide">
                    <img class="quote" src="/images/icons/quote.svg" alt="quote"/>
                    <p class="comment">"${slide.comment}"</p>
                    <div class="bottom">
                        <img class="pfp" src="${slide.pfp}" alt="${slide.name}"/>
                        <div class="info">
                            <span class="name">${slide.name}</span>
                            <p class="role">${slide.role}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    render() {
        return html`
            <div class="container" @mouseenter="${this.stopAutoplay}" @mouseleave="${this.startAutoplay}">
                <button class="sliderButton" @click=${this.slidePrev}>
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                    <img src="/images/icons/red_arrow_left.svg" alt="prev"/>
                    <span>Prev</span>
                </button>
                <div class="sliderContent">
                    <div class="slidesWrapper" style="transform: ${this.getTransform()}">
                        ${this.slides.map((slide: any) => this.renderSlide(slide))}
                    </div>
                </div>
                <button class="sliderButton" @click=${this.slideNext}>
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                    <img src="/images/icons/red_arrow_right.svg" alt="next"/>
                    <span>Next</span>
                </button>
                <div class="mobile-buttons">
                    <button class="sliderButton" @click=${this.slidePrev}>
                        <img src="/images/icons/red_arrow_left.svg" alt="prev"/>
                        <span>Prev</span>
                    </button>
                    <div class="sep"></div>
                    <button class="sliderButton" @click=${this.slideNext}>
                        <img src="/images/icons/red_arrow_right.svg" alt="next"/>
                        <span>Next</span>
                    </button>
                </div>
            </div>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'slider-component': Slider;
    }
}