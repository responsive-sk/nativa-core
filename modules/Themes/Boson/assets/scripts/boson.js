/**
 * boson.js — the boson theme's behaviour script, inlined by pageWrap().
 *
 * The reference shipped these as Lit elements (`boson-header`,
 * `horizontal-accordion`, `slider-component`, a highlighter package). This
 * port keeps the behaviour and drops the runtime: four self-gating inits,
 * ES2015-safe for the Chrome 70 baseline, and nothing to download.
 *
 *   - header   — adds `bo-header--scrolled` once the page scrolls;
 *   - menu     — syncs the checkbox menu's scroll lock, Escape and outside
 *                click (the open state itself is CSS, so it works with this
 *                file missing);
 *   - accordion — moves the open item and keeps `aria-expanded` honest;
 *   - code      — tokenizes `pre code.language-*` into `tok-*` spans, adds a
 *                language badge and a copy button.
 *
 * Every init returns early when its markup is absent, so a blog article runs
 * the highlighter and nothing else.
 */
(function () {
    'use strict';

    function ready(fn) {
        if ('loading' === document.readyState) {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    /* ---------------------------------------------------------------- header */

    function initHeader() {
        var header = document.querySelector('[data-bo-header]');

        if (!header) {
            return;
        }

        var tick = false;

        function sync() {
            var top = window.pageYOffset || document.documentElement.scrollTop || 0;
            header.classList.toggle('bo-header--scrolled', top > 0);
            tick = false;
        }

        function onScroll() {
            if (tick) {
                return;
            }

            tick = true;

            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(sync);
            } else {
                window.setTimeout(sync, 16);
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        sync();
    }

    /* ------------------------------------------------------------------ menu */

    function initMenu() {
        var input = document.getElementById('bo-nav-toggle');

        if (!input) {
            return;
        }

        function sync() {
            document.documentElement.classList.toggle('bo-nav-open', input.checked);
        }

        function close() {
            if (!input.checked) {
                return;
            }

            input.checked = false;
            sync();
            input.blur();
        }

        input.addEventListener('change', sync);

        document.addEventListener('keydown', function (event) {
            if ('Escape' === event.key || 27 === event.keyCode) {
                close();
            }
        });

        document.addEventListener('click', function (event) {
            if (!input.checked) {
                return;
            }

            var header = input.parentNode;

            if (header && !header.contains(event.target)) {
                close();
            }
        });

        sync();
    }

    /* ------------------------------------------------------------- accordion */

    function initAccordion() {
        var root = document.querySelector('[data-bo-accordion]');

        if (!root) {
            return;
        }

        var items = root.querySelectorAll('[data-bo-accordion-item]');

        function open(target) {
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var isTarget = item === target;
                var trigger = item.querySelector('.bo-accordion__trigger');

                item.classList.toggle('bo-accordion__item--open', isTarget);

                if (trigger) {
                    trigger.setAttribute('aria-expanded', isTarget ? 'true' : 'false');
                }
            }
        }

        for (var i = 0; i < items.length; i++) {
            (function (item) {
                var trigger = item.querySelector('.bo-accordion__trigger');

                if (!trigger) {
                    return;
                }

                trigger.addEventListener('click', function () {
                    if (item.classList.contains('bo-accordion__item--open')) {
                        return;
                    }

                    open(item);
                });
            })(items[i]);
        }
    }

    /* ------------------------------------------------------------------- toc */

    function initToc() {
        var links = document.querySelectorAll('[data-toc-link]');

        if (!links.length) {
            return;
        }

        var entries = [];

        for (var i = 0; i < links.length; i++) {
            var heading = document.getElementById(links[i].getAttribute('data-heading'));

            if (heading) {
                entries.push([heading, links[i]]);
            }
        }

        if (!entries.length) {
            return;
        }

        var groups = document.querySelectorAll('[data-toc-group]');

        // A group owns its heading and its sublinks. Walk up rather than
        // relying on a fixed depth, so the markup can nest the list deeper
        // without the rail silently losing its active section.
        function groupOf(link) {
            var node = link.parentNode;

            while (node && 1 === node.nodeType) {
                if (node.hasAttribute('data-toc-group')) {
                    return node;
                }

                node = node.parentNode;
            }

            return null;
        }

        var tick = false;

        function sync() {
            var header = document.querySelector('[data-bo-header]');
            var offset = (header ? header.getBoundingClientRect().height : 0) + 48;
            var active = null;

            for (var j = 0; j < entries.length; j++) {
                if (entries[j][0].getBoundingClientRect().top <= offset) {
                    active = entries[j][1];
                }
            }

            // Before the first heading passes the header there is no current
            // section yet; the first entry is the one the reader is heading
            // into, so it starts lit instead of leaving the rail blank.
            if (null === active) {
                active = entries[0][1];
            }

            for (var g = 0; g < groups.length; g++) {
                groups[g].removeAttribute('data-toc-active');
            }

            for (var k = 0; k < entries.length; k++) {
                var isActive = entries[k][1] === active;

                entries[k][1].classList.toggle('bo-toc__link--active', isActive);

                // The class paints it; aria-current is what a screen reader
                // announces. Keep the two in step rather than styling a state
                // nothing exposes semantically.
                if (isActive) {
                    entries[k][1].setAttribute('aria-current', 'true');

                    // Only the section being read unfolds its sublinks.
                    var group = groupOf(entries[k][1]);

                    if (group) {
                        group.setAttribute('data-toc-active', '');
                    }
                } else {
                    entries[k][1].removeAttribute('aria-current');
                }
            }

            tick = false;
        }

        function onScroll() {
            if (tick) {
                return;
            }

            tick = true;

            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(sync);
            } else {
                window.setTimeout(sync, 16);
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        sync();
    }

    /* --------------------------------------------------------------- code UI */

    var PHP = [
        [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^#[^\n]*/, 'comment'],
        [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
        [/^\$\w+/, 'variable'],
        [/^(?:abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield)\b/, 'keyword'],
        [/^(?:int|float|string|bool|array|object|callable|iterable|null|void|never|self|parent)\b/, 'type'],
        [/^(?:true|false|null)\b/, 'keyword'],
        [/^\d+\.?\d*/, 'number'],
        [/^->|::|\?->|\?\?/, 'operator'],
        [/^[a-zA-Z_]\w*(?=\s*\()/, 'function'],
        [/^[A-Z]\w*/, 'type'],
        [/^[a-zA-Z_]\w*/, 'property'],
        [/^[{}()\[\];,.:]/, 'punctuation'],
        [/^[ \t]+/, '']
    ];

    var JS = [
        [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'],
        [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^`(?:[^`\\]|\\.)*`/, 'string'],
        [/^(?:import|export|from|default|const|let|var|function|class|extends|implements|interface|type|enum|namespace|declare|abstract|as|async|await|new|return|if|else|for|while|do|switch|case|break|continue|throw|try|catch|finally|typeof|instanceof|in|of|yield|this|super|static|public|private|protected|readonly|get|set|never|unknown|any|void|null|undefined|true|false)\b/, 'keyword'],
        [/^\d+\.?\d*/, 'number'],
        [/^[a-zA-Z_]\w*(?=\s*[<(])/, 'function'],
        [/^[A-Z]\w*/, 'type'],
        [/^[a-zA-Z_]\w*/, 'property'],
        [/^[{}()\[\];,.:!?=<>+\-*/%&|^~]+/, 'operator'],
        [/^[ \t]+/, '']
    ];

    var JSON_RULES = [
        [/^"(?:[^"\\]|\\.)*"(?=\s*:)/, 'property'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
        [/^-?\d+\.?\d*(?:[eE][+-]?\d+)?/, 'number'], [/^(?:true|false|null)\b/, 'keyword'],
        [/^[{}()\[\]:,]/, 'punctuation'], [/^[ \t]+/, '']
    ];

    var BASH = [
        [/^#[^\n]*/, 'comment'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
        [/^\$\{[^}]+\}/, 'variable'], [/^\$\w+/, 'variable'],
        [/^(?:if|then|else|elif|fi|for|while|do|done|case|esac|function|return|exit|local|export|source|set|unset|shift|declare|readonly|eval|exec|trap|wait|kill|cd|pwd|echo|printf|read|test|true|false|break|continue|in|select)\b/, 'keyword'],
        [/^\d+/, 'number'], [/^[a-zA-Z_]\w*/, 'function'], [/^[|&;<>]+/, 'operator'],
        [/^[ \t]+/, ''], [/^[^\s#'"';|&<>]+/, 'string']
    ];

    var SQL = [
        [/^--[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
        [/^\d+\.?\d*/, 'number'],
        [/^(?:SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|DROP|ALTER|INDEX|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AND|OR|NOT|IN|EXISTS|BETWEEN|LIKE|IS|NULL|AS|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|COUNT|SUM|AVG|MIN|MAX|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|PRIMARY|KEY|FOREIGN|REFERENCES|CONSTRAINT|DEFAULT|INTEGER|VARCHAR|TEXT|BOOLEAN|DATE|TIMESTAMP)\b/i, 'keyword'],
        [/^[a-zA-Z_]\w*/, 'property'], [/^[{}()\[\];,.:*/+=<>'\-]+/, 'operator'], [/^[ \t]+/, '']
    ];

    var YAML = [
        [/^#[^\n]*/, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
        [/^\d+\.?\d*/, 'number'], [/^(?:true|false|null|yes|no|on|off)\b/, 'keyword'],
        [/^[a-zA-Z_][\w.-]*(?=\s*:)/, 'property'], [/^-[ \t]+/, 'punctuation'],
        [/^[{}()\[\],&*?|>!]/, 'punctuation'], [/^[ \t]+/, ''],
        [/^[^\s#'"{}()\[\],&*?|>!:-]+/, 'string']
    ];

    var HTML = [
        [/^<!--[\s\S]*?-->/, 'comment'],
        [/^<![a-zA-Z][\s\S]{0,60}/, 'keyword'],
        [/^<\/?[a-zA-Z][\w-]*/, 'tag'],
        [/^[a-zA-Z][\w-]*(?=\s*=)/, 'property'],
        [/^=/, 'punctuation'],
        [/^"[^"]*"/, 'string'], [/^'[^']*'/, 'string'],
        [/^&[a-zA-Z]+;/, 'number'], [/^[ \t]+/, ''],
        [/^[^<]+/, '']
    ];

    var RULES = {
        php: PHP,
        js: JS,
        javascript: JS,
        ts: JS,
        typescript: JS,
        json: JSON_RULES,
        bash: BASH,
        sh: BASH,
        shell: BASH,
        sql: SQL,
        yaml: YAML,
        yml: YAML,
        html: HTML,
        xml: HTML
    };

    var PLAIN = ' plain plaintext text console terminal output log ';

    function esc(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function tokenize(code, rules) {
        var html = '';
        var rest = code;

        while (rest.length > 0) {
            var matched = false;

            for (var i = 0; i < rules.length; i++) {
                var m = rest.match(rules[i][0]);

                if (null !== m && 0 === m.index) {
                    html += '' !== rules[i][1]
                        ? '<span class="tok-' + rules[i][1] + '">' + esc(m[0]) + '</span>'
                        : esc(m[0]);
                    rest = rest.slice(m[0].length);
                    matched = true;
                    break;
                }
            }

            if (!matched) {
                html += esc(rest.charAt(0));
                rest = rest.slice(1);
            }
        }

        return html;
    }

    function highlight() {
        var blocks = document.querySelectorAll('pre code[class*="language-"]');

        for (var i = 0; i < blocks.length; i++) {
            var code = blocks[i];

            if (code.getAttribute('data-highlighted')) {
                continue;
            }

            var lang = (code.className.match(/language-([^\s]+)/) || [])[1] || '';
            var rules = RULES[lang.toLowerCase()];

            code.innerHTML = rules ? tokenize(code.textContent || '', rules) : esc(code.textContent || '');
            code.setAttribute('data-highlighted', 'true');
        }
    }

    function badge(lang) {
        var span = document.createElement('span');
        span.className = 'bo-code-badge';
        span.textContent = lang.toUpperCase();

        return span;
    }

    function copyButton(code) {
        var button = document.createElement('button');
        button.className = 'bo-copy';
        button.type = 'button';
        button.textContent = 'Copy';

        button.addEventListener('click', function () {
            var text = code.textContent || '';
            var done = function () {
                button.textContent = 'Copied';
                button.classList.add('bo-copy--done');
                window.setTimeout(function () {
                    button.textContent = 'Copy';
                    button.classList.remove('bo-copy--done');
                }, 2000);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    fallbackCopy(text);
                    done();
                });
            } else {
                fallbackCopy(text);
                done();
            }
        });

        return button;
    }

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        try {
            document.execCommand('copy');
        } catch (e) {
            /* clipboard unavailable — the button still confirms the attempt */
        }

        document.body.removeChild(area);
    }

    function initCode() {
        var pres = document.querySelectorAll('pre');

        for (var i = 0; i < pres.length; i++) {
            var pre = pres[i];
            var code = pre.querySelector('code');

            if (!code) {
                continue;
            }

            var lang = (code.className.match(/language-([^\s]+)/) || [])[1] || '';

            if ('' !== lang && PLAIN.indexOf(' ' + lang.toLowerCase() + ' ') < 0
                && !pre.querySelector('.bo-code-badge')) {
                pre.insertBefore(badge(lang), pre.firstChild);
                pre.classList.add('bo-has-badge');
            }

            if (!pre.querySelector('.bo-copy')) {
                pre.appendChild(copyButton(code));
            }
        }
    }

    ready(function () {
        initHeader();
        initMenu();
        initAccordion();
        initToc();
        highlight();
        initCode();
    });
})();
