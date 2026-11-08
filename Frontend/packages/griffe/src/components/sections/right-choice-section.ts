import { css, html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('right-choice-section')
export class RightChoiceSection extends LitElement {
    static styles = [sharedStyles, css`
        :host {
            margin-top: calc(var(--landing-layout-gap) * -1);
        }

        .container {
            background-size: cover;
            background: url("/images/right_choice_bg.svg") no-repeat center;
            min-height: 200vh;
            display: flex;
            flex-direction: column;
        }

        .top {
            margin: 18em;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .top h2 {
            font-size: var(--font-size-h1);
        }

        .red {
            color: var(--color-text-brand);
        }

        .content {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-top: 1px solid var(--color-border);
        }
        .content-top {
            border-bottom: 1px solid var(--color-border);
        }
        .sep {
            min-width: 1px;
            align-self: stretch;
            background: var(--color-border);
        }
        .content-top, .content-bottom {
            display: flex;
            align-self: stretch;
            flex: 1;
        }
        .content-left, .content-right {
            display: flex;
            position: relative;
            flex: 1;
            padding: 4em;
            overflow: hidden;
        }
        .content-top > div {
            align-items: flex-end;
        }
        .content-left {
            justify-content: flex-end;
            mask-image: linear-gradient(to left, transparent 0%, black 4em);
        }
        .content-right {
            justify-content: flex-start;
            mask-image: linear-gradient(to right, transparent 0%, black 4em);
        }

        .inner {
            width: 620px;
            transition: transform 0.5s ease;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 1em;
        }
        .progress {
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: center;
            padding: 3em;
            align-self: stretch;
            border-top: 1px solid var(--color-border);
            gap: 1em;
        }
        .el {
            display: flex;
            flex-direction: row;
            gap: 5px;
        }
        .dots {
            height: 14px;
            width: 5px;
        }
        .dots.red {
            background-image: url("/images/icons/dots_red.svg");
        }
        .dots.grey {
            background-image: url("/images/icons/dots_grey.svg");
        }
        .progress-text {
            text-transform: uppercase;
            color: var(--color-text-secondary);
            font-family: var(--font-title), sans-serif;
        }

        /* Mobile styles - Portrait orientation only */
        @media (orientation: portrait) {
            .progress {
                margin-top: 15em;
            }
            .top {
                margin: 5em 1em;
            }
            .top h2 {
                font-size: 2.5rem;
            }

            .container {
                min-height: 100vh;
            }

            .content {
                position: relative;
            }

            .content-top, .content-bottom {
                position: relative;
                min-height: 200px;
            }

            .content-left, .content-right {
                position: absolute;
                left: 0;
                right: 0;
                padding: 2em;
                mask-image: none;
                justify-content: center;
                align-items: center;
                text-align: center;
                transition: opacity 0.5s ease;
            }

            .content-top .content-left,
            .content-top .content-right {
                bottom: 0;
            }

            .content-bottom .content-left,
            .content-bottom .content-right {
                top: 0;
            }

            .inner {
                width: 100%;
                max-width: 400px;
                align-items: center;
                text-align: center;
                transform: none !important;
            }

            .sep {
                display: none;
            }

            .mobile-hidden {
                opacity: 0;
            }

            .mobile-visible {
                opacity: 1;
            }
        }
    `];

    static animationConfig = {
        blockDuration: 7000,
        transitionDuration: 500,
        animationDistance: 800,
    };

    private elements: {
        topLeft: HTMLElement | null;
        topRight: HTMLElement | null;
        bottomLeft: HTMLElement | null;
        bottomRight: HTMLElement | null;
        progressDots: NodeListOf<Element> | null;
    } = {
            topLeft: null,
            topRight: null,
            bottomLeft: null,
            bottomRight: null,
            progressDots: null
        };

    @property({ type: Boolean })
    isMobile = false;

    private animationState: {
        startTime: number;
        animationId: number | null;
    } = {
            startTime: 0,
            animationId: null
        };

    private _handleOrientationChange = () => {
        setTimeout(() => {
            this.checkMobile();
        }, 100);
    };

    private _handleResize = () => {
        this.checkMobile();
    };



    firstUpdated(): void {
        this.elements.topLeft = this.shadowRoot!.querySelector('.content-top .content-left .inner') as HTMLElement;
        this.elements.topRight = this.shadowRoot!.querySelector('.content-top .content-right .inner') as HTMLElement;
        this.elements.bottomLeft = this.shadowRoot!.querySelector('.content-bottom .content-left .inner') as HTMLElement;
        this.elements.bottomRight = this.shadowRoot!.querySelector('.content-bottom .content-right .inner') as HTMLElement;
        this.elements.progressDots = this.shadowRoot!.querySelectorAll('.dots');

        this.checkMobile();
        this.startAnimation();

        // Listen for orientation changes
        window.addEventListener('orientationchange', this._handleOrientationChange);
        window.addEventListener('resize', this._handleResize);
    }

    disconnectedCallback(): void {
        super.disconnectedCallback();
        this.stopAnimation();
        window.removeEventListener('orientationchange', this._handleOrientationChange);
        window.removeEventListener('resize', this._handleResize);
    }

    checkMobile() {
        const wasMobile = this.isMobile;
        this.isMobile = window.matchMedia('(orientation: portrait)').matches;

        if (wasMobile !== this.isMobile) {
            // Reset transforms when switching modes
            if (this.elements.topLeft && this.elements.topRight &&
                this.elements.bottomLeft && this.elements.bottomRight) {

                if (this.isMobile) {
                    // Reset desktop transforms for mobile
                    this.elements.topLeft.style.transform = '';
                    this.elements.topRight.style.transform = '';
                    this.elements.bottomLeft.style.transform = '';
                    this.elements.bottomRight.style.transform = '';
                } else {
                    // Reset mobile classes for desktop
                    this.resetMobileClasses();
                }
            }
        }
    }

    resetMobileClasses() {
        const containers = [
            this.shadowRoot!.querySelector('.content-top .content-left'),
            this.shadowRoot!.querySelector('.content-top .content-right'),
            this.shadowRoot!.querySelector('.content-bottom .content-left'),
            this.shadowRoot!.querySelector('.content-bottom .content-right')
        ];

        containers.forEach(container => {
            if (container) {
                container.classList.remove('mobile-hidden', 'mobile-visible');
            }
        });
    }

    startAnimation() {
        this.animationState.startTime = Date.now();
        this.runLoop();
    }

    stopAnimation() {
        if (this.animationState.animationId) {
            cancelAnimationFrame(this.animationState.animationId);
            this.animationState.animationId = null;
        }
    }

    runLoop() {
        const config = RightChoiceSection.animationConfig;
        const currentTime = Date.now();
        const elapsed = currentTime - this.animationState.startTime;

        const totalCycleDuration = (config.blockDuration * 4) + (config.transitionDuration * 4);
        const cyclePosition = elapsed % totalCycleDuration;

        const phase1End = config.blockDuration;
        const phase2End = phase1End + config.transitionDuration;
        const phase3End = phase2End + config.blockDuration;
        const phase4End = phase3End + config.transitionDuration;
        const phase5End = phase4End + config.blockDuration;
        const phase6End = phase5End + config.transitionDuration;
        const phase7End = phase6End + config.blockDuration;


        let progressValue = 0;
        let elementStage = 0;
        let showSecond = false;

        if (cyclePosition < phase1End) {
            const phaseProgress = cyclePosition / config.blockDuration;
            progressValue = phaseProgress * 0.5;
            elementStage = 0;
            showSecond = false;
        } else if (cyclePosition < phase2End) {
            const transitionElapsed = cyclePosition - phase1End;
            const transitionProgress = transitionElapsed / config.transitionDuration;
            progressValue = 0.5;
            elementStage = transitionProgress;
            showSecond = transitionProgress > 0.5;
        } else if (cyclePosition < phase3End) {
            const phaseProgress = (cyclePosition - phase2End) / config.blockDuration;
            progressValue = 0.5 + (phaseProgress * 0.5);
            elementStage = 1;
            showSecond = true;
        } else if (cyclePosition < phase4End) {
            const transitionElapsed = cyclePosition - phase3End;
            const transitionProgress = transitionElapsed / config.transitionDuration;
            progressValue = 1.0;
            elementStage = 1 - transitionProgress;
            showSecond = transitionProgress < 0.5;
        } else if (cyclePosition < phase5End) {
            const phaseProgress = (cyclePosition - phase4End) / config.blockDuration;
            progressValue = 1.0 - (phaseProgress * 0.5);
            elementStage = 0;
            showSecond = false;
        } else if (cyclePosition < phase6End) {
            const transitionElapsed = cyclePosition - phase5End;
            const transitionProgress = transitionElapsed / config.transitionDuration;
            progressValue = 0.5;
            elementStage = transitionProgress;
            showSecond = transitionProgress > 0.5;
        } else if (cyclePosition < phase7End) {
            const phaseProgress = (cyclePosition - phase6End) / config.blockDuration;
            progressValue = 0.5 - (phaseProgress * 0.5);
            elementStage = 1;
            showSecond = true;
        } else {
            const transitionElapsed = cyclePosition - phase7End;
            const transitionProgress = transitionElapsed / config.transitionDuration;
            progressValue = 0;
            elementStage = 1 - transitionProgress;
            showSecond = transitionProgress < 0.5;
        }

        if (this.isMobile) {
            this.animateMobileElements(showSecond);
        } else {
            this.animateDesktopElements(elementStage);
        }

        this.updateProgressBar(progressValue);

        this.animationState.animationId = requestAnimationFrame(() => this.runLoop());
    }

    animateDesktopElements(progress: number) {
        const config = RightChoiceSection.animationConfig;
        const maxTranslate = config.animationDistance;

        if (!this.elements.topLeft || !this.elements.topRight ||
            !this.elements.bottomLeft || !this.elements.bottomRight) {
            return;
        }

        const topLeftTranslate = progress * maxTranslate;
        const topRightTranslate = Math.min(0, -maxTranslate + (progress * maxTranslate));
        const bottomRightTranslate = -(progress * maxTranslate);
        const bottomLeftTranslate = Math.max(0, maxTranslate - (progress * maxTranslate));

        this.elements.topLeft.style.transform = `translateX(${topLeftTranslate}px)`;
        this.elements.topRight.style.transform = `translateX(${topRightTranslate}px)`;
        this.elements.bottomRight.style.transform = `translateX(${bottomRightTranslate}px)`;
        this.elements.bottomLeft.style.transform = `translateX(${bottomLeftTranslate}px)`;
    }

    animateMobileElements(showSecond: boolean) {
        const topLeft = this.shadowRoot!.querySelector('.content-top .content-left');
        const topRight = this.shadowRoot!.querySelector('.content-top .content-right');
        const bottomLeft = this.shadowRoot!.querySelector('.content-bottom .content-left');
        const bottomRight = this.shadowRoot!.querySelector('.content-bottom .content-right');

        if (!topLeft || !topRight || !bottomLeft || !bottomRight) {
            return;
        }

        // Top section: toggle between left and right
        if (showSecond) {
            topLeft.classList.add('mobile-hidden');
            topLeft.classList.remove('mobile-visible');
            topRight.classList.add('mobile-visible');
            topRight.classList.remove('mobile-hidden');
        } else {
            topLeft.classList.add('mobile-visible');
            topLeft.classList.remove('mobile-hidden');
            topRight.classList.add('mobile-hidden');
            topRight.classList.remove('mobile-visible');
        }

        // Bottom section: toggle between left and right
        if (showSecond) {
            bottomLeft.classList.add('mobile-hidden');
            bottomLeft.classList.remove('mobile-visible');
            bottomRight.classList.add('mobile-visible');
            bottomRight.classList.remove('mobile-hidden');
        } else {
            bottomLeft.classList.add('mobile-visible');
            bottomLeft.classList.remove('mobile-hidden');
            bottomRight.classList.add('mobile-hidden');
            bottomRight.classList.remove('mobile-visible');
        }
    }

    updateProgressBar(progressValue: number) {
        if (!this.elements.progressDots || this.elements.progressDots.length === 0) {
            return;
        }

        const totalDots = this.elements.progressDots.length; // Should be 30
        const activeDots = Math.floor(progressValue * totalDots);

        this.elements.progressDots.forEach((dot, index) => {
            if (index < activeDots) {
                dot.classList.remove('grey');
                dot.classList.add('red');
            } else {
                dot.classList.remove('red');
                dot.classList.add('grey');
            }
        });
    }

    render() {
        return html`
            <section class="container">
                <div class="top">
                    <h2>
                        Why is Boson PHP</br>
                        <span class="red">the right choice</span> </br>
                        for you?
                    </h2>
                </div>
                <div class="content">
                    <div class="content-top">
                        <div class="content-left">
                            <div class="inner">
                                <h3>Your PHP — On All Devices</h3>
                            </div>
                        </div>
                        <div class="sep"></div>
                        <div class="content-right">
                            <div class="inner">
                                <h3>One Line to Start With</h3>
                            </div>
                        </div>
                    </div>
                    <div class="content-bottom">
                        <div class="content-left">
                            <div class="inner">
                                <p>
                                    To launch the application, you will need
                                    only one line of code. Without a ton of
                                    settings and configurations.
                                    Even a child can figure it out.
                                </p>

                                <!--
                                <boson-button href="/">
                                    Read More
                                </boson-button>
                                -->
                            </div>
                        </div>
                        <div class="sep"></div>
                        <div class="content-right">
                            <div class="inner">
                                <p>
                                    No need to learn other languages! You already
                                    know PHP, and that's all you need. Write code
                                    once for the Web and create native apps on
                                    Windows, macOS and Linux. The same code,
                                    and your app is available everywhere.
                                </p>

                                <!--
                                <boson-button href="/">
                                    Read More
                                </boson-button>
                                -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="progress">
                    <div class="el">
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                    </div>
                    <span class="progress-text">STAGE</span>
                    <div class="el">
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                        <div class="dots grey"></div>
                    </div>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'right-choice-section': RightChoiceSection;
    }
}
