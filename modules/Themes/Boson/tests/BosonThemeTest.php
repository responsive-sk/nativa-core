<?php

declare(strict_types = 1);

namespace Modules\Themes\Boson\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Themes\Boson\Theme\BosonTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BosonThemeTest — the boson port's contracts.
 *
 * The reference is a Lit/Twig site; this port is server-rendered PHP plus one
 * vendored script. Three things therefore need pinning that a screenshot
 * cannot: the checkbox menu's DOM order (the panel is reached with `~`), the
 * fact that every class the markup emits is `bo-` prefixed (the stylesheet is
 * one file, and an unprefixed name would silently fall out of it), and that
 * no surface names a remote host (the reference's artwork is deliberately
 * absent rather than fetched at runtime).
 *
 * Skips when the module is not installed, like every module test here: the
 * kernel-only suite must stay green without it.
 */
final class BosonThemeTest extends TestCase
{
    private const string MODULE = 'Boson';

    private const string THEME = 'boson';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `boson` theme ships with this module.');
        }
    }

    #[Test]
    public function discoveryFindsTheTheme(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Boson ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(BosonTheme::class, $theme);
        $this->assertSame('boson', $theme->bundle());
        $this->assertSame('boson', $theme->jsKey());
        $this->assertSame('bo', $theme->prefix());
        $this->assertSame('', $theme->themeInitScript(), 'The shared pre-paint block stays the kernel\'s bytes.');
        $this->assertFileExists(APP_BASE_PATH . '/modules/Themes/Boson/assets/critical/boson.css');
    }

    /**
     * The behaviour script arrives exactly once, from one of two places: the
     * built bundle FrontendLayout tags with `defer` when `public/dist/boson/`
     * has a manifest, or the vendored file inlined here when it has not.
     *
     * Both branches are asserted against the same state, so the test says the
     * same thing in a tree that ran `make assets` and in the CI job that runs
     * the suite without a build. The built case is the reason for the test: a
     * wrap that inlined as well would execute every init twice — duplicate
     * scroll listeners, duplicate copy buttons — and nothing would fail.
     */
    #[Test]
    public function pageWrapShipsTheBehaviourScriptOnce(): void
    {
        $theme = new BosonTheme();
        $wrapped = $theme->pageWrap($theme->homepage(self::homepageContext()));
        $built = null !== (new AssetManager('boson'))->jsUrl('boson');

        $this->assertSame(1, substr_count($wrapped, '<main class="bo-main">'), 'One wrap, one <main>.');

        if ($built) {
            $this->assertSame(
                0,
                substr_count($wrapped, '<script'),
                'The bundle is the page\'s one script; inlining the vendored copy too would run the IIFE twice.',
            );

            $html = FrontendLayout::render('Test', $wrapped, theme: 'boson');

            $this->assertSame(
                1,
                substr_count($html, '<script src="/dist/boson/'),
                'A built tree ships the bundle as the one deferred behaviour script.',
            );
            $this->assertStringContainsString(' defer></script>', $html);

            return;
        }

        $this->assertSame(1, substr_count($wrapped, '<script'), 'One behaviour script, no per-surface scripts.');
        $this->assertStringContainsString('nonce="__CSP_NONCE_PLACEHOLDER__"', $wrapped);
        $this->assertStringContainsString('initHeader()', $wrapped);
        $this->assertStringContainsString('initAccordion()', $wrapped);
        $this->assertStringContainsString('initCode()', $wrapped);
    }

    /**
     * The checkbox contract: one `~` selector reaches both the panel and the
     * label, so the input must be the header's first child. The CSS uses the
     * same order, which is pinned beside the DOM here.
     */
    #[Test]
    public function theToggleSitsBeforeThePanelAndTheLabel(): void
    {
        $header = (new BosonTheme())->header(self::shell());

        $input = strpos($header, 'id="bo-nav-toggle"');
        $panel = strpos($header, 'id="bo-mobile-menu"');
        $label = strpos($header, 'for="bo-nav-toggle"');

        $this->assertIsInt($input);
        $this->assertIsInt($panel);
        $this->assertIsInt($label);
        $this->assertLessThan($panel, $input, 'The input must precede the panel: `~` cannot reach a preceding sibling.');
        $this->assertLessThan($label, $input);

        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString('.bo-burger-input:checked ~ .bo-mobile', $css);
        $this->assertStringContainsString('.bo-burger-input:checked ~ .bo-header__bar', $css);
    }

    /**
     * The menu-glyph choice is OPTIONAL: an install that never opened the
     * settings screen keeps the three lines the design was ported with.
     */
    #[Test]
    public function theHeaderKeepsTheThemesOwnBurgerWithoutAChoice(): void
    {
        $header = (new BosonTheme())->header(self::shell());

        $this->assertStringContainsString('class="bo-burger"', $header);
        $this->assertStringNotContainsString('bo-burger--meatball', $header);
    }

    #[Test]
    public function autoResolvesToTheThemesOwnIcon(): void
    {
        $header = (new BosonTheme())->header(self::shell('auto'));

        $this->assertStringContainsString('class="bo-burger"', $header);
        $this->assertStringNotContainsString('bo-burger--meatball', $header);
    }

    /**
     * The variant is a class on the SAME label, not a second markup path: the
     * checkbox, its position in the header and the shared close glyph are the
     * contract the panel's `~` selectors depend on.
     */
    #[Test]
    public function aStoredMeatballRendersTheMeatballVariantOnTheSameLabel(): void
    {
        $header = (new BosonTheme())->header(self::shell('meatball'));

        $this->assertStringContainsString('class="bo-burger bo-burger--meatball"', $header);
        $this->assertStringContainsString('id="bo-nav-toggle"', $header, 'The checkbox stays; only the glyph changes.');
        $this->assertStringContainsString('for="bo-nav-toggle"', $header);
        $this->assertStringContainsString('class="bo-burger__open"', $header);
        $this->assertStringContainsString('class="bo-burger__close"', $header, 'An open menu is the shared X in both variants.');
    }

    /**
     * The stylesheet, not just the class name: a modifier with no rule behind
     * it renders the burger and looks saved — the exact "setting saved, page
     * unchanged" failure this feature exists to avoid.
     */
    #[Test]
    public function theMeatballVariantIsStyledAsThreeDots(): void
    {
        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString('.bo-burger--meatball .bo-burger__open', $css);
        $this->assertMatchesRegularExpression(
            '/\.bo-burger--meatball[^{]*\{[^}]*border-radius:\s*50%/s',
            $css,
            'The dots are round; without the radius the variant draws three short lines.',
        );
        $this->assertStringContainsString(
            '.bo-burger-input:checked ~ .bo-mobile',
            $css,
            'The panel still opens off the checkbox, never off the variant class.',
        );
    }

    #[Test]
    public function theSchemaDeclaresTheNavIconChoice(): void
    {
        $schema = (new BosonTheme())->settingsSchema();

        $this->assertCount(1, $schema, 'One field: the theme offers one choice, and the admin renders what it declares.');
        $this->assertSame('nav_toggle_icon', $schema[0]->key);
        $this->assertSame('auto', $schema[0]->default, 'Unset means the theme\'s own icon, not the admin\'s worst guess.');
        $this->assertSame(['auto', 'burger', 'meatball'], array_keys($schema[0]->options));
    }

    #[Test]
    public function theHeaderRendersTheShellNavAndTheAdminPrefix(): void
    {
        $header = (new BosonTheme())->header(self::shell());

        $this->assertStringContainsString('bo-header__brand', $header);
        $this->assertStringContainsString('>Boson Test<', $header);
        $this->assertStringContainsString('href="/blog"', $header);
        $this->assertStringContainsString('aria-current="page"', $header, 'The active nav item is announced.');
        $this->assertStringContainsString('bo-nav__sub', $header, 'Children render as a ruled submenu.');
        $this->assertStringContainsString('href="' . AdminRoutePolicy::prefix() . '"', $header, 'The admin link comes from the policy, never a literal.');
        $this->assertStringContainsString('bo-locale__link--active', $header);
        $this->assertStringContainsString('bo-search__input', $header);
        $this->assertStringContainsString('bo-mobile__link', $header);
    }

    /**
     * The mark is the site's own logo, not a letter drawn from its name: the
     * header ships an `<img>` pointing at the file the head already declares as
     * the tab icon, so the tab, the touch icon and the brand cannot drift
     * apart. The artwork is a public asset, so it must exist as one.
     */
    #[Test]
    public function theBrandMarkIsTheSitesOwnLogo(): void
    {
        $header = (new BosonTheme())->header(
            ShellContext::from('responsive.sk', [], [], '/', null, 'sk', [], false),
        );

        $this->assertStringContainsString('class="bo-header__mark-img"', $header);
        $this->assertStringContainsString('src="/favicon.svg"', $header);
        $this->assertStringContainsString('bo-header__wordmark">responsive.sk<', $header, 'The name stays text; the mark is the logo.');
        $this->assertFileExists(APP_BASE_PATH . '/public/favicon.svg', 'The mark points at a public asset that must be shipped.');
        $this->assertFileExists(APP_BASE_PATH . '/public/favicon.ico');
        $this->assertFileExists(APP_BASE_PATH . '/public/apple-touch-icon.png');
    }

    /**
     * The icon files are ONE artwork, and the vector is measured against the
     * bitmap it redraws rather than trusted.
     *
     * The first redraw sampled its palette from anti-aliased pixels (#a90000,
     * #fe5353) and grew the light band by a third to hide seams, so the header
     * mark and the tab icon were visibly two different logos. What a test can
     * hold without a rasteriser is the part that made it wrong: the palette
     * must be the ICO frame's own fully opaque colours, the viewBox must be
     * the frame it redraws, the touch icon must be the size iOS asks for, and
     * the ICO's hand-hinted small sizes must survive the redraw.
     */
    #[Test]
    public function theIconSetIsOneArtwork(): void
    {
        $svg = (string) file_get_contents(APP_BASE_PATH . '/public/favicon.svg');

        $this->assertStringContainsString('viewBox="0 0 48 48"', $svg, 'The redraw is of the ICO\'s 48px frame.');

        preg_match_all('/fill="(#[0-9a-f]{6})"/', $svg, $matches);

        $this->assertSame(
            ['#aa0000', '#d40000', '#ff5555'],
            array_values(array_unique($matches[1])),
            'Fills must be the ICO frame\'s own opaque colours, in paint order (silhouette first), never sampled from its anti-aliased edges.',
        );

        $touch = getimagesize(APP_BASE_PATH . '/public/apple-touch-icon.png');

        $this->assertIsArray($touch);
        $this->assertSame([180, 180], [$touch[0], $touch[1]], 'iOS wants a 180x180 touch icon.');

        $ico = (string) file_get_contents(APP_BASE_PATH . '/public/favicon.ico');
        $directories = unpack('v', substr($ico, 4, 2));

        $this->assertIsArray($directories);
        $this->assertSame(3, $directories[1], 'The .ico carries three entries.');

        $sizes = [];

        for ($entry = 0; $entry < 3; $entry++) {
            $sizes[] = ord($ico[6 + ($entry * 16)]);
        }

        $this->assertSame([16, 32, 48], $sizes, 'The hand-hinted small sizes are the authoritative artwork; they must not be replaced by downscales.');
    }

    /**
     * The hero orbit's core is the same logo, and the CSS must not draw a
     * letter any more: a theme that left the old rules behind would render a
     * 22px image on a font-size that outranks it.
     */
    #[Test]
    public function theHeroOrbitCarriesTheSameLogo(): void
    {
        $html = (new BosonTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('<img class="bo-orbit__mark" src="/favicon.svg"', $html);
        $this->assertStringNotContainsString('bo-orbit__mark">', $html, 'The orbit mark is an image, not a text node.');

        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString('.bo-orbit__mark {', $css);
        $this->assertStringContainsString('.bo-header__mark-img {', $css);
        $this->assertStringContainsString('.bo-header__brand:hover .bo-header__mark-img {', $css, 'The mark must invert on the brand fill it sits on.');
    }

    /**
     * The split/closing bands derive the blog URL when the context carries
     * none (`$context->blogHref === ''`). They were declared static and reached
     * for `$this->blogBase()` — a latent `Using $this when not in object
     * context` 500 that stayed hidden only because the homepage always passes
     * a blog href, so PHP never evaluated the fallback branch.
     */
    #[Test]
    public function theBandsWithoutABlogHrefFallBackToTheLocalePrefix(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: ['title' => 'Boson Test'],
            blogHref: '',
        );

        $html = (new BosonTheme())->homepage($context);

        $this->assertStringContainsString('bo-split', $html);
        $this->assertStringContainsString('bo-cta', $html);
        $this->assertStringContainsString('href="/blog"', $html, 'The fallback resolves through the locale prefix, not a literal.');
    }

    #[Test]
    public function aHeaderWithoutNavStillRendersTheBrandAndNoToggle(): void
    {
        $header = (new BosonTheme())->header(ShellContext::from('Boson Test', [], [], '/blog', null, 'sk', [], false));

        $this->assertStringContainsString('bo-header__brand', $header);
        $this->assertStringNotContainsString('bo-nav-toggle', $header);
        $this->assertStringNotContainsString('bo-mobile', $header);
    }

    /**
     * Every band from the reference is present, and the accordion ships item 1
     * open so the section reads without the script.
     */
    #[Test]
    public function theHomepageRendersEveryBand(): void
    {
        $html = (new BosonTheme())->homepage(self::homepageContext());

        foreach ([
            'bo-hero',
            'bo-segment',
            'bo-surfaces',
            'bo-stacks',
            'bo-columns',
            'bo-accordion',
            'bo-compare',
            'bo-split',
            'bo-principles',
            'bo-cta',
            'bo-discover',
        ] as $block) {
            $this->assertStringContainsString('class="' . $block, $html, 'Missing band: ' . $block);
        }

        $this->assertSame(5, substr_count($html, 'data-bo-accordion-item'), 'Five "under the hood" items.');
        $this->assertSame(1, substr_count($html, 'bo-accordion__item--open'), 'Exactly one item ships open.');
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    /**
     * The kernel's copy wins over the defaults, and a hostile value cannot
     * leave the attribute or the text node it was placed in.
     */
    #[Test]
    public function theHomepageReadsTheCopyFirstAndEscapesIt(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: [
                'home.hero_title_1' => 'Title <script>alert(1)</script>',
                'home.hero_title_2' => 'Second "line"',
                'home.cta_blog' => 'Go & read',
            ],
        );

        $html = (new BosonTheme())->homepage($context);

        $this->assertStringContainsString('Title &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Title <script>', $html);
        $this->assertStringContainsString('Second &quot;line&quot;', $html);
        $this->assertStringContainsString('Go &amp; read', $html);
    }

    #[Test]
    public function theHeroUsesTheKernelsBlogHref(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'en',
            blogHref: '/en/blog',
        );

        $this->assertStringContainsString('href="/en/blog"', (new BosonTheme())->homepage($context));
    }

    /**
     * The index slices by page/perPage — the action hands the theme the full
     * list and the theme owns the window.
     */
    #[Test]
    public function theBlogIndexSlicesAndPaginates(): void
    {
        $html = (new BosonTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha'), self::article('b', 'Beta'), self::article('c', 'Gamma')],
            page: 2,
            perPage: 2,
        ));

        $this->assertStringNotContainsString('Alpha', $html, 'Page 2 must not repeat page 1.');
        $this->assertStringNotContainsString('Beta', $html, 'Page 2 is the second window, not the first.');
        $this->assertStringContainsString('Gamma', $html);
        $this->assertStringContainsString('href="/blog/c"', $html, 'Article links are relative to the locale.');
        $this->assertStringContainsString('bo-pager__page--current" aria-current="page" href="/blog?page=2">2', $html);
        $this->assertStringContainsString('href="/blog">1</a>', $html);
    }

    #[Test]
    public function theEnglishBlogLinksCarryTheLocalePrefix(): void
    {
        $html = (new BosonTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/en/blog'),
            self::THEME,
            'en',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('href="/en/blog/a"', $html);
    }

    /**
     * The index is the list, and the search field is the shell's.
     *
     * The header's input posts to `/blog` and the menu carries a second copy for
     * a narrow viewport, so a form here asked `q` of the same URL a third time
     * and pushed the cards down the page. The guides' index makes the same
     * choice in the kernel.
     */
    #[Test]
    public function theBlogIndexLeavesTheSearchToTheShell(): void
    {
        $html = (new BosonTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringNotContainsString('<form', $html, 'The blog index composed a form of its own.');
        $this->assertStringNotContainsString('name="q"', $html, 'The shell\'s field is the index\'s search.');
    }

    #[Test]
    public function theBlogSearchRendersAndEscapesTheQuery(): void
    {
        $html = (new BosonTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog?q=%3Cb%3E'),
            self::THEME,
            'sk',
            '<b>',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('Search: &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Search: <b>', $html);
        $this->assertStringNotContainsString(
            'name="q"',
            $html,
            'The results page renders its own field instead of narrowing from the shell\'s.',
        );
        $this->assertStringContainsString('1 result', $html);
    }

    /**
     * Raw-by-contract fields pass through; the headline and the TOC labels do
     * not.
     */
    #[Test]
    public function theArticlePassesRawFieldsAndEscapesItsOwnMarkup(): void
    {
        $html = (new BosonTheme())->blogArticle(self::articleContext());

        $this->assertStringContainsString('<p>Body <em>raw</em>.</p>', $html, 'bodyHtml is HTML by contract.');
        $this->assertStringContainsString('<a href="/authors/x">By X</a>', $html, 'metaLine carries a pre-built anchor.');
        $this->assertStringContainsString('bo-article__tags', $html);
        $this->assertStringContainsString('bo-toc__link', $html);
        $this->assertStringContainsString('data-heading="first-part"', $html);
        $this->assertStringContainsString('open', $html, 'The contents rail must ship open.');
        $this->assertStringContainsString('2 views', $html);
        $this->assertStringNotContainsString('bo-toc__kicker', $html, 'A heading without a prefix gets no kicker.');
    }

    /**
     * `Časť I — Title` on every section is repetition, not navigation: the
     * prefix becomes a quiet kicker and the title carries the line. Level-3
     * headings fold under their section so the rail stays a few groups long.
     */
    #[Test]
    public function theArticleTocGroupsSectionsAndQuietsRepeatedKickers(): void
    {
        $html = (new BosonTheme())->blogArticle(PageContext::forBlogArticle(
            Request::create('GET', '/blog/a'),
            self::THEME,
            'sk',
            'a',
            'A title',
            '<p>Body</p>',
            '',
            '',
            '',
            '',
            [
                ['level' => 2, 'text' => 'Časť I — Prvý krok', 'anchor' => 'cast-1'],
                ['level' => 3, 'text' => 'Detail', 'anchor' => 'detail-1'],
                ['level' => 3, 'text' => 'Druhý detail', 'anchor' => 'detail-2'],
                ['level' => 2, 'text' => 'Časť II — Druhý krok', 'anchor' => 'cast-2'],
            ],
            [],
            0,
        ));

        $this->assertSame(4, substr_count($html, 'data-toc-link'), 'Every heading stays reachable.');
        $this->assertSame(2, substr_count($html, 'data-toc-group'), 'Level-2 headings are the two rail groups.');
        $this->assertSame(1, substr_count($html, 'bo-toc__sublist'), 'Only the first group has subheadings.');
        $this->assertSame(2, substr_count($html, 'bo-toc__kicker'), 'Two prefixed headings, two kickers.');
        $this->assertStringContainsString('<span class="bo-toc__kicker">Časť I</span>', $html);
        $this->assertStringContainsString('<span class="bo-toc__text">Prvý krok</span>', $html);
        $this->assertStringContainsString('bo-toc__link bo-toc__link--sub', $html);
    }

    /**
     * Comments and reader questions are composed by the action, so they arrive
     * after the article surface. Appended raw they sit outside `.bo-page` and
     * lose the page gutters; the tail puts them in the article's own column.
     */
    #[Test]
    public function theArticleTailIsPlacedInTheReadingColumn(): void
    {
        $theme = new BosonTheme();
        $html = $theme->articleTail('<section class="bo-comments">x</section>');

        $this->assertStringContainsString('bo-page bo-page--article-tail', $html);
        $this->assertStringContainsString('bo-article-layout__main', $html);
        $this->assertMatchesRegularExpression(
            '/bo-article-layout__main"><section class="bo-comments">/',
            $html,
            'The tail must open inside the reading column, not after the page.',
        );
        $this->assertSame('', $theme->articleTail(''));
    }

    #[Test]
    public function theArticleTitleIsEscaped(): void
    {
        $html = (new BosonTheme())->blogArticle(self::articleContext('<h1>Injected</h1>'));

        $this->assertStringNotContainsString('<h1>Injected</h1>', $html);
        $this->assertStringContainsString('&lt;h1&gt;Injected&lt;/h1&gt;', $html);
    }

    #[Test]
    public function theTagAndCategorySurfacesRenderTheirCloudsAndCards(): void
    {
        $theme = new BosonTheme();

        $tags = $theme->blogTags(PageContext::forBlogTags(
            Request::create('GET', '/blog/tags/php'),
            self::THEME,
            'sk',
            ['php', 'web'],
            ['php' => 3],
            'php',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('bo-chip--active', $tags);
        $this->assertStringContainsString('php (3)', $tags);
        $this->assertStringContainsString('href="/blog/tags/web"', $tags);
        $this->assertStringContainsString('Alpha', $tags);

        $categories = $theme->blogCategories(PageContext::forBlogCategories(
            Request::create('GET', '/blog/categories/news'),
            self::THEME,
            'sk',
            ['news'],
            ['news' => 1],
            'news',
            [self::article('b', 'Beta')],
        ));

        $this->assertStringContainsString('bo-chip--active', $categories);
        $this->assertStringContainsString('Beta', $categories);
    }

    #[Test]
    public function theAuthorProfileRendersTheProfileAndTheGrid(): void
    {
        $html = (new BosonTheme())->authorProfile(PageContext::forAuthorProfile(
            Request::create('GET', '/authors/x'),
            self::THEME,
            'sk',
            ['id' => 'x', 'name' => 'Ada', 'avatar' => '/storage/a.png'],
            [self::article('a', 'Alpha')],
            '2026-01-01',
        ));

        $this->assertStringContainsString('bo-author__avatar', $html);
        $this->assertStringContainsString('Ada', $html);
        $this->assertStringContainsString('Member since 2026-01-01', $html);
        $this->assertStringContainsString('Alpha', $html);
    }

    /**
     * The auth surfaces the theme now composes: the CSRF/return_to inputs
     * arrive ready and are placed inside the form, the fields are escaped, and
     * both error and success messages are announced. The footnote stays HTML
     * because it is the action's static link line, never user input.
     */
    #[Test]
    public function theAuthPagePlacesTheHiddenFieldsAndEscapesItsInput(): void
    {
        $html = (new BosonTheme())->authPage(
            'Sign <in>',
            '/login',
            'Sign in',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => '"><script>'],
                ['name' => 'stay', 'label' => 'Keep me signed in', 'type' => 'checkbox', 'required' => false],
                ['name' => 'token', 'type' => 'hidden', 'value' => 'abc'],
            ],
            '<input type="hidden" name="_token" value="csrf">',
            ['Wrong <password>'],
            'No account yet? <a href="/register">Create one</a>',
            'Check <mail>.',
        );

        $this->assertStringContainsString('bo-auth__form', $html);
        $this->assertStringContainsString('action="/login"', $html);
        $this->assertStringContainsString('name="_token" value="csrf"', $html, 'The CSRF field is placed by the theme, never rebuilt.');
        $this->assertStringContainsString('id="bo-auth-email"', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $html);
        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringContainsString('Sign &lt;in&gt;', $html);
        $this->assertStringContainsString('Wrong &lt;password&gt;', $html);
        $this->assertStringContainsString('Check &lt;mail&gt;.', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('<a href="/register">Create one</a>', $html, 'The footnote is trusted HTML by contract.');
        $this->assertStringContainsString('type="hidden" name="token" value="abc"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('bo-auth__submit', $html);
    }

    /**
     * The /docs surfaces the theme now composes: the index reads the catalogue
     * and drops an entry without a key (it would have no URL), and the guide
     * passes MarkdownRenderer output through by contract.
     */
    #[Test]
    public function theDocsSurfacesRenderTheCatalogueAndEscapeIt(): void
    {
        $theme = new BosonTheme();

        $index = $theme->docsIndex([
            ['key' => 'getting-started', 'title' => 'Getting <started>', 'description' => 'How <it> works.'],
            ['key' => '', 'title' => 'dropped', 'description' => 'No URL.'],
        ]);

        $this->assertStringContainsString('bo-docs__list', $index);
        $this->assertStringContainsString('href="/docs/getting-started"', $index);
        $this->assertStringContainsString('Getting &lt;started&gt;', $index);
        $this->assertStringContainsString('How &lt;it&gt; works.', $index);
        $this->assertStringNotContainsString('dropped', $index, 'An entry without a key has no URL, so it is dropped.');

        $page = $theme->docsPage('getting-started', 'Getting started', '<h2>Part</h2><p>Body</p>');

        $this->assertStringContainsString('bo-docs__back-link', $page);
        $this->assertStringContainsString('bo-article__body', $page);
        $this->assertStringContainsString('<h2>Part</h2>', $page, 'Markdown output passes through by contract.');
    }

    #[Test]
    public function theErrorContentCarriesStatusAndEscapesDetail(): void
    {
        $html = (new BosonTheme())->errorContent(404, 'Not found', '<script>x</script>');

        $this->assertStringContainsString('Error 404', $html);
        $this->assertStringContainsString('Not found', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
    }

    /**
     * Every class the theme writes itself carries `bo-`. The highlighter's
     * `tok-*` spans are generated by the script, never server-rendered, so
     * they are correctly absent here; the body shell class belongs to the
     * layout.
     */
    #[Test]
    public function everyClassTheMarkupEmitsIsPrefixed(): void
    {
        $theme = new BosonTheme();
        $pages = [
            $theme->homepage(self::homepageContext()),
            $theme->blogIndex(self::indexContext()),
            $theme->blogSearch(PageContext::forBlogSearch(
                Request::create('GET', '/blog?q=x'),
                self::THEME,
                'sk',
                'x',
                [self::article('a', 'Alpha')],
            )),
            $theme->blogArticle(self::articleContext()),
            $theme->blogTags(PageContext::forBlogTags(
                Request::create('GET', '/blog/tags'),
                self::THEME,
                'sk',
                ['php'],
                ['php' => 1],
            )),
            $theme->blogCategories(PageContext::forBlogCategories(
                Request::create('GET', '/blog/categories'),
                self::THEME,
                'sk',
                ['news'],
                ['news' => 1],
            )),
            $theme->authorProfile(PageContext::forAuthorProfile(
                Request::create('GET', '/authors/x'),
                self::THEME,
                'sk',
                ['id' => 'x', 'name' => 'Ada', 'avatar' => null],
                [],
                '',
            )),
            $theme->errorContent(500, 'Error', 'Detail.'),
            $theme->authPage(
                'Sign in',
                '/login',
                'Sign in',
                [
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                    ['name' => 'stay', 'label' => 'Keep me signed in', 'type' => 'checkbox', 'required' => false],
                ],
                '<input type="hidden" name="_token" value="x">',
                ['Wrong credentials.'],
                'No account yet? <a href="/register">Create one</a>',
            ),
            $theme->docsIndex([
                ['key' => 'getting-started', 'title' => 'Getting started', 'description' => 'How it works.'],
            ]),
            $theme->docsPage('getting-started', 'Getting started', '<p>Body.</p>'),
            $theme->header(self::shell()),
            $theme->footer(self::shell()),
        ];

        $stray = [];

        foreach ($pages as $html) {
            preg_match_all('/class="([^"]*)"/', $html, $matches);

            foreach ($matches[1] as $value) {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ('' === $class || str_starts_with($class, 'bo-')) {
                        continue;
                    }

                    $stray[$class] = true;
                }
            }
        }

        $this->assertSame([], array_keys($stray), 'Unprefixed classes would fall out of the one boson stylesheet: ' . implode(', ', array_keys($stray)));
    }

    /**
     * The reference's CSS and artwork are not fetched at runtime, and the
     * inline block cannot carry an at-rule InlineCss strips.
     */
    #[Test]
    public function theStylesheetIsSelfContained(): void
    {
        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $this->assertStringContainsString(':root', $css);
        $this->assertStringContainsString('--bo-brand: #f93904;', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('@charset', $css);
        $this->assertStringNotContainsString('@layer', $css);
        $this->assertStringNotContainsString('/images/', $css);
        $this->assertStringNotContainsString('fonts.googleapis.com', $css);
        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
    }

    #[Test]
    public function noRenderedSurfaceNamesARemoteHost(): void
    {
        $theme = new BosonTheme();
        $html = $theme->homepage(self::homepageContext())
            . $theme->header(self::shell())
            . $theme->footer(self::shell());

        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertStringNotContainsString('//cdn', $html);
    }

    /**
     * Lighthouse: "Heading elements are not in a sequentially-descending
     * order" — the home page jumped `h2` to `h5` twice (the three columns and
     * the four split notes). Each level is a document outline, so a skip is a
     * section a screen-reader user cannot estimate the depth of; the fix is
     * the level, not the label, and `.bo-column__title` / `.bo-split__title`
     * carry their own size so the change moves no pixel.
     *
     * Every rendered surface is checked, not just the homepage: the same
     * `<h5>` in a shared band would come back on another page.
     */
    #[Test]
    public function headingLevelsDescendWithoutSkippingALevel(): void
    {
        $theme = new BosonTheme();
        $surfaces = [
            'homepage' => $theme->homepage(self::homepageContext()),
            'blog index' => $theme->blogIndex(self::indexContext()),
            'blog article' => $theme->blogArticle(self::articleContext()),
            'docs page' => $theme->docsPage('getting-started', 'Getting started', '<h2>Part</h2><p>Body.</p>'),
        ];

        foreach ($surfaces as $surface => $html) {
            preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h([1-6])>/s', $html, $matches, PREG_SET_ORDER);

            // A heading whose closer names another level is still a heading to
            // a browser and still in the outline the audit reads, so it must
            // not be allowed to fall out of this walk unnoticed.
            preg_match_all('/<h[1-6]\b/i', $html, $opened);
            $this->assertSame(
                count($opened[0]),
                count($matches),
                sprintf('The %s opens %d headings but closes only %d of them correctly.', $surface, count($opened[0]), count($matches)),
            );

            $previous = 0;

            foreach ($matches as $match) {
                $this->assertSame(
                    $match[1],
                    $match[3],
                    sprintf('The %s closes an h%s with </h%s>.', $surface, $match[1], $match[3]),
                );

                $level = (int) $match[1];

                if ($previous > 0) {
                    $this->assertLessThanOrEqual(
                        $previous + 1,
                        $level,
                        sprintf(
                            'The %s jumps from h%d to h%d ("%s") — an outline with a hole in it.',
                            $surface,
                            $previous,
                            $level,
                            mb_substr(trim(strip_tags($match[2])), 0, 40),
                        ),
                    );
                }

                $previous = $level;
            }

            $this->assertGreaterThan(0, count($matches), sprintf('The %s renders no headings at all.', $surface));
            $this->assertSame(1, substr_count($html, '<h1'), sprintf('The %s must carry exactly one h1.', $surface));
        }
    }

    /**
     * The module surfaces a boson article and page actually render — comments,
     * reader questions and static pages — are part of the one stylesheet, and
     * the contents rail exposes its current section to both CSS and assistive
     * technology. The TOC title is a `<summary>`: it needs the tap-target and
     * disclosure affordances a bare summary does not get from the browser.
     */
    #[Test]
    public function theModuleSurfacesAndTheActiveTocLinkAreStyled(): void
    {
        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');
        $script = InlineJs::file('Modules/Boson/assets/scripts/boson.js');

        foreach ([
            '.bo-section {',
            '.bo-comments__form,',
            '.bo-questions__form {',
            '.bo-toc__title::after {',
            '.bo-toc__link--active,',
            '.bo-toc__sublist {',
            '.bo-toc__kicker {',
            '.bo-toc__item--group[data-toc-active] .bo-toc__sublist {',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, 'Missing module-surface style: ' . $selector);
        }

        $this->assertStringContainsString("classList.toggle('bo-toc__link--active'", $script);
        $this->assertStringContainsString("setAttribute('aria-current', 'true')", $script);
        $this->assertStringContainsString("removeAttribute('aria-current')", $script);
        $this->assertStringContainsString("setAttribute('data-toc-active', '')", $script);
        $this->assertStringContainsString("removeAttribute('data-toc-active')", $script);
    }

    /**
     * Lighthouse: "Certain ARIA roles must contain particular children" on the
     * comparison table. `role="table"` may only own `rowgroup`/`row`, and a
     * `row` may only own `cell`/`columnheader`/`rowheader`/`gridcell`; the body
     * rows shipped without any role, so the table had rows of anonymous spans
     * and assistive technology announced nothing usable.
     */
    #[Test]
    public function theComparisonTableOwnsTheRolesItsRoleRequires(): void
    {
        $html = (new BosonTheme())->homepage(self::homepageContext());

        $start = strpos($html, 'class="bo-compare__table"');
        $this->assertNotFalse($start, 'The comparison table left the homepage.');

        $table = substr($html, $start, strpos($html, '</section>', $start) - $start);

        $this->assertStringContainsString('role="table"', $table);

        // role="table" owns row groups/rows, never cells directly.
        $this->assertStringContainsString('<div role="rowgroup">', $table, 'A table whose rows are not grouped has no header/body split.');

        // Five rows: one header plus the four comparisons.
        $rows = substr_count($table, 'role="row"');
        $this->assertSame(5, $rows, 'One header row plus the four comparison rows.');

        // The rule this test exists for: no cell-shaped child may go without
        // its role, because that is what turned the rows into anonymous spans.
        preg_match_all('/<span class="bo-compare__(?:label|cell)[^"]*"(?![^>]*\brole=)/', $table, $anonymous);
        $this->assertSame([], $anonymous[0], 'Every bo-compare label/cell must carry its role: ' . implode(', ', $anonymous[0]));

        // Header cells are column headers, body cells are cells.
        preg_match_all('/<span class="bo-compare__(?:label|cell)[^"]*" role="([a-z]+)"/', $table, $roles);
        $this->assertSame(3, count(array_filter($roles[1], static fn (string $r): bool => 'columnheader' === $r)), 'One columnheader per column.');
        $this->assertSame(4 * 3, count(array_filter($roles[1], static fn (string $r): bool => 'cell' === $r)), 'Four body rows of three cells each.');
        $this->assertSame(
            count($roles[1]),
            substr_count($table, 'role="row"') * 3,
            'Every child of every row carries a role.',
        );
    }

    /**
     * Lighthouse: "Background and foreground colors do not have a sufficient
     * contrast ratio" on `bo-btn--primary` — the brand orange on the button
     * fill measured 4.40:1, just under the 4.5:1 floor for normal-size text.
     *
     * The ratio is computed here from the two tokens the rule actually uses,
     * so a palette edit that fixes the default and re-breaks the hover (or the
     * reverse) fails: both states are labels a reader can land on.
     */
    #[Test]
    public function thePrimaryButtonLabelPassesContrastInBothStates(): void
    {
        $css = InlineCss::file('Modules/Boson/assets/critical/boson.css');

        $brand = $this->token($css, '--bo-brand');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, $this->token($css, '--bo-btn')), 'The primary button label is under 4.5:1 on its resting fill.');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, $this->token($css, '--bo-btn-hover')), 'The primary button label is under 4.5:1 on its hover fill.');

        // The same orange is the eyebrow, the comparison marks and the hover
        // link colour on the page background; those were only passing by luck
        // before, so the floor is pinned for them too.
        $this->assertGreaterThanOrEqual(4.5, self::contrast($brand, $this->token($css, '--bo-bg')), 'Brand text on the page background is under 4.5:1.');
    }

    /** A `--bo-*` colour token from the stylesheet, as `#rrggbb`. */
    private function token(string $css, string $name): string
    {
        $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6});/i', $css, sprintf('Token %s is not declared as a hex colour.', $name));

        preg_match('/' . preg_quote($name, '/') . ':\s*(#[0-9a-f]{6});/i', $css, $matches);

        return strtolower($matches[1]);
    }

    /** WCAG 2.x contrast ratio of two opaque hex colours. */
    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function homepageContext(): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: ['title' => 'Boson Test'],
        );
    }

    private static function indexContext(): PageContext
    {
        return PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha')],
        );
    }

    private static function articleContext(string $title = 'A title'): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/a'),
            self::THEME,
            'sk',
            'a',
            $title,
            '<p>Body <em>raw</em>.</p>',
            '<a href="/authors/x">By X</a>',
            '<a href="/blog/tags/php">php</a>',
            '',
            '',
            [
                ['level' => 2, 'text' => 'First part', 'anchor' => 'first-part'],
                ['level' => 3, 'text' => 'Detail', 'anchor' => 'detail'],
            ],
            [],
            2,
        );
    }

    /**
     * @param string $icon stored `nav_toggle_icon`; '' means "nothing stored"
     */
    private static function shell(string $icon = ''): ShellContext
    {
        return ShellContext::from(
            'Boson Test',
            [
                new ShellNavItem('Home', '/', true),
                new ShellNavItem('Blog', '/blog', false, false, [
                    new ShellNavItem('PHP', '/blog/tags/php'),
                ]),
            ],
            [new ShellNavItem('Privacy', '/privacy')],
            '/blog',
            ['sk' => '/blog', 'en' => '/en/blog'],
            'sk',
            ['sk', 'en'],
            false,
            '' === $icon ? [] : ['nav_toggle_icon' => $icon],
        );
    }

    private static function article(string $slug, string $title): object
    {
        return (object) [
            'slug' => $slug,
            'title' => $title,
            'date' => '2026-01-01',
            'category' => 'News',
            'author' => 'Ada',
            'excerpt' => 'An excerpt.',
            'image' => '/storage/cover.png',
        ];
    }
}
