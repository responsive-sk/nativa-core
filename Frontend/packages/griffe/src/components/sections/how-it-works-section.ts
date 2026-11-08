import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('how-it-works-section')
export class HowItWorksSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            display: flex;
            flex-direction: column;
            gap: 4em;
        }

        .content {
            display: flex;
            padding: 1px 0;
            border-bottom: 1px solid var(--color-border);
            border-top: 1px solid var(--color-border);
        }

        .dots {
            min-width: 120px;
        }

        .content .dots:nth-child(1) {
            border-right: 1px solid var(--color-border);
        }

        .inner {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        @media (orientation: portrait) {
            .dots {
                display: none;
            }
        }
    `];

    get content() {
        return [{
            headline: 'Saucer: The Core of Performance',
            text: 'At the heart of Boson PHP is saucer, a fast cross-platform ' +
                'C++ library. It allows us to create applications with minimal ' +
                'size and resource consumption, significantly outperforming ' +
                'Electron in terms of performance.',
        }, {
            headline: 'Direct OS API calls',
            text: 'Instead of emulating behavior through multiple external ' +
                'layers like a browser, server, and sockets, we use direct ' +
                'access to the operating system API, just like any existing ' +
                'system language does.',
        }, {
            headline: 'On the edge of PHP',
            text: 'Boson is built on the basis of advanced architectural ' +
                'approaches and functionality provided by the most modern ' +
                'versions of PHP. No outdated approaches of large frameworks ' +
                'for the sake of backward compatibility.',
        }, {
            headline: 'Kernel Optimizations',
            text: 'The kernel is written in such a way as to provide maximum ' +
                'performance without limitations in functionality. Numerous ' +
                'PHP OPCode and JIT optimizations ensure that there are no ' +
                'dubious or slow solutions.',
        }, {
            headline: 'Fiber-Based Life Cycle',
            text: 'Using Revolt EventLoop and painless cooperative multitasking ' +
                'ensures high performance and ease of use. Why wait? Use the ' +
                'green threads today!',
        }];
    }

    render() {
        return html`
            <section class="container">
                <div class="content">
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                    <div class="inner">
                        <horizontal-accordion .content=${this.content}></horizontal-accordion>
                    </div>
                    <div class="dots">
                        <dots-container></dots-container>
                    </div>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'how-it-works-section': HowItWorksSection;
    }
}
