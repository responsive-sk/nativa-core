import { css, html, LitElement } from 'lit';
import { customElement } from 'lit/decorators.js';

import { sharedStyles } from "@/utils/sharedStyles.js";

@customElement('testimonials-section')
export class TestimonialsSection extends LitElement {
    static styles = [sharedStyles, css`
        .container {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 6em;
        }

        .headline {
            text-align: center;
        }

        .container:before {
            content: '';
            position: absolute;
            pointer-events: none;
            background: radial-gradient(50% 50% at 50% 50%, var(--color-text-brand) 0%, var(--color-bg-layer-hover) 50%);
            opacity: 0.3;
            inset: 0;
            filter: blur(140px);
            z-index: -1;
        }
    `];

    get slides() {
        return [
            {
                name: "Aleksei Gagarin",
                pfp: "/images/u/roxblnfk.png",
                role: "Maintainer of Spiral, Cycle, RoadRunner PHP",
                comment: "Finally, genuine native PHP - exactly as it should be."
            },
            {
                name: "Sergey Panteleev",
                pfp: "/images/u/saundefined.png",
                role: "PHP Release Manager",
                comment: "Every year, PHP and its ecosystem get better, partly" +
                    "thanks to projects that bring something new to PHP.\n" +
                    "I like how fast it is, how user-friendly it is, and its " +
                    "huge potential for cross-platform applications.\n\n" +
                    "I’ll be following the development of Boson."
            },
            {
                name: "Valentin Udaltsov",
                pfp: "/images/u/vudaltsov.png",
                role: "OSS contributor, Speaker, Author of PHPyh",
                comment: "As the author of open-source tools for PHP, I see " +
                    "Boson as an invaluable companion for handling input/output " +
                    "in PHP tooling. Instead of writing temporary HTML files or " +
                    "spinning up a web server, you simply pass your data to a" +
                    " Boson process — and boom, you’ve got a window with debug " +
                    "information, errors, metrics, whatever. The best part is " +
                    "that it’s all PHP — no need to learn anything else."
            },
            {
                name: "Danil Shutsky",
                pfp: "/images/u/lee-to.png",
                role: "CutCode, Moonshine",
                comment: "I've been following NativePHP since its announcement " +
                    "at Laracon, but the release ultimately disappointed me " +
                    "with its slow performance and bulkiness. Boson turned " +
                    "out to be the complete opposite: fast, lightweight, and " +
                    "most importantly — it actually works."
            },
            {
                name: "Roman Pronskiy",
                pfp: "/images/u/pronskiy.png",
                role: "PhpStorm team, The PHP Foundation founder",
                comment: "I built a few production apps with Electron before. " +
                    "It has a big ecosystem, but I always missed PHP. The PHP " +
                    "wrappers around Electron felt limited and slow. When I " +
                    "first tried Boson, I thought, wow, is this a mistake? " +
                    "Why is it so fast? I really like the simple API and the " +
                    "smart design under the hood. This feels like the PHP " +
                    "way. Love it."
            },
            {
                name: "Pavel Buchnev",
                pfp: "/images/u/butschster.png",
                role: "CTO at Intruforce, Spiral Framework Maintainer",
                comment: "Recently, I needed to build a desktop application. " +
                    "The only tools I had at hand were PHP and Spiral, and " +
                    "honestly, I didn’t feel like diving into something " +
                    "completely new — I wanted some real hardcore PHP. Then " +
                    "I remembered that Kirill is developing Boson and thought " +
                    "it was the perfect time to give it a try.\n\n" +
                    "It integrated with Spiral seamlessly, like it was made " +
                    "for it. And now—I actually have a desktop application " +
                    "running on PHP. I couldn’t be happier."
            },
            {
                name: "Curve (Noah)",
                pfp: "/images/u/curve.png",
                role: "Developer of Saucer",
                comment: "I'm very glad to see projects based on Saucer " +
                    "bindings, and I'm especially excited for Boson as it " +
                    "looks very promising and professionally made, all the best!"
            },
        ];
    }

    get slidesInRandomOrder() {
        let array = this.slides;

        for (let i = array.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [array[i], array[j]] = [array[j], array[i]];
        }

        return array;
    }

    render() {
        return html`
            <section class="container">
                <div class="content">
                    <slider-component .slides=${this.slidesInRandomOrder}></slider-component>
                </div>
            </section>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'testimonials-section': TestimonialsSection;
    }
}
