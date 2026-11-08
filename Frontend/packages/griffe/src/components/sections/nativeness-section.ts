import { css, html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import { sharedStyles } from '@/utils/sharedStyles';

@customElement('nativeness-section')
export class NativenessSection extends LitElement {
    @property({ type: Number })
    activeIndex: number = 0;

    private _intervalId: number | null = null;

    static cfg = {
        delay: 2000 // 2 seconds
    };

    static styles = [sharedStyles, css`
        .container {
            display: flex;
            flex-direction: column;
            margin-bottom: 2em;
        }

        .content {
            display: flex;
            justify-content: center;
            align-items: center;
            align-self: stretch;
            flex-direction: column;
            margin-top: 8em;
        }

        .wtf {
            display: flex;
            align-self: stretch;
        }

        .edge {
            flex: 3;
            border: none !important;
        }

        .full {
            flex: 4;
        }

        .half {
            flex: 2;
        }

        .wtf > div {
            height: 100px;
            border: 1px dashed var(--color-border);
            border-bottom: none;
            transition: 0.3s ease-in-out;
        }

        .icon {
            height: 128px;
            width: 128px;
            background: url("/images/icon.svg") center center no-repeat;
            background-size: contain;
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .icon::before {
            z-index: 1;
            position: absolute;
            content: "";
            width: 640px;
            height: 640px;
            background: radial-gradient(50% 50% at 50% 50%, var(--color-text-brand) 0%, var(--color-bg) 100%);
            opacity: 0.1;
        }

        .border-top {
            border-left: 1px dashed var(--color-text-brand);
            height: 100px;
        }

        #border-1 {
            border-right: none;
        }

        #border-2 {
            border-right: none;
        }

        #border-3 {
            border-left: none;
        }

        #border-4 {
            border-left: none;
        }

        .systems {
            display: flex;
            align-self: stretch;
        }

        .system {
            transition: 0.3s ease-in-out;
            position: relative;
            height: 50px;
            flex: 8;
            border: 1px solid var(--color-border);
            border-right: none;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 1.5em;
            padding: 5em 0;
            background: var(--color-bg);
            font-family: var(--font-title), sans-serif;
            color: var(--color-text-secondary);
        }

        .system::before {
            position: absolute;
            transition: 0.3s ease-in-out;
            content: '';
            z-index: 1;
            inset: -1px;
            pointer-events: none;
            border: 1px dashed transparent;
        }

        #system-4 {
            border-right: 1px solid var(--color-border);
        }
        .system-edge {
            flex: 2;
            border-top: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
            background: var(--color-bg);
        }

        .system-active {
            border-color: transparent;
            color: var(--color-text);
        }

        .system-active::before {
            border: 1px dashed var(--color-text-brand);
        }

        .border-active {
            border-color: var(--color-text-brand) !important;
        }

        .border-top-active {
            border-top-color: var(--color-text-brand) !important;
        }

        .logo {
            min-height: 38px;
            max-height: 38px;
            width: 38px;
            background-position: center center;
            background-repeat: no-repeat;
            background-size: contain;
        }

        #system-1 > .logo {
            background-image: url('/images/icons/windows.svg');
        }

        #system-2 > .logo {
            background-image: url('/images/icons/linux.svg');
        }

        #system-3 > .logo {
            background-image: url('/images/icons/apple.svg');
        }

        #system-4 > .logo {
            background-image: url('/images/icons/freebsd.svg');
        }

        .name {
            text-transform: uppercase;
        }

        .technology-edge {
            flex: 3;
        }
        .technologies {
            display: flex;
            align-self: stretch;
            position: relative;
        }

        .technology {
            flex: 16;
            display: flex;
            position: relative;
        }
        .sticky {
            height: 450px;
            flex-direction: column;
            position: sticky;
            border: 1px solid var(--color-border);
            border-right: none;
            border-top: none;
            background: var(--color-bg);
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 5em;
            gap: 1.5em;
        }
        .technologies > .technology:nth-child(1) {
            flex-direction: column;
        }
        .technologies > .technology:nth-child(2) {
            flex-direction: column-reverse;
            height: 600px;
        }
        .technologies > .technology:nth-child(3) {
            flex-direction: column-reverse;
            height: 750px;
        }
        .technologies > .technology:nth-child(1) > .sticky {
        }
        .technologies > .technology:nth-child(2) > .sticky {
            bottom: 0;
        }
        .technologies > .technology:nth-child(3) > .sticky {
            bottom: 0;
        }
        .dots-container {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 150px;
            border-left: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
        }
        .tech-logo {
            min-height: 64px;
            max-height: 64px;
            min-width: 250px;
            max-width: 250px;
            background-position: center center;
            background-size: contain;
            background-repeat: no-repeat;
        }
        .tech-name {
            text-transform: uppercase;
            color: var(--color-text);
        }
        .tech-description {
            color: var(--color-text-secondary);
        }
        #technology-1 > .sticky > .tech-logo {
            background-image: url("/images/icons/php.svg");
        }
        #technology-2 > .sticky > .tech-logo {
            background-image: url("/images/icons/laravel.svg");
        }
        #technology-3 > .sticky > .tech-logo {
            background-image: url("/images/icons/symfony.svg");
        }
        @media (orientation: portrait) {
            .top {
                flex-direction: column;
                margin: 0 1em;
            }
            .system-edge {
                display: none;
            }
            .border-top {
                border-color: var(--color-border) !important;
            }
            .full {
                border-color: var(--color-border) !important;
            }
            .half {
                display: none;
                border-color: var(--color-border) !important;
            }
            .half {
                border-left-color: transparent !important;
                border-right-color: transparent !important;
            }
            .systems {
                flex-wrap: wrap;
            }
            .systems > div {
                flex: 34%;
            }
            .technologies {
                flex-direction: column;
            }
            .icon::before {
                width: 95vw;
            }
        }
    `];

    connectedCallback(): void {
        super.connectedCallback();
        this._startAnimation();
    }

    disconnectedCallback(): void {
        super.disconnectedCallback();
        this._stopAnimation();
    }

    private _startAnimation(): void {
        this._intervalId = window.setInterval(() => {
            this.activeIndex = this.activeIndex === 4 ? 1 : this.activeIndex + 1;
        }, NativenessSection.cfg.delay);
    }

    private _stopAnimation(): void {
        if (this._intervalId) {
            clearInterval(this._intervalId);
            this._intervalId = null;
        }
    }

    private _getBorderClass(borderNumber: number): string {
        const classes: string[] = [];

        if (this.activeIndex === borderNumber) {
            classes.push('border-active');
        }

        // Special cases for border-top
        if (this.activeIndex === 1 && borderNumber === 2) {
            classes.push('border-top-active');
        }
        if (this.activeIndex === 4 && borderNumber === 3) {
            classes.push('border-top-active');
        }

        return classes.join(' ');
    }

    private _getSystemClass(systemNumber: number): string {
        return this.activeIndex === systemNumber ? 'system system-active' : 'system';
    }

    render() {
        return html`
            <section class="container">
                <div class="content">
                    <div class="icon"></div>
                    <div class="border-top"></div>
                    <div class="wtf">
                        <div class="edge"></div>
                        <div id="border-1" class="full ${this._getBorderClass(1)}"></div>
                        <div id="border-2" class="half ${this._getBorderClass(2)}"></div>
                        <div id="border-3" class="half ${this._getBorderClass(3)}"></div>
                        <div id="border-4" class="full ${this._getBorderClass(4)}"></div>
                        <div class="edge"></div>
                    </div>
                    <div class="systems">
                        <div class="system-edge"></div>
                        <div id="system-1" class="${this._getSystemClass(1)}">
                            <div class="logo"></div>
                            <span class="name">Windows</span>
                        </div>
                        <div id="system-2" class="${this._getSystemClass(2)}">
                            <div class="logo"></div>
                            <span class="name">Linux</span>
                        </div>
                        <div id="system-3" class="${this._getSystemClass(3)}">
                            <div class="logo"></div>
                            <span class="name">macOS</span>
                        </div>
                        <div id="system-4" class="${this._getSystemClass(4)}">
                            <div class="logo"></div>
                            <span class="name">BSD</span>
                        </div>
                        <div class="system-edge"></div>
                    </div>
                    <div class="technologies">
                        <div class="technology" id="technology-1">
                            <div class="sticky">
                                <div class="tech-logo"></div>
                                <h6 class="tech-name">
                                    Do you write in pure PHP?
                                </h6>
                                <span class="tech-description">
                                    Boson loves it too!
                                </span>
                            </div>
                        </div>
                        <div class="technology" id="technology-2">
                            <div class="dots-container"><dots-container></dots-container></div>
                            <div class="sticky">
                                <div class="tech-logo"></div>
                                <h6 class="tech-name">
                                    Do you work with Laravel?
                                </h6>
                                <span class="tech-description">
                                    Use familiar Blade, Livewire, Inertia or
                                    Eloquent for UI and logic. Your routes and
                                    controllers work just like on the web.
                                </span>
                            </div>
                        </div>
                        <div class="technology" id="technology-3">
                            <div class="dots-container"><dots-container></dots-container></div>
                            <div style="top: 150px" class="dots-container"><dots-container></dots-container></div>
                            <div class="sticky">
                                <div class="tech-logo"></div>
                                <h6 class="tech-name">
                                    Do you prefer Symfony or Yii?
                                </h6>
                                <span class="tech-description">
                                    Just plug in Boson. Your components and
                                    services are ready to work in Desktop application.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'nativeness-section': NativenessSection;
    }
}