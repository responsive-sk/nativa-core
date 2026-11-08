import { css, html, LitElement, PropertyValues } from 'lit';
import { customElement } from 'lit/decorators.js';
import { sharedStyles } from '@/utils/sharedStyles.js';

@customElement('boson-logo')
export class BosonLogo extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            width: 100%;
            height: 100%;
            min-width: 400px;
            min-height: 50px;
            max-width: 550px;
            max-height: 550px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .circle-wrapper {
            width: 100%;
            height: 100%;
            min-width: 50px;
            min-height: 50px;
            max-width: 100%;
            max-height: 100%;
            aspect-ratio: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dot-container {
            width: 100%;
            height: 100%;
            min-width: 50px;
            min-height: 50px;
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .square {
            position: absolute;
            transition: opacity 0.5s ease;
            opacity: 1;
            will-change: transform;
        }

        .square.outer {
            background: #8B8B8B;
        }

        .square.inner {
            background: #F93904;
        }

        .square.dimmed {
            opacity: 0.1;
            border-radius: 50%;
        }

        @media (max-aspect-ratio: 1/1) {
            .circle-wrapper {
                width: 100%;
                height: auto;
            }
        }

        @media (min-aspect-ratio: 1/1) {
            .circle-wrapper {
                width: auto;
                height: 100%;
            }
        }
        @media (orientation: portrait) {
            .container {
                height: 90vw;
                width: 90vw;
                max-width: 400px;
                max-height: 400px;
            }
        }
    `];

    private squares: HTMLElement[] = [];
    private squareData: { originalX: number; originalY: number; element: HTMLElement }[] = [];
    private animationIntervals: number[] = [];
    private mouseX: number = 0;
    private mouseY: number = 0;
    private targetMouseX: number = 0;
    private targetMouseY: number = 0;
    private containerRect: DOMRect | null = null;
    private animationFrame: number | null = null;
    private isMouseOver: boolean = false;
    private resizeObserver: ResizeObserver | null = null;

    private config = {
        outerRadius: 260,
        innerRadius: 60,
        gapBetweenCircles: 10,
        outerLayers: 9,
        innerLayers: 5,
        squareSize: 4,
        squareSpacing: 10,
        outerColor: '#8B8B8B',
        innerColor: '#F93904',
        baseSize: 550,
        mouseRadius: 150,
        animationStrength: 25,
        smoothing: .5
    };

    constructor() {
        super();
        this.squares = [];
        this.squareData = [];
        this.animationIntervals = [];
        this.mouseX = 0;
        this.mouseY = 0;
        this.targetMouseX = 0;
        this.targetMouseY = 0;
        this.containerRect = null;
        this.animationFrame = null;
        this.isMouseOver = false;

        this.config = {
            outerRadius: 260,
            innerRadius: 60,
            gapBetweenCircles: 10,

            outerLayers: 9,
            innerLayers: 5,

            squareSize: 4,
            squareSpacing: 10,

            outerColor: '#8B8B8B',
            innerColor: '#F93904',

            baseSize: 550,

            mouseRadius: 150,
            animationStrength: 25,
            smoothing: .5
        };
    }

    firstUpdated(_changedProperties: PropertyValues) {
        this.createSquares();
        this.startAnimations();
        this.setupMouseTracking();
        this.updateContainerRect();

        this.loopAnimation();

        this.resizeObserver = new ResizeObserver(() => {
            this.updateContainerRect();
        });

        const dotContainer = this.shadowRoot?.querySelector('.dot-container');
        if (dotContainer) {
            this.resizeObserver.observe(dotContainer);
        }
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        this.animationIntervals.forEach(interval => clearInterval(interval));
        this.removeMouseTracking();
        if (this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
        }
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }
    }

    updateContainerRect() {
        const container = this.shadowRoot?.querySelector('.dot-container');
        if (container) {
            this.containerRect = container.getBoundingClientRect();
        }
    }

    setupMouseTracking() {
        const container = this.shadowRoot?.querySelector('.container') as HTMLElement;
        if (!container) return;

        this.handleMouseMove = this.handleMouseMove.bind(this);
        this.handleMouseLeave = this.handleMouseLeave.bind(this);
        this.handleMouseEnter = this.handleMouseEnter.bind(this);

        container.addEventListener('mousemove', this.handleMouseMove as EventListener);
        container.addEventListener('mouseleave', this.handleMouseLeave as EventListener);
        container.addEventListener('mouseenter', this.handleMouseEnter as EventListener);
    }

    removeMouseTracking() {
        const container = this.shadowRoot?.querySelector('.container') as HTMLElement;
        if (!container) return;

        container.removeEventListener('mousemove', this.handleMouseMove as EventListener);
        container.removeEventListener('mouseleave', this.handleMouseLeave as EventListener);
        container.removeEventListener('mouseenter', this.handleMouseEnter as EventListener);
    }

    handleMouseMove(event: MouseEvent) {
        if (!this.containerRect) {
            this.updateContainerRect();
        }

        if (this.containerRect) {
            this.targetMouseX = event.clientX - this.containerRect.left;
            this.targetMouseY = event.clientY - this.containerRect.top + window.scrollY;
        }
    }

    handleMouseEnter(_event: MouseEvent) {
        this.isMouseOver = true;

        if (!this.containerRect) {
            this.updateContainerRect();
        }
    }

    handleMouseLeave() {
        this.isMouseOver = false;

        this.mouseX = -100;
        this.mouseY = -100;
        this.updateSquarePositions();
    }

    loopAnimation() {
        if (window.scrollY < window.innerHeight) {
            if (this.isMouseOver) {
                this.mouseX += (this.targetMouseX - this.mouseX) * this.config.smoothing;
                this.mouseY += (this.targetMouseY - this.mouseY) * this.config.smoothing;
                this.updateSquarePositions();
            } else {
                this.mouseX = -1000;
                this.mouseY = -1000;
                this.resetSquaresToOriginal();
            }
        }

        this.animationFrame = requestAnimationFrame(() => this.loopAnimation());
    }

    resetSquaresToOriginal() {
        this.squareData.forEach((_, index) => {
            const square = this.squares[index];
            const currentTransform = square.style.transform;

            const match = currentTransform.match(/calc\(-50% \+ ([-\d.]+)px\), calc\(-50% \+ ([-\d.]+)px\)/);

            if (match) {
                const currentX = parseFloat(match[1]) || 0;
                const currentY = parseFloat(match[2]) || 0;

                const newX = currentX * (1 - this.config.smoothing);
                const newY = currentY * (1 - this.config.smoothing);

                if (Math.abs(newX) < 0.1 && Math.abs(newY) < 0.1) {
                    square.style.transform = 'translate(-50%, -50%)';
                } else {
                    square.style.transform = `translate(calc(-50% + ${newX}px), calc(-50% + ${newY}px))`;
                }
            }
        });
    }

    updateSquarePositions() {
        const mouseRadiusSq = this.config.mouseRadius * this.config.mouseRadius;

        this.squareData.forEach((data, index) => {
            const square = this.squares[index];

            const dx = data.originalX - this.mouseX;
            const dy = data.originalY - this.mouseY;
            const distanceSq = dx * dx + dy * dy;

            if (distanceSq < mouseRadiusSq && distanceSq > 0) {
                const distance = Math.sqrt(distanceSq);

                const pushStrength = ((this.config.mouseRadius - distance) / this.config.mouseRadius) * this.config.animationStrength;

                const invDistance = .7 / distance;
                const directionX = dx * invDistance;
                const directionY = dy * invDistance;

                const offsetX = directionX * pushStrength;
                const offsetY = directionY * pushStrength;

                square.style.transform = `translate(calc(-50% + ${offsetX}px), calc(-50% + ${offsetY}px))`;
            } else {
                square.style.transform = 'translate(-50%, -50%)';
            }
        });
    }

    createSquares() {
        const container = this.shadowRoot?.querySelector('.dot-container');
        if (!container) return;

        const rect = container.getBoundingClientRect();
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;
        const scale = Math.min(rect.width, rect.height) / this.config.baseSize;

        const squareSize = this.config.squareSize * scale;
        const spacing = this.config.squareSpacing * scale;

        const outerRadius = this.config.outerRadius * scale;
        const outerInnerRadius = outerRadius - (this.config.outerLayers - 1) * spacing;

        for (let layer = 0; layer < this.config.outerLayers; layer++) {
            const radius = outerRadius - layer * spacing;
            const circumference = 2 * Math.PI * radius;
            const squaresInLayer = Math.floor(circumference / spacing);

            for (let i = 0; i < squaresInLayer; i++) {
                const angle = (i / squaresInLayer) * Math.PI * 2;
                const x = centerX + Math.cos(angle) * radius;
                const y = centerY + Math.sin(angle) * radius;

                const square = document.createElement('div');
                square.className = 'square outer';
                square.style.left = `${x}px`;
                square.style.top = `${y}px`;
                square.style.width = `${squareSize}px`;
                square.style.height = `${squareSize}px`;
                square.style.transform = 'translate(-50%, -50%)';
                container.appendChild(square);

                this.squares.push(square);
                this.squareData.push({
                    originalX: x,
                    originalY: y,
                    element: square
                });
            }
        }

        const maxInnerRadius = outerInnerRadius - this.config.gapBetweenCircles * scale;
        const innerRadius = Math.min(this.config.innerRadius * scale, maxInnerRadius);

        for (let layer = 0; layer < this.config.innerLayers; layer++) {
            const radius = innerRadius - layer * spacing;
            if (radius <= 0) break;

            if (radius < spacing) {
                const square = document.createElement('div');
                square.className = 'square inner';
                square.style.left = `${centerX}px`;
                square.style.top = `${centerY}px`;
                square.style.width = `${squareSize}px`;
                square.style.height = `${squareSize}px`;
                square.style.transform = 'translate(-50%, -50%)';
                container.appendChild(square);

                this.squares.push(square);
                this.squareData.push({
                    originalX: centerX,
                    originalY: centerY,
                    element: square
                });
                break;
            }

            const circumference = 2 * Math.PI * radius;
            const squaresInLayer = Math.floor(circumference / spacing);

            for (let i = 0; i < squaresInLayer; i++) {
                const angle = (i / squaresInLayer) * Math.PI * 2;
                const x = centerX + Math.cos(angle) * radius;
                const y = centerY + Math.sin(angle) * radius;

                const square = document.createElement('div');
                square.className = 'square inner';
                square.style.left = `${x}px`;
                square.style.top = `${y}px`;
                square.style.width = `${squareSize}px`;
                square.style.height = `${squareSize}px`;
                square.style.transform = 'translate(-50%, -50%)';
                container.appendChild(square);

                this.squares.push(square);
                this.squareData.push({
                    originalX: x,
                    originalY: y,
                    element: square
                });
            }
        }
    }

    startAnimations() {
        this.squares.forEach((square) => {
            if (Math.random() > 0.96) {
                square.classList.add('dimmed');
            }

            const interval = window.setInterval(() => {
                if (Math.random() > 0.3) {
                    square.classList.toggle('dimmed');
                }
            }, 500 + Math.random() * 3000);

            this.animationIntervals.push(interval);
        });
    }

    render() {
        return html`
            <div class="container">
                <div class="circle-wrapper">
                    <div class="dot-container"></div>
                </div>
            </div>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'boson-logo': BosonLogo;
    }
}
