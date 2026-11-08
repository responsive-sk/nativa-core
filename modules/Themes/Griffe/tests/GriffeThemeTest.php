<?php

declare(strict_types = 1);

namespace Modules\Themes\Griffe\Tests;

use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Themes\Griffe\Theme\GriffeTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GriffeThemeTest — the checkbox hamburger's DOM order, the slider contract,
 * and the frozen bundle the behaviours ship in.
 *
 * The mobile menu opens through `:checked`, which only works while the input
 * precedes both the menu and the label (one `~` selector, no `:has()`).
 * Nothing in the stylesheet enforces that order, so this file does.
 *
 * pageWrap ships a bare `<main>`: the header/slider/reveal/highlight twins
 * moved into the frozen `griffe` bundle FrontendLayout defers, each init
 * self-gating on its markup. The slider counts still cut at the first
 * `<script` — belt and braces against an inline script sneaking back in.
 */
final class GriffeThemeTest extends TestCase
{
    private const string MODULE = 'Griffe';

    private const string THEME = 'griffe';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `griffe` theme ships with this module.');
        }
    }

    #[Test]
    public function discoveryFindsTheTheme(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Griffe ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(GriffeTheme::class, $theme);
        $this->assertSame('griffe', $theme->bundle());
        $this->assertSame('gr', $theme->prefix());

        // The colour-mode restore stays verbatim (Mono precedent) — and it is
        // now the ONLY pre-paint script: the `gr-js` staging signal went away
        // with the griffe slider surface it staged.
        $init = $theme->themeInitScript();

        $this->assertStringContainsString("localStorage.getItem('nativa-theme')", $init);
        $this->assertStringContainsString("setAttribute('data-theme', theme)", $init);
        $this->assertStringNotContainsString('gr-js', $init, 'The staging signal is gone with the slider it staged — it must not creep back.');
        $this->assertSame(1, substr_count($init, '<script'), 'One pre-paint script in <head>, not two.');
    }

    #[Test]
    public function theHeadShipsExactlyOnePrePaintScript(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $this->assertStringContainsString("setAttribute('data-theme', theme)", $head, 'The colour-mode restore still runs before first paint.');
        $this->assertStringNotContainsString('gr-js', $head, 'No staging signal in the rendered head.');

        $style = (int) strpos($head, '<style');
        $script = (int) strpos($head, 'setAttribute(');
        $this->assertLessThan($script, $style, 'The <style> paints first — the restore still precedes the body.');
    }

    #[Test]
    public function theLandingIsTheRespFrontHorizontalHero(): void
    {
        $html = (new GriffeTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('<hero-layout', $html);
        $this->assertStringContainsString('<horizontal-scroll-hero', $html);
        $this->assertStringContainsString('autoplay-interval="6000"', $html);
        $this->assertStringContainsString('show-navigation show-scroll-hint', $html);
        $this->assertSame(3, count(self::slidesOf($html)), 'Hero slide + one slide per article.');
    }

    /**
     * The landing component owns its fixed header/footer, so the theme must
     * not stack the griffe chrome above them on `/` — while every other path
     * keeps it.
     */
    #[Test]
    public function theLandingOwnsItsChrome(): void
    {
        $theme = new GriffeTheme();

        $this->assertSame('', $theme->header(self::shellWithItems('/')), 'hero-layout supplies the only header on /.');
        $this->assertSame('', $theme->footer(self::shellWithItems('/')), 'hero-layout supplies the only footer on /.');

        $this->assertStringContainsString('gr-header__brand', $theme->header(self::shellWithItems('/blog')), 'Every other path keeps the griffe chrome.');
        $this->assertStringContainsString('gr-footer__inner', $theme->footer(self::shellWithItems('/blog')));
    }

    #[Test]
    public function theToggleSitsBeforeTheMenuItOpens(): void
    {
        $header = self::headerWithItems();

        $input = strpos($header, 'id="gr-nav-toggle"');
        $menu = strpos($header, 'class="gr-nav"');
        $label = strpos($header, 'for="gr-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($menu);
        $this->assertIsInt($label);
        $this->assertLessThan($menu, $input, 'The input must precede the menu: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $menu, 'The label must follow the menu, inside the actions cluster.');
    }

    #[Test]
    public function theTogglePairingIsComplete(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('type="checkbox"', $header);
        $this->assertStringContainsString('class="gr-nav__toggle-input"', $header);
        $this->assertStringContainsString('aria-label="Open navigation"', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--meatball', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--close', $header, 'Both icons ship; CSS shows one at a time.');
    }

    /**
     * The glyph choice is optional: without a stored value the theme keeps the
     * meatball the header was ported with.
     */
    #[Test]
    public function theToggleKeepsTheMeatballByDefault(): void
    {
        foreach (['', 'auto'] as $stored) {
            $header = (new GriffeTheme())->header(self::shellWithItems(icon: $stored));

            $this->assertStringNotContainsString('gr-nav__toggle--burger', $header, 'stored=' . $stored);
        }
    }

    #[Test]
    public function aStoredBurgerSwapsOnlyTheClosedGlyph(): void
    {
        $header = (new GriffeTheme())->header(self::shellWithItems(icon: 'burger'));

        $this->assertStringContainsString('class="gr-nav__toggle gr-icon-btn gr-nav__toggle--burger"', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--burger', $header);
        $this->assertStringContainsString('gr-nav__toggle-icon--meatball', $header, 'Both glyphs ship; the modifier decides which one shows.');
        $this->assertStringContainsString('gr-nav__toggle-icon--close', $header, 'The open state stays the shared X.');
        $this->assertStringContainsString('id="gr-nav-toggle"', $header, 'The checkbox and its label pairing do not move.');
    }

    /**
     * The stylesheet, not just the class name: a modifier with no rule behind
     * it renders the meatball and looks saved.
     */
    #[Test]
    public function theStylesheetSwapsTheClosedGlyphOnTheModifier(): void
    {
        $css = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');

        $this->assertStringContainsString('.gr-nav__toggle-icon--burger { display: none; }', $css);
        $this->assertStringContainsString('.gr-nav__toggle--burger .gr-nav__toggle-icon--meatball { display: none; }', $css);
        $this->assertStringContainsString('.gr-nav__toggle--burger .gr-nav__toggle-icon--burger { display: inline-flex; }', $css);
        $this->assertStringContainsString(
            '.gr-nav__toggle-input:checked ~ .gr-header__inner .gr-nav__toggle-icon--burger',
            $css,
            'An open menu must hide whichever closed glyph is showing.',
        );
    }

    #[Test]
    public function theSchemaDeclaresTheNavIconChoice(): void
    {
        $schema = (new GriffeTheme())->settingsSchema();

        $this->assertCount(1, $schema);
        $this->assertSame('nav_toggle_icon', $schema[0]->key);
        $this->assertSame('auto', $schema[0]->default, 'Unset keeps the theme\'s own meatball.');
        $this->assertSame(['auto', 'burger', 'meatball'], array_keys($schema[0]->options));
    }

    #[Test]
    public function aHeaderWithoutItemsRendersNoToggleAtAll(): void
    {
        // A non-landing path: `/` renders no griffe header at all (the landing
        // component owns its chrome), so this contract belongs to /blog.
        $context = ShellContext::from('Griffe', [], [], '/blog', null, 'sk', [], false);

        $header = (new GriffeTheme())->header($context);

        $this->assertStringNotContainsString('gr-nav__toggle', $header);
        $this->assertStringNotContainsString('class="gr-nav"', $header);
        $this->assertStringContainsString('gr-header__brand', $header, 'The brand is always there.');
    }

    #[Test]
    public function theStylesheetKeysTheOpenStateOffChecked(): void
    {
        $css = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');

        $this->assertStringContainsString(
            '.gr-nav__toggle-input:checked ~ .gr-header__inner .gr-nav',
            $css,
            'The open state is a checkbox, not a class some script has to add.',
        );
    }

    #[Test]
    public function theHeaderCarriesTheSharedToggleBeforeTheHamburger(): void
    {
        $header = self::headerWithItems();

        $this->assertStringContainsString('data-theme-toggle', $header, 'Without the opt-in the shared handler never wires the button.');
        $this->assertStringContainsString('gr-theme-toggle__sun', $header);
        $this->assertStringContainsString('gr-theme-toggle__moon', $header, 'Both icons ship; CSS shows one at a time.');

        $toggle = (int) strpos($header, 'data-theme-toggle');
        $hamburger = (int) strpos($header, 'gr-nav__toggle-icon--meatball');
        $this->assertLessThan($hamburger, $toggle, 'Theme switcher first, hamburger last — the hamburger stays the final control.');
    }

    #[Test]
    public function theHomepageIsTheRespFrontHorizontalHero(): void
    {
        $slides = self::slidesOf((new GriffeTheme())->homepage(self::homepageContext()));

        $this->assertSame('hero', $slides[0]['id'], 'The copy hero opens the deck.');
        $this->assertSame('/blog/alpha', $slides[1]['link']);
        $this->assertSame('/blog/beta', $slides[2]['link']);
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

        $locale = ContentServices::mapper()->defaultLocale();
        $context = PageContext::forHomepage(Request::create('GET', '/'), 'griffe', $locale);

        $slides = self::slidesOf((new GriffeTheme())->homepage($context));

        if (!class_exists($facade)) {
            $this->assertCount(1, $slides, 'The hero slide renders with no articles.');

            return;
        }

        $expected = $facade::articles()->latestCards(6, $locale);

        $this->assertCount(1 + count($expected), $slides, 'Hero slide plus one slide per published article.');

        foreach (array_values($expected) as $i => $card) {
            $this->assertSame('/blog/' . $card['slug'], $slides[$i + 1]['link']);
            $this->assertSame($card['image'], $slides[$i + 1]['backgroundImage'], 'Selected work is illustrated — every fallback article ships its cover.');
        }
    }

    /**
     * Slide 1 carries the copy hero: eyebrow kicker, resolved title, lede and
     * the blog CTA. The component renders the text, so the contract is the
     * JSON payload it receives rather than server-side markup.
     */
    #[Test]
    public function theHeroSlideCarriesTheCopy(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Hero', 'lede' => 'Lede.', 'hero_eyebrow' => 'Proof & Co', 'cta' => 'Go'],
            blogHref: '/blog',
            latestArticles: [self::article('a', 'A')],
        );

        $hero = self::slidesOf((new GriffeTheme())->homepage($context))[0];

        $this->assertSame('Proof & Co', $hero['tag']);
        $this->assertSame('Hero', $hero['titleLine1']);
        $this->assertSame('Lede.', $hero['subtitle']);
        $this->assertSame('/blog', $hero['link']);
        $this->assertSame('Go', $hero['buttonText']);
    }

    /**
     * The meta line carries a pre-built author anchor
     * (BlogArticleHelper::metaLine — the same bytes the starter renders raw
     * in st-article__meta), so the theme must pass it through: escaping it
     * prints the tag as visible text under the headline.
     */
    /**
     * The article headline sits above the fold, exactly like the homepage
     * hero: it ships `gr-in` from the server and never waits on the bundle
     * or the observer — a dead observer must not eat the headline.
     */
    #[Test]
    public function theArticleHeadlineShipsRevealed(): void
    {
        $html = (new GriffeTheme())->blogArticle(self::articleContext('<p>Body.</p>'));

        $this->assertStringContainsString('class="gr-anim gr-in"><span class="gr-anim__line"', $html);
        $this->assertStringNotContainsString('class="gr-anim"><span class="gr-anim__line"', $html, 'No masked line may survive above the fold.');
    }

    #[Test]
    public function blogArticlePassesTheMetaLineThroughUnescaped(): void
    {
        $html = (new GriffeTheme())->blogArticle(self::articleContext(
            '<p>Body.</p>',
            '2026-10-12 · Architektúra · <a href="/authors/nativa-dev">Nativa Dev</a>',
        ));

        $this->assertStringContainsString('<p class="gr-article__meta">2026-10-12', $html);
        $this->assertStringContainsString('<a href="/authors/nativa-dev">Nativa Dev</a>', $html);
        $this->assertStringNotContainsString('&lt;a', $html, 'The author anchor escaped into visible tag text.');
    }

    /**
     * Masked units keep a text boundary between them: the spans are blocks,
     * so a newline paints nothing — but without one the last word of a unit
     * and the first of the next copy as a single word ("ktoré"+"dokáže,").
     */
    #[Test]
    public function maskedLineUnitsStaySeparatedByWhitespace(): void
    {
        $html = (new GriffeTheme())->blogArticle(PageContext::forBlogArticle(
            Request::create('GET', '/blog/hello'),
            self::THEME,
            'en',
            'hello',
            'One two three four five six',
            '<p>Body.</p>',
            '',
            '',
            '',
            '',
            [],
            [],
        ));

        $this->assertStringContainsString(
            '</span></span>' . "\n" . '<span class="gr-anim gr-in">',
            $html,
            'Six words make two masked units; the boundary between them must survive copy.',
        );
    }

    #[Test]
    public function pageWrapShipsNoInlineScripts(): void
    {
        // Surfaces return bare sections; the single pageWrap (FrontendLayout's)
        // wraps them in a bare <main> — the frozen bundle owns every behaviour.
        $theme = new GriffeTheme();
        $home = $theme->pageWrap($theme->homepage(self::homepageContext()));

        $this->assertSame(1, substr_count($home, '<main class="gr-main">'), 'One wrap, one <main> — no nested mains.');
        $this->assertStringNotContainsString('<script', $home, 'No inline scripts — header, slider and reveal run from the bundle.');
        $this->assertStringNotContainsString('gr-slider.js', $home);

        $article = $theme->pageWrap($theme->blogArticle(self::articleContext(
            '<pre><code class="language-php">echo 1;</code></pre>',
        )));

        $this->assertStringNotContainsString('<script', $article, 'Even fenced pages ship no inline highlighter.');
    }

    #[Test]
    public function renderedDocumentDefersTheFrozenBundle(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString(
            '<script src="/dist/griffe/',
            $html,
            'The document must defer the frozen bundle — without it griffe pages keep the critical CSS but lose every behaviour.',
        );
        $this->assertStringContainsString('defer></script>', $html, 'The bundle script stays deferred, never render-blocking.');
    }

    #[Test]
    public function nothingLeaksAnotherThemesVocabulary(): void
    {
        $html = (new GriffeTheme())->homepage(self::homepageContext())
            . (new GriffeTheme())->header(self::shellWithItems())
            . (new GriffeTheme())->footer(self::shellWithItems());

        foreach (['er-header', 'er-nav', 'mo-navbar', 'st-header', 'mo-card', 'er-section'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, sprintf('Foreign vocabulary `%s` in griffe output.', $foreign));
        }
    }

    #[Test]
    public function theBlogFamilyRendersCloudsAuthorAndErrors(): void
    {
        $theme = new GriffeTheme();

        $tags = $theme->blogTags(PageContext::forBlogTags(
            Request::create('GET', '/blog/tag/php'),
            'griffe',
            'en',
            ['php', 'web'],
            ['php' => 3],
            'php',
            [self::article('a', 'A')],
        ));

        $this->assertStringContainsString('gr-chip--active', $tags, 'The active term is marked.');
        $this->assertStringContainsString('php (3)', $tags, 'Counts render beside terms.');

        $author = $theme->authorProfile(PageContext::forAuthorProfile(
            Request::create('GET', '/author/jane'),
            'griffe',
            'en',
            ['name' => 'Jane', 'avatar' => null],
            [self::article('a', 'A')],
            '2024',
        ));

        $this->assertStringContainsString('Jane', $author);
        $this->assertStringContainsString('Member since 2024', $author);

        $error = $theme->errorContent(404, 'Lost <here>', '& gone');

        $this->assertStringContainsString('Lost &lt;here&gt;', $error);
        $this->assertStringContainsString('&amp; gone', $error);
        $this->assertStringContainsString('href="/"', $error);
    }

    #[Test]
    public function slidesCarryTintsAndImageryWhenProvided(): void
    {
        $imaged = self::article('pic', 'Pictured Client');
        $imaged->image = '/img/case.jpg';

        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Hero'],
            blogHref: '/blog',
            heroSrc: '/img/hero.jpg',
            heroSrcset: '/img/hero-800.jpg 800w',
            latestArticles: [$imaged, self::article('plain', 'Plain Client')],
        );

        $slides = self::slidesOf((new GriffeTheme())->homepage($context));

        $this->assertSame('/img/hero.jpg', $slides[0]['backgroundImage']);
        $this->assertSame('/img/case.jpg', $slides[1]['backgroundImage']);
        $this->assertSame('', $slides[2]['backgroundImage'], 'A source-less article keeps the theme-colour background.');
    }

    #[Test]
    public function untrustedCopyEscapes(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'A & B <script>'],
        );

        $html = (new GriffeTheme())->homepage($context);

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    /**
     * The blog's search control lives in the page, because this shell has none:
     * without it `/blog` is searchable only by typing `?q=` by hand.
     */
    #[Test]
    public function theBlogIndexCarriesASearchField(): void
    {
        $html = (new GriffeTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'griffe',
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
        $html = (new GriffeTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog?q=%3Cb%3E'),
            'griffe',
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
        $english = (new GriffeTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'griffe',
            'en_US',
            [self::article('alpha', 'Alpha Client')],
        ));

        $this->assertStringContainsString('action="/en/blog"', $english);
        $this->assertStringNotContainsString('action="/blog"', $english);
    }

    private static function bodyOf(string $html): string
    {
        $cut = strpos($html, '<script');

        return false === $cut ? $html : substr($html, 0, $cut);
    }

    private static function article(string $slug, string $title): object
    {
        $article = new \stdClass();
        $article->slug = $slug;
        $article->title = $title;

        return $article;
    }

    private static function homepageContext(): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            'griffe',
            'en',
            copy: ['title' => 'Tailored B2B E-commerce Platforms', 'lede' => 'Business first.'],
            blogHref: '/blog',
            latestArticles: [self::article('alpha', 'Alpha Client'), self::article('beta', 'Beta Client')],
        );
    }

    /**
     * @param list<object> $articles
     */
    private static function indexContext(array $articles): PageContext
    {
        return PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            'griffe',
            'en',
            $articles,
        );
    }

    private static function articleContext(string $bodyHtml, string $metaLine = ''): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/hello'),
            'griffe',
            'en',
            'hello',
            'Hello',
            $bodyHtml,
            $metaLine,
            '',
            '',
            '',
            [],
            [],
        );
    }

    private static function headerWithItems(): string
    {
        return (new GriffeTheme())->header(self::shellWithItems());
    }

    private static function shellWithItems(string $currentPath = '/blog', string $icon = ''): ShellContext
    {
        return ShellContext::from(
            'Griffe',
            [new ShellNavItem('Work', '/work', active: true), new ShellNavItem('Blog', '/blog')],
            [new ShellNavItem('Privacy', '/privacy')],
            $currentPath,
            null,
            'en',
            ['en', 'it'],
            false,
            '' === $icon ? [] : ['nav_toggle_icon' => $icon],
        );
    }

    /**
     * Decode the landing's `slides` attribute back into the array the custom
     * element parses. The attribute is HTML-escaped on purpose, so undo that
     * first — the assertions should read values, not `&quot;`.
     *
     * @return list<array<string, string>>
     */
    private static function slidesOf(string $html): array
    {
        $matched = preg_match('/<horizontal-scroll-hero[^>]* slides="([^"]*)"/', $html, $m);

        self::assertSame(1, $matched, 'The hero must declare a slides attribute.');

        $slides = json_decode(
            html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($slides);

        /** @var list<array<string, string>> $slides */
        return $slides;
    }
}
