<?php

declare(strict_types = 1);

namespace Modules\Themes\Mono\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Themes\Mono\Theme\MonoTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MonoThemeTest (Boot 25) — the mobile meatball, and the contract that makes it
 * work without a line of JavaScript.
 *
 * The vendored mono snapshot hides `.mo-navbar__menu` below 768px and reopens
 * it by adding `mo-navbar--menu-open` from the live site's JS. This tree ships
 * no JS, so the menu was unreachable on a phone. Boot 23 answered that by
 * showing the strip inline always; the answer now is the theme's own meatball,
 * driven by a checkbox (`:checked`), in the idiom the shared layout already uses
 * for the starter's nav and Erik repeats in `er-nav__toggle-input`.
 *
 * That mechanism rests on DOM ORDER: one `~` sibling selector has to reach both
 * the menu and the label from the input, which only works while the input comes
 * first and the label comes after the menu. Nothing in a stylesheet enforces
 * that, so this file does — the day someone moves the toggle next to the brand,
 * the menu silently stops opening, and these assertions say why.
 */
final class MonoThemeTest extends TestCase
{
    private const string MODULE = 'Mono';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `mono` theme ships with this module.');
        }
    }

    #[Test]
    public function theMeatballAndTheCheckboxItDrivesAreRenderedForAHeaderWithItems(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('type="checkbox"', $header);
        $this->assertStringContainsString('id="mo-nav-toggle"', $header, 'The label needs an input id to target.');
        $this->assertStringContainsString('class="mo-navbar__toggle-input"', $header);
        $this->assertStringContainsString('aria-label="Open navigation"', $header, 'The hidden control must still be named.');
        $this->assertStringContainsString('for="mo-nav-toggle"', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--meatball', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--close', $header, 'Both icons ship; CSS shows one at a time.');
    }

    /**
     * The `~` contract: input, then the menu, then the label that activates it.
     */
    #[Test]
    public function theToggleSitsBeforeTheMenuItOpens(): void
    {
        $header = self::headerWithItems();

        $input = strpos($header, 'id="mo-nav-toggle"');
        $menu = strpos($header, 'class="mo-navbar__menu"');
        $label = strpos($header, 'for="mo-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($menu);
        $this->assertIsInt($label);
        $this->assertLessThan($menu, $input, 'The input must precede the menu: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $menu, 'The label must follow the menu, inside the actions cluster.');
    }

    /**
     * A toggle that opens nothing is worse than no toggle — and its label would
     * dangle over an id the document does not contain.
     */
    #[Test]
    public function aHeaderWithoutItemsRendersNoToggleAtAll(): void
    {
        $context = ShellContext::from('Mono', [], [], '/', null, 'sk', [], false);

        $header = (new MonoTheme())->header($context);

        $this->assertStringNotContainsString('mo-navbar__toggle', $header);
        $this->assertStringNotContainsString('mo-navbar__menu', $header);
        $this->assertStringContainsString('mo-navbar__brand', $header, 'The brand is always there.');
    }

    /**
     * The open state is a checkbox, not a class some script has to add.
     */
    #[Test]
    public function theStylesheetKeysTheOpenStateOffChecked(): void
    {
        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        $this->assertStringContainsString('.mo-navbar__toggle-input:checked~.mo-navbar__menu', $css);
        $this->assertStringContainsString('.mo-navbar__toggle-input{height:1px;opacity:0;position:absolute;width:1px}', $css);
        $this->assertStringNotContainsString(
            'max-width:767px){.mo-navbar__menu{gap:var(--space-4);overflow-x:auto',
            $css,
            'The always-visible strip was replaced by the toggle — a leftover copy would hide the meatball’s effect.',
        );
    }

    /**
     * The token palette the client-side highlighter needs: without these
     * rules mo-code-highlight.js still runs, but every span it emits is
     * invisible — one colour either way, and nothing says why.
     */
    #[Test]
    public function theStylesheetCarriesTokenColorsForHighlightedBlocks(): void
    {
        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        foreach (['keyword', 'string', 'comment', 'number', 'function', 'variable', 'type'] as $token) {
            $this->assertStringContainsString(
                'main pre .tok-' . $token,
                $css,
                'The token palette is missing .' . $token . ' — highlighted blocks fall back to one colour.',
            );
        }

        $this->assertStringContainsString(
            '[data-theme=light] main pre .tok-keyword',
            $css,
            'The light variant needs its own token colours — the dark palette on white fails contrast.',
        );

        $this->assertStringContainsString(
            'main pre .mo-code-badge',
            $css,
            'The language badge needs positioning — without it the badge overlaps the code.',
        );
    }

    /**
     * pageWrap ships a bare `<main>`: the highlighter, scrollspy and variant
     * switcher twins moved into the frozen `mono` bundle FrontendLayout
     * defers, each init self-gating on its markup. The gating (fences, TOC
     * aside, navbar buttons) now lives in TypeScript, not PHP.
     */
    #[Test]
    public function pageWrapShipsNoInlineScripts(): void
    {
        $theme = new MonoTheme();

        $fenced = $theme->pageWrap('<pre><code class="language-php">echo 1;</code></pre>');

        $this->assertSame(1, substr_count($fenced, '<main>'), 'One wrap, one <main> — no nested mains.');
        $this->assertStringNotContainsString('<script', $fenced, 'No inline scripts — highlight, scrollspy and switcher run from the bundle.');
        $this->assertStringNotContainsString('mo-code-highlight.js', $fenced);

        $plain = $theme->pageWrap('<p>no code here</p>');

        $this->assertStringNotContainsString('<script', $plain, 'Even fenced pages ship no inline highlighter.');
    }

    #[Test]
    public function renderedDocumentDefersTheFrozenBundle(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: 'mono');

        $this->assertStringContainsString(
            '<script src="/dist/mono/',
            $html,
            'The document must defer the frozen bundle — without it mono pages keep the critical CSS but lose every behaviour.',
        );
        $this->assertStringContainsString('defer></script>', $html, 'The bundle script stays deferred, never render-blocking.');
    }

    /**
     * The dev variant switcher: upstream-ids, palettes in the stylesheet,
     * pre-paint restore in the init script.
     */
    #[Test]
    public function pageWrapLoadsTheVariantSwitcherWithUpstreamIds(): void
    {
        $theme = new MonoTheme();

        $header = self::headerWithItems();

        $this->assertStringContainsString('id="dev-theme-switcher"', $header);
        $this->assertStringContainsString('id="dev-variant-switcher-2"', $header);
        $this->assertStringContainsString('data-variant-toggle="ember"', $header);
        $this->assertStringContainsString('data-variant-toggle="glacier"', $header);
        $this->assertStringContainsString('aria-pressed="false"', $header, 'Static markup cannot know the stored choice — the tracker syncs on load.');

        $toggle = (int) strpos($header, 'data-theme-toggle');
        $ember = (int) strpos($header, 'id="dev-theme-switcher"');
        $glacier = (int) strpos($header, 'id="dev-variant-switcher-2"');
        $meatball = (int) strpos($header, 'mo-navbar__toggle-icon--meatball');
        $this->assertLessThan($ember, $toggle, 'Upstream order: dark/light toggle first, then the variant buttons.');
        $this->assertLessThan($glacier, $ember, 'Ember keeps the upstream id, so it stays the first variant.');
        $this->assertLessThan($meatball, $glacier, 'The meatball stays the final control — the variant buttons sit before it.');

        $css = InlineCss::file('Modules/Mono/assets/critical/mo-variants.css');

        $this->assertStringContainsString('html[data-variant="ember"]', $css);
        $this->assertStringContainsString('html[data-variant="glacier"]', $css);
        $this->assertStringNotContainsString('@import', $css, 'Vendored palettes must not pull external dependencies.');

        $init = $theme->themeInitScript();

        $this->assertStringContainsString('nativa-variant', $init, 'The persisted variant must restore pre-paint, or the page flashes default.');
        $this->assertStringContainsString('nativa-theme', $init, 'The override reproduces the shared dark/light restore — it replaces it, not appends.');
        $this->assertStringContainsString("classList.add('mo-js')", $init, 'The multi-slider stages pre-paint — without mo-js the opening frame stacks.');
        $this->assertStringContainsString('prefers-reduced-motion', $init, 'Reduced motion keeps the stacked calm fallback.');
    }

    #[Test]
    public function theRenderedDocumentSignalsJsBeforeFirstPaint(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: 'mono');
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringContainsString("classList.add('mo-js')", $head, 'The staging signal must run synchronously in <head>, before first paint.');
    }

    #[Test]
    public function theMultiSliderOptsIntoPrePaintStaging(): void
    {
        $multi = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString(
            '<section class="mo-hero-slider mo-hero-slider--multi"',
            $multi,
            '2+ slides stage before first paint; without --multi the opening frame stacks.',
        );
    }

    #[Test]
    public function stagingOptInFollowsTheSlideCount(): void
    {
        // No articles passed: the theme falls back to published ones, so the
        // count — and therefore the opt-in — follows whatever storage holds.
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'mono',
            'en',
            copy: ['title' => 'Hero'],
        );

        $html = (new MonoTheme())->homepage($context);
        $count = substr_count($html, '<li class="mo-slide');

        if ($count >= 2) {
            $this->assertStringContainsString('mo-hero-slider--multi', $html);
        } else {
            $this->assertStringNotContainsString('mo-hero-slider--multi', $html, 'One slide reads the same stacked or staged — no opt-in, no surprise.');
        }
    }

    #[Test]
    public function theHomepageIsASliderWithOneSlidePerArticlePlusHero(): void
    {
        $html = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertSame(3, substr_count($html, '<li class="mo-slide'), 'Hero slide + 2 case slides.');
        $this->assertSame(1, substr_count($html, 'mo-slide--active'), 'Slide 1 is active server-side.');
        $this->assertSame(3, substr_count($html, '<button type="button" class="mo-pag'), 'One pagination button per slide.');
        $this->assertStringContainsString('aria-current="true"', $html);
        $this->assertSame(1, substr_count($html, 'mo-slider__arrow--prev'));
        $this->assertSame(1, substr_count($html, 'mo-slider__arrow--next'));
        $this->assertStringContainsString('data-mo-autoplay="6000"', $html);
        $this->assertStringContainsString('aria-roledescription="carousel"', $html, 'A rotating stage announces itself as a carousel.');
    }

    /**
     * The kernel HomepageAction passes no articles (no Blog import by explicit
     * contract), so the theme falls back to the latest published ones itself —
     * guarded: without Blog the hero stands alone, exactly as before. The
     * expectation is computed from the same source the theme reads, so the
     * test holds whatever storage publishes (including nothing).
     */
    #[Test]
    public function homepageWithoutPassedArticlesFallsBackToPublishedOnes(): void
    {
        $facade = 'Modules\\Blog\\Application\\BlogServices';

        $context = PageContext::forHomepage(Request::create('GET', '/'), 'mono', 'en_US', copy: ['title' => 'Hero']);

        $html = (new MonoTheme())->homepage($context);

        if (!class_exists($facade)) {
            $this->assertSame(1, substr_count($html, '<li class="mo-slide'), 'The hero slide renders with no articles.');
            $this->assertStringNotContainsString('mo-slider__pag', $html, 'No pagination for a single slide.');
            $this->assertStringNotContainsString('mo-slider__arrow', $html, 'No arrows for a single slide.');

            return;
        }

        $expected = $facade::articles()->latestCards(6, 'en');

        $this->assertSame(1 + count($expected), substr_count($html, '<li class="mo-slide'), 'Hero slide plus one case slide per published article.');

        if ([] === $expected) {
            return;
        }

        foreach ($expected as $card) {
            $this->assertStringContainsString('/blog/' . $card['slug'], $html);
        }
    }

    /**
     * The home surface's OWN keys win over the site name and over the theme's
     * defaults, because those are the rows the translation admin edits.
     *
     * Measured 2026-10-17 with the site switched to `mono`: the hero `<h1>`
     * answered the SITE NAME (the kernel's `title`) and the button `Discover
     * more`, while the same database held `Postavte Niečo Skvelé` and `Začať`
     * for `home.title` and `home.cta_start`. The case slide's link label went
     * the same way — `home.read_article` (`Čítať článok`) sat unread beside a
     * hardcoded `Read More`.
     */
    #[Test]
    public function theHomeGroupKeysBeatTheSiteNameAndTheDefaults(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'mono',
            'sk_SK',
            copy: [
                'title'             => 'the site name',
                'home.title'        => 'Postavte Niečo Skvelé',
                'home.message'      => 'Malý a čitateľný základ.',
                'home.cta_start'    => 'Začať',
                'home.read_article' => 'Čítať článok',
            ],
            blogHref: '/blog',
            latestArticles: [self::article('alpha', 'Alpha Client')],
        );

        $html = (new MonoTheme())->homepage($context);

        // Asserted by VALUE here, not by the wrapper around it: the title's
        // markup is wrapped in `mo-anim__line` spans in the installed copy of
        // this theme and not in the committed catalogue one, and that drift is
        // not this test's subject. What it pins is the value the slot resolved
        // to, so it holds in both.
        $this->assertStringContainsString('Postavte Niečo Skvelé', $html, 'The hero title must resolve to home.title.');
        $this->assertStringContainsString('Malý a čitateľný základ.', $html, 'The lede must resolve to home.message.');
        $this->assertStringContainsString('>Začať</a>', $html, 'The hero button reads home.cta_start, not its own English label.');
        $this->assertStringContainsString('>Čítať článok →</a>', $html, 'The case slide label comes from home.read_article; the arrow stays markup.');
        $this->assertStringNotContainsString('Discover more', $html, 'The default must be shadowed, not merged with it.');
        $this->assertStringNotContainsString('Read More', $html, 'And so must the hardcoded link label it replaced.');
    }

    /**
     * ...and the floor is intact: no row for a key means the theme answers what
     * it answered before — the bare key when the caller passed one (the kernel
     * passes the site name as `title`), its own string when it did not.
     */
    #[Test]
    public function withoutTheGroupTheBareKeysAndTheDefaultsStillAnswer(): void
    {
        $html = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('Mono upstream', $html, 'The bare key still answers when the group carries nothing.');
        $this->assertStringContainsString('Business first.', $html);
        $this->assertStringContainsString('>Discover more</a>', $html, 'With no row for home.cta_start the theme\'s own label stands.');
        $this->assertStringContainsString('>Read More →</a>', $html, 'The case slide keeps its own label with no home.read_article row.');

        $bare = (new MonoTheme())->homepage(
            PageContext::forHomepage(Request::create('GET', '/'), 'mono', 'en_US'),
        );

        // The title's wrapper differs between the two copies of this theme, so
        // the floor is matched either way round: `>Home</h1>` in the committed
        // catalogue, `>Home</span>` in the installed one.
        $this->assertMatchesRegularExpression('~>Home</(?:span|h1)>~', $bare, 'With neither key, the theme\'s own string is the floor.');
        $this->assertStringContainsString('>Discover more</a>', $bare);
        $this->assertStringNotContainsString(
            'mo-slide__lede',
            $bare,
            'The lede floor is the empty string, so the paragraph is omitted rather than rendered empty.',
        );
    }

    /**
     * The group's rows are what the translation admin types, so they are user
     * input by definition and travel the same escaping path as the rest.
     *
     * The copy is asserted through the hero's own slots (`home.title` and
     * `home.cta_start`): they render on every tree, while `home.read_article`
     * is the label of a case-study slide, which exists only where storage
     * publishes an article. Escaping is the subject here, not the corpus.
     */
    #[Test]
    public function theHomeGroupCopyIsEscaped(): void
    {
        $html = (new MonoTheme())->homepage(
            PageContext::forHomepage(
                Request::create('GET', '/'),
                'mono',
                'sk_SK',
                copy: ['home.title' => 'A & B <script>', 'home.cta_start' => '"quoted"'],
            ),
        );

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;quoted&quot;', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    /**
     * Every slide's text ships `mo-is-visible`: the slider cross-fades the
     * containers, never the titles — a stacked no-JS render stays fully
     * readable, and no bundle timing can leave an empty hero behind.
     */
    #[Test]
    public function everySlideShipsVisibleTitles(): void
    {
        $html = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertSame(3, substr_count($html, 'mo-slide__title mo-is-visible'), 'Hero H1 + 2 case H2s, all visible from first paint.');
        $this->assertStringContainsString('<a class="mo-btn" href="/blog">', $html, 'The hero CTA leads to the blog.');
        $this->assertSame(1, substr_count($html, 'mo-slide--hero'), 'Only the opening slide stages the editorial glow.');
        $this->assertStringContainsString(
            '.mo-slide--hero',
            InlineCss::file('Modules/Mono/assets/critical/mono.css'),
            'The hero glow must ship in the fallback — without it the opening stage is a flat tint next to photos.',
        );
    }

    /**
     * The slider's photography is the committed demo art (`public/demo/*.jpg`),
     * cycled by slide position — every stage ships a photo, so the slider
     * reads photographic whatever storage publishes. An article's own cover
     * never drives it; the action's explicit heroSrc still wins on slide 1.
     * Load intent ships with the markup: the opening slide is the LCP element
     * (high priority), the off-stage slides load late.
     */
    #[Test]
    public function homepageSlidesCarryTintsAndDemoPhotography(): void
    {
        $html = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertSame(3, substr_count($html, '--mo-slide-bg:'), 'Every slide carries its tint.');
        $this->assertSame(3, substr_count($html, '--mo-slide-bg-dark:'), 'Every slide carries its dark tint — mono is dark-first.');
        $this->assertStringContainsString('mo-slide__shade', $html, 'Imagery ships its legibility shade.');

        $this->assertSame(3, substr_count($html, '<img class="mo-slide__bg"'), 'Every slide ships a photo — the demo art is the slider imagery.');
        $this->assertStringContainsString('src="https://example.test/hero.webp"', $html, 'The action\'s explicit hero still wins on slide 1.');
        $this->assertStringContainsString('src="/demo/hero-2.jpg"', $html, 'The case slides follow the demo frames in position order.');
        $this->assertStringContainsString('src="/demo/hero-3.jpg"', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'Only the opening slide is the LCP element.');
        $this->assertSame(2, substr_count($html, 'loading="lazy"'), 'The off-stage case slides load late — never on the critical path.');

        // Without an action-passed hero, slide 1 opens on the demo frame —
        // and an article's own cover still does not reach the stage.
        $demo = (new MonoTheme())->homepage(PageContext::forHomepage(
            Request::create('GET', '/'),
            'mono',
            'en',
            copy: ['title' => 'Hero'],
            latestArticles: [self::article('a', 'A', '/uploads/a.jpg')],
        ));

        $this->assertStringContainsString('src="/demo/hero-1.jpg"', $demo, 'The kernel home passes no heroSrc, so slide 1 opens on the demo frame.');
        $this->assertStringContainsString('src="/demo/hero-2.jpg"', $demo, 'Slide 2 follows in position order.');
        $this->assertStringNotContainsString('/uploads/a.jpg', $demo, 'An article cover is not slider art — it never drives the stage.');

        // A slider that points at a file the docroot does not ship renders
        // tints and a broken-image icon: the frames must exist.
        foreach (['/demo/hero-1.jpg', '/demo/hero-2.jpg', '/demo/hero-3.jpg'] as $src) {
            $this->assertFileExists(APP_BASE_PATH . '/public' . $src, 'The slider frame is missing from the docroot: ' . $src);
        }
    }

    #[Test]
    public function homepageShipsNoInlineScripts(): void
    {
        $home = (new MonoTheme())->homepage(self::homepageContext());

        $this->assertStringNotContainsString('<script', $home, 'No inline scripts — the slider runs from the bundle.');
        $this->assertStringNotContainsString('mo-slider.js', $home);
    }

    /**
     * The scrollspy's active rule must ship in the stylesheet — a tracker
     * with no style highlights invisibly. The TOC gate itself moved into
     * the bundle init; pageWrap no longer decides.
     */
    #[Test]
    public function theStylesheetCarriesTheScrollspyActiveRule(): void
    {
        $this->assertStringContainsString(
            '.mo-toc__link--active',
            InlineCss::file('Modules/Mono/assets/critical/mono.css'),
            'The active rule must ship in the stylesheet — a tracker with no style highlights invisibly.',
        );
    }

    /**
     * The article aside: h2/h3 become labelled anchor links, h3 indented —
     * the same HTML the browser sees, so every href matches a heading id.
     */
    #[Test]
    public function blogArticleRendersTheTocAsideFromHeadings(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'Getting started', 'anchor' => 'getting-started'],
            ['level' => 3, 'text' => 'Install', 'anchor' => 'install'],
        ]));

        $this->assertStringContainsString('<aside class="mo-toc">', $html);
        $this->assertStringContainsString('aria-label="On this page"', $html, 'The nav needs a name — two navs without one are noise to a screen reader.');
        $this->assertStringContainsString('href="#getting-started"', $html);
        $this->assertStringContainsString('>Install</a>', $html);
        $this->assertStringContainsString('mo-toc__item--sub', $html, 'h3 nests under its h2 — a flat list lies about the structure.');
        $this->assertStringContainsString('data-toc-link data-heading="install"', $html, 'The scrollspy opt-in travels with the link, so a future Mono scrollspy needs no new markup.');
    }

    /**
     * No headings, no aside — and the markup stays exactly the pre-TOC shape,
     * so short articles ship not one byte of navigation chrome.
     */
    #[Test]
    public function blogArticleOmitsTheTocAsideWithoutHeadings(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([]));

        $this->assertStringNotContainsString('mo-toc', $html);
        $this->assertStringNotContainsString('mo-article-layout', $html);
        $this->assertStringContainsString('<article class="mo-card">', $html);
    }

    /**
     * TOC text and anchors are escaped — a heading is author input, and
     * `"#` in an href is a breakout waiting for a quote.
     */
    #[Test]
    public function blogArticleEscapesTocTextAndAnchors(): void
    {
        $html = (new MonoTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'A & B <script>', 'anchor' => 'a-"b'],
            ['level' => 2, 'text' => '', 'anchor' => 'empty-text'],
            ['level' => 2, 'text' => 'No anchor', 'anchor' => ''],
        ]));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('href="#a-&quot;b"', $html);
        $this->assertStringNotContainsString('#empty-text', $html, 'An entry with no text is a link to nowhere.');
        $this->assertStringNotContainsString('No anchor', $html, 'An entry with no anchor cannot be jumped to.');
    }

    /**
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleContext(array $headings): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/toc-test'),
            'mono',
            'sk',
            'toc-test',
            'TOC test',
            '<h2 id="getting-started">Getting started</h2>',
            '',
            '',
            '',
            '',
            $headings,
            [],
        );
    }

    /**
     * The upstream navbar__actions carries a dark/light switcher next to the
     * meatball — the vendored snapshot styles its icons, the shared layout
     * handler owns the click, so the theme only has to render the button.
     */
    #[Test]
    public function theNavbarActionsCarryTheThemeToggleBeforeTheMeatball(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('data-theme-toggle', $header, 'Without the opt-in the shared handler never wires the button.');
        $this->assertStringContainsString('mo-theme-toggle__sun', $header);
        $this->assertStringContainsString('mo-theme-toggle__moon', $header, 'Both icons ship; CSS shows one at a time.');
        $this->assertStringContainsString('aria-label="Toggle theme"', $header);

        $toggle = (int) strpos($header, 'data-theme-toggle');
        $meatball = (int) strpos($header, 'mo-navbar__toggle-icon--meatball');
        $this->assertLessThan($meatball, $toggle, 'Upstream order: theme switcher first, meatball last — the meatball stays the final control.');
    }

    /**
     * The blog's search control lives in the page, because this shell carries
     * none: without it `/blog` is searchable only by typing `?q=` by hand.
     */
    #[Test]
    public function theBlogIndexCarriesASearchField(): void
    {
        $html = (new MonoTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'mono',
            'sk_SK',
            [self::article('alpha', 'Alpha Client')],
        ));

        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('action="/blog"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    #[Test]
    public function theBlogSearchCarriesTheQueryEscaped(): void
    {
        $html = (new MonoTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog?q=%3Cb%3E'),
            'mono',
            'sk_SK',
            '<b>',
            [self::article('alpha', 'Alpha Client')],
        ));

        $this->assertStringContainsString('value="&lt;b&gt;"', $html);
        $this->assertStringNotContainsString('value="<b>"', $html);
    }

    /**
     * The field posts to the locale's blog base, never to the path it was
     * rendered on: LocaleMiddleware strips `/en` before routing, so an English
     * reader's `getPathInfo()` is `/blog` and posting there would search the
     * default corpus instead of the one they are reading.
     */
    #[Test]
    public function theSearchFieldPostsToTheLocalesBlogBase(): void
    {
        $english = (new MonoTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'mono',
            'en_US',
            [self::article('alpha', 'Alpha Client')],
        ));

        $this->assertStringContainsString('action="/en/blog"', $english);
        $this->assertStringNotContainsString('action="/blog"', $english);
    }

    private static function article(string $slug, string $title, ?string $image = null): object
    {
        $article = new \stdClass();
        $article->slug = $slug;
        $article->title = $title;

        if (null !== $image) {
            $article->image = $image;
        }

        return $article;
    }

    private static function homepageContext(): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            'mono',
            'en_US',
            copy: ['title' => 'Mono upstream', 'lede' => 'Business first.', 'cta' => 'Discover more'],
            blogHref: '/blog',
            heroSrc: 'https://example.test/hero.webp',
            latestArticles: [self::article('alpha', 'Alpha Client'), self::article('beta', 'Beta Client')],
        );
    }

    /**
     * The locale switcher is a SWITCH, not two nav links: one labelled pill
     * track with the active locale filled. Measured 2026-10-17 — it rendered
     * `class="mo-navbar__link mo-btn--sm"`, a nav link plus a SIZE MODIFIER
     * whose base class (`mo-btn`) was never applied, so the track had no state
     * at all and the language the visitor was already reading looked like the
     * other one. The label is the short code (`SK`), because the full `sk_SK`
     * track was clipped off a 390 px phone; `lang` / `hreflang` carry the
     * BCP-47 form so each link is still announced in its own language.
     *
     * The active segment is the option whose code equals `$activeLocale`, and
     * this asserts WHICH one that is — a track that fills the first option
     * regardless of the active locale would pass a count-only assertion.
     */
    /**
     * The glyph choice is optional: without a stored value Mono keeps its own
     * meatball — the glyph the other themes borrowed the idiom from.
     */
    #[Test]
    public function theToggleKeepsTheMeatballByDefault(): void
    {
        foreach (['', 'auto'] as $stored) {
            $header = self::headerWithIcon($stored);

            $this->assertStringNotContainsString('mo-navbar__toggle--burger', $header, 'stored=' . $stored);
        }
    }

    #[Test]
    public function aStoredBurgerSwapsOnlyTheClosedGlyph(): void
    {
        $header = self::headerWithIcon('burger');

        $this->assertStringContainsString('class="mo-navbar__toggle mo-icon-btn mo-navbar__toggle--burger"', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--burger', $header);
        $this->assertStringContainsString('mo-navbar__toggle-icon--meatball', $header, 'Both glyphs ship; the modifier decides which one shows.');
        $this->assertStringContainsString('mo-navbar__toggle-icon--close', $header, 'The open state stays the shared X.');
        $this->assertStringContainsString('id="mo-nav-toggle"', $header, 'The checkbox and its label pairing do not move.');
    }

    /**
     * The stylesheet, not just the class name: an open menu must hide the
     * burger exactly like it hides the meatball, or two glyphs stack.
     */
    #[Test]
    public function theStylesheetSwapsAndHidesTheClosedGlyph(): void
    {
        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        $this->assertStringContainsString('.mo-navbar__toggle-icon--burger{display:none}', $css);
        $this->assertStringContainsString('.mo-navbar__toggle--burger .mo-navbar__toggle-icon--meatball{display:none}', $css);
        $this->assertStringContainsString('.mo-navbar__toggle--burger .mo-navbar__toggle-icon--burger{display:block}', $css);
        $this->assertStringContainsString(
            '.mo-navbar__toggle-input:checked~.mo-navbar__actions .mo-navbar__toggle-icon--burger{display:none}',
            $css,
            'The open state must hide the opt-in burger too.',
        );
    }

    #[Test]
    public function theSchemaDeclaresTheNavIconChoice(): void
    {
        $schema = (new MonoTheme())->settingsSchema();

        $this->assertCount(1, $schema);
        $this->assertSame('nav_toggle_icon', $schema[0]->key);
        $this->assertSame('auto', $schema[0]->default, 'Unset keeps the theme\'s own meatball.');
        $this->assertSame(['auto', 'burger', 'meatball'], array_keys($schema[0]->options));
    }

    #[Test]
    public function theLocaleSwitcherIsASwitchWithTheActiveSegmentMarked(): void
    {
        $locales = ['sk_SK', 'en_US'];
        $header = (new MonoTheme())->header(ShellContext::from(
            'Mono',
            [],
            [],
            '/blog',
            null,
            'en_US',
            $locales,
            false,
        ));

        $this->assertStringContainsString(
            '<nav class="mo-lang-switch" aria-label="Language">',
            $header,
            'The links form a labelled group, not an unlabelled div.',
        );
        // Counted with the opening quote on purpose: `--active` CONTAINS
        // `mo-lang-switch__option`, so a bare substring count reports two for
        // the active segment alone and would read as "three options".
        $this->assertSame(
            2,
            substr_count($header, 'class="mo-lang-switch__option'),
            'One segment per available locale.',
        );
        $this->assertSame(
            1,
            substr_count($header, 'mo-lang-switch__option--active'),
            'Exactly one segment is the current locale.',
        );
        $this->assertMatchesRegularExpression(
            '/mo-lang-switch__option mo-lang-switch__option--active" href="\/en\/blog" hreflang="en" lang="en" aria-current="true">EN</',
            $header,
            'The ACTIVE segment must be the active locale, not the first option — with BCP-47 lang/hreflang and aria-current.',
        );
        $this->assertStringContainsString(
            'href="/blog" hreflang="sk" lang="sk">SK</a>',
            $header,
            'The other locale keeps its own href and prints the short code.',
        );
        $this->assertStringNotContainsString(
            '>en_US</a>',
            $header,
            'The segment prints SK/EN, not the underscore locale that widened the track off a phone.',
        );
        $this->assertStringNotContainsString(
            'mo-btn--sm',
            $header,
            'A size modifier without its `mo-btn` base is exactly what this replaced.',
        );

        // With a menu and two locales, the meatball is the LAST control: on a
        // phone it is the one a visitor needs at the edge of the row.
        $full = (new MonoTheme())->header(ShellContext::from(
            'Mono',
            [new ShellNavItem('Home', '/', active: true)],
            [],
            '/blog',
            null,
            'en_US',
            $locales,
            false,
        ));
        $this->assertLessThan(
            (int) strpos($full, 'mo-navbar__toggle-icon--meatball'),
            (int) strpos($full, 'mo-lang-switch'),
            'The meatball stays the final control — the switch sits before it, not after.',
        );

        $css = InlineCss::file('Modules/Mono/assets/critical/mono.css');

        $this->assertStringContainsString(
            '.mo-navbar__dev-toggle{display:none}',
            $css,
            'The dev variant buttons leave no room for the switch on a phone.',
        );
        $this->assertStringContainsString(
            '.mo-navbar__brand{font-size:var(--text-lg);flex-shrink:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            $css,
            'A long site name must shrink instead of pushing the row past the viewport.',
        );
    }

    private static function headerWithItems(): string
    {
        return self::headerWithIcon('');
    }

    /**
     * @param string $icon stored `nav_toggle_icon`; '' means "nothing stored"
     */
    private static function headerWithIcon(string $icon): string
    {
        $context = ShellContext::from(
            'Site',
            [new ShellNavItem('Home', '/', active: true), new ShellNavItem('Articles', '/blog')],
            [new ShellNavItem('Docs', '/docs')],
            '/',
            null,
            'sk',
            [],
            false,
            '' === $icon ? [] : ['nav_toggle_icon' => $icon],
        );

        return (new MonoTheme())->header($context);
    }
}
