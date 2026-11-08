<?php

declare(strict_types = 1);

namespace Modules\Themes\Jednotka\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Domain\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ShellContext;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Themes\Jednotka\Theme\JednotkaTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * JednotkaThemeTest — the `jednotka` theme, ported, and the two things that
 * make a port like this go wrong quietly.
 *
 * `erik` established the pattern this file repeats for the fourth theme: a
 * theme is discovered through its class, its build is resolved through a
 * bundle `AssetManager` knows, and the document wears its own vocabulary. Those
 * three are asserted here for `jednotka`, against its build on disk.
 *
 * The port adds two assertions of its own, because a ported theme has a failure
 * mode a first-party one does not: it can arrive still carrying the imports of
 * the tree it came from. The showcase original `use`d `Modules\Blog`,
 * `Modules\Locales` and `Modules\Settings`; here those reads go through guarded
 * string FQCNs and `get_object_vars`, and `themeDeclaresNoFeatureModuleImport`
 * is what keeps that true after the next person edits the file.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Jednotka is a module. A kernel-only tree has no JednotkaTheme to construct,
 * so the suite SKIPS rather than fails — the same contract ErikThemeTest and
 * KernelOnlyBootTest use. `tests/` is inside PHPStan's scope and `modules/` is
 * not, so a test naming an optional module's class cannot make the analyser of
 * a tree without that module fail.
 */
final class JednotkaThemeTest extends TestCase
{
    private const string MODULE = 'Jednotka';

    private const string THEME = 'jednotka';

    /** The markup prefix the theme owns (`je-`), against its siblings'. */
    private const string PREFIX = 'je';

    /**
     * The manifest entry inside the bundle — NOT the bundle name.
     *
     * This package builds `src/main.ts`, so `bundle()` and `jsKey()` differ
     * here while they coincide for `erik` and `mono`. The assertion below is
     * what keeps a future reader from "fixing" them into agreement and
     * silently resolving no stylesheet.
     */
    private const string ENTRY = 'main';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `jednotka` theme ships with this module.');
        }

        // Discovery memoizes statically and the module set is what it reads:
        // a test that ran after an install/remove must not read that cache.
        ThemeRegistry::reset();
    }

    protected function tearDown(): void
    {
        ThemeRegistry::reset();

        parent::tearDown();
    }

    #[Test]
    public function themeIsDiscoveredAndItsBundleIsKnownToAssetManager(): void
    {
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Jednotka ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(JednotkaTheme::class, $theme);
        $this->assertSame(self::THEME, $theme->name());
        $this->assertSame(self::THEME, $theme->bundle());
        $this->assertSame(self::ENTRY, $theme->jsKey(), 'The entry is the manifest key inside the bundle.');

        $this->assertContains(
            self::THEME,
            ThemeRegistry::bundles(),
            'The discovered theme does not contribute its bundle to the registry.',
        );

        $this->assertContains(
            self::THEME,
            AssetManager::knownBundles(),
            'AssetManager does not know the bundle this theme declares, so its'
            . ' manifest resolves as `kernel` — a bundle with no build — and the'
            . ' page silently inlines the critical-CSS fallback instead.',
        );
    }

    /**
     * The regression `erik` hit, measured for this theme: the bytes in the
     * document are the bytes in `public/dist/jednotka/assets/main-*.css`.
     */
    #[Test]
    public function layoutInlinesTheBuiltStylesheetAndNotTheCriticalSubset(): void
    {
        $assets = $this->requireBuiltBundle();

        // The ENTRY, not the bundle — see self::ENTRY.
        $url = $assets->cssUrls(self::ENTRY)[0] ?? null;

        $this->assertNotNull($url, 'The jednotka manifest entry ships no CSS.');

        $build = (string) file_get_contents($this->assetPath($url));

        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);
        $inlined = $this->styleBlock($html);

        $this->assertSame(
            $build . "\n",
            $inlined,
            'The inlined CSS is not the theme build — the layout resolved another bundle.'
            . ' (The critical subset is ' . strlen(FrontendLayout::criticalCss()) . ' chars.)',
        );

        // The fallback is what the defect served, so name it explicitly: an
        // equal-bytes assertion on the build is enough only as long as the
        // fallback is genuinely smaller — which this says out loud.
        $this->assertNotSame(
            FrontendLayout::criticalCss(),
            $inlined,
            'The layout inlined the critical subset instead of the build.',
        );

        $this->assertGreaterThan(
            100,
            substr_count($inlined, '.' . self::PREFIX . '-'),
            'The inlined CSS carries almost none of the theme vocabulary.',
        );

        $this->assertStringContainsString(
            'src="/dist/' . self::THEME . '/assets/',
            $html,
            'The theme build is inlined, so its JS entry must be referenced too.',
        );
    }

    #[Test]
    public function documentWearsTheJednotkaVocabularyAndNoSiblings(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString('<body class="' . self::THEME . '-shell">', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-header"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-main"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-footer"', $html);

        foreach (['k-header', 'k-footer', 'mo-header', 'mo-footer', 'er-header', 'er-footer'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'Another theme reached this document: ' . $foreign);
        }
    }

    /**
     * The invariant that makes this a port rather than a transplant: the theme
     * reads feature modules through guarded string names, never through `use`.
     *
     * A `use Modules\Blog\…` in this file would put an edge in the module's
     * install closure — the coupling the kernel exists to avoid — and it would
     * be invisible in a tree that happens to have Blog installed, which is
     * every tree that runs the blog. Hence a text assertion rather than a
     * behavioural one.
     */
    #[Test]
    public function themeDeclaresNoFeatureModuleImport(): void
    {
        $file = (new \ReflectionClass(JednotkaTheme::class))->getFileName();

        $this->assertIsString($file);

        $source = (string) file_get_contents($file);

        foreach (['Modules\\Blog', 'Modules\\Locales', 'Modules\\Settings'] as $feature) {
            $this->assertDoesNotMatchRegularExpression(
                '/^use ' . preg_quote($feature, '/') . '/m',
                $source,
                sprintf('The theme imports `%s`. Feature reads must go through a guarded string FQCN.', $feature),
            );
        }

        // The guard is only a guard if the names are still there: a port that
        // "fixed" the imports by deleting the reads would pass the loop above
        // and render an empty grid.
        $this->assertStringContainsString('class_exists', $source, 'The Blog reads must be guarded, not removed.');
        $this->assertStringContainsString('get_object_vars', $source, 'Article shapes must be read by shape, not by import.');
    }

    /**
     * jednotka is dark-first, so its pre-paint resolution is a design decision
     * and the theme owns the script — the showcase's reasoning, kept.
     */
    #[Test]
    public function themeInitScriptIsTheThemesOwnPrePaintResolution(): void
    {
        $theme = new JednotkaTheme();
        $script = $theme->themeInitScript();

        $this->assertStringContainsString('nonce="__CSP_NONCE_PLACEHOLDER__"', $script);
        $this->assertSame(
            '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . InlineJs::file('Modules/Jednotka/assets/scripts/theme-init.js')
            . '</script>',
            $script,
        );

        // The resolution order it exists for: choice → OS → DARK.
        $this->assertStringContainsString("localStorage.getItem('nativa-theme')", $script);
        $this->assertStringContainsString('prefers-color-scheme: dark', $script);
        $this->assertStringContainsString(": 'dark'", $script, 'The theme default is dark, and it is stated here.');
    }

    /**
     * The home surface as this kernel actually calls it: `HomepageAction`
     * passes `copy: ['title' => $siteName]`, no cards and no hero image.
     *
     * The theme's own defaults are what make that a page instead of four
     * headings over nothing — the showcase's strings and its card layout,
     * ported. This is the assertion that caught the port rendering a bare hero.
     */
    #[Test]
    public function homepageRendersEveryUnitFromTheThemesOwnDefaults(): void
    {
        $html = (new JednotkaTheme())->homepage(self::homepageContext('Tailored start'));

        $this->assertStringContainsString('<section class="je-unit je-hero">', $html);
        $this->assertStringContainsString('<h1 class="je-hero__title">Minimal PHP 8.5 Starter Kit</h1>', $html);
        $this->assertStringContainsString('A tiny, auditable foundation', $html);
        $this->assertStringContainsString('Unit 01 — Premise', $html);
        $this->assertStringContainsString('href="/blog">Read the blog</a>', $html);
        $this->assertStringContainsString('href="/mark">Open admin</a>', $html);

        // Both feature layers, three cards each, in the showcase's own words.
        $this->assertStringContainsString('<h2 class="je-section__title">Fewer Layers</h2>', $html);
        $this->assertStringContainsString('<h2 class="je-section__title">Super Fast PHP</h2>', $html);
        $this->assertSame(6, substr_count($html, 'class="je-card"'), 'Two layers of three cards.');
        $this->assertStringContainsString('<h3 class="je-card__title">Zero dependencies</h3>', $html);
        $this->assertStringContainsString('SQLite is pure C', $html);

        // The measure unit — and no invented link, because no href was handed over.
        $this->assertStringContainsString('je-stat__value', $html);
        $this->assertStringContainsString('215 MB dev', $html);
        $this->assertStringNotContainsString('je-card__link', $html, 'An href nobody provided is not invented.');

        // No heroSrc in this kernel: no media plate, and never a second <main>.
        $this->assertStringNotContainsString('je-hero__visual', $html, 'No heroSrc was configured — no media plate.');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<main', $html, 'One wrap, owned by FrontendLayout — never two.');

        // Order is the document's argument: premise, feature, feature, measure.
        $hero = strpos($html, 'je-hero');
        $layer1 = strpos($html, 'Fewer Layers');
        $layer2 = strpos($html, 'Super Fast PHP');
        $stats = strpos($html, 'je-stat__value');
        $this->assertIsInt($hero);
        $this->assertIsInt($layer1);
        $this->assertIsInt($layer2);
        $this->assertIsInt($stats);
        $this->assertLessThan($layer1, $hero);
        $this->assertLessThan($layer2, $layer1);
        $this->assertLessThan($stats, $layer2);
    }

    /**
     * The kernel's copy map wins over the defaults, KEY BY KEY — so when home
     * copy moves out of the kernel (the `M` answer `HomepageAction` records),
     * this theme follows it without an edit, and the keys the kernel does not
     * send keep their defaults.
     */
    #[Test]
    public function kernelCopyAndLayoutOverrideTheThemesDefaults(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'en',
            copy: ['home.hero_title' => 'Kernel wins', 'home.layer1_title' => 'Kernel layer'],
            links: ['layer1_card1' => '/blog/minimal'],
            cards: ['layer1' => ['layer1_card1']],
            heroSrc: '/img/hero.webp',
            heroSrcset: '/img/hero-800.webp 800w',
        );

        $html = (new JednotkaTheme())->homepage($context);

        $this->assertStringContainsString('<h1 class="je-hero__title">Kernel wins</h1>', $html);
        $this->assertStringContainsString('<h2 class="je-section__title">Kernel layer</h2>', $html);
        $this->assertStringContainsString('src="/img/hero.webp"', $html);
        $this->assertStringContainsString('srcset="/img/hero-800.webp 800w"', $html);
        $this->assertStringContainsString('href="/blog/minimal">Read article</a>', $html);

        // Overridden keys come from the kernel; the keys it did not send keep
        // the theme's own copy, and its layer2 layout is untouched.
        $this->assertStringContainsString('<h2 class="je-section__title">Super Fast PHP</h2>', $html);
        $this->assertSame(
            4,
            substr_count($html, 'class="je-card"'),
            'The kernel sent one layer1 card; layer2 still comes from the theme.',
        );
    }

    /**
     * The search field posts to the locale's blog base, not to a literal and
     * not to `getPathInfo()`: LocaleMiddleware strips `/en` before routing, so
     * the rendered path is `/blog` even for an English reader.
     */
    #[Test]
    public function theBlogSearchPostsToTheLocalesBlogBase(): void
    {
        $english = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'en_US', [self::article('a', 'A')]),
        );
        $slovak = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'sk_SK', [self::article('a', 'A')]),
        );

        $this->assertStringContainsString('action="/en/blog"', $english);
        $this->assertStringContainsString('action="/blog"', $slovak);
    }

    /**
     * The pager is computed from `PageContext` — the showcase's `SearchResult`
     * is a Blog DTO a theme may not name — and it keeps the index's one
     * canonical first-page URL.
     */
    #[Test]
    public function blogIndexPaginatesFromThePageContext(): void
    {
        $articles = [self::article('a', 'A'), self::article('b', 'B'), self::article('c', 'C')];

        $html = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'en', $articles, page: 2, perPage: 1),
        );

        $this->assertStringContainsString('je-blog-index__title', $html);
        $this->assertStringContainsString('je-blog-search', $html);
        $this->assertStringContainsString('href="/blog">1</a>', $html, 'Page 1 keeps the bare URL.');
        $this->assertStringContainsString('href="/blog?page=3">3</a>', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'The current page is announced, not only painted.');
        $this->assertStringNotContainsString('href="/blog?page=1"', $html, 'No second URL for the first page.');

        // Page 1 alone carries the lead marker, and it is a class on the
        // wrapper — never a second card renderer.
        $first = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'en', $articles, page: 1, perPage: 9),
        );
        $this->assertStringContainsString('je-blog-index__main--lead', $first);
        $this->assertStringNotContainsString('je-blog-index__main--lead', $html);
    }

    /**
     * A taxonomy page has no canonical to preserve, so its pager is `?page=`.
     */
    #[Test]
    public function taxonomyListingPaginatesWithThePageQuery(): void
    {
        $html = (new JednotkaTheme())->blogTags(
            PageContext::forBlogTags(
                Request::create('GET', '/blog/tags/security'),
                self::THEME,
                'en',
                ['security'],
                ['security' => 3],
                tag: 'security',
                articles: [self::article('a', 'A')],
                page: 1,
                perPage: 1,
                totalArticles: 3,
                totalPages: 3,
            ),
        );

        $this->assertStringContainsString('Unit 02 — Tag', $html);
        $this->assertStringContainsString('3 articles found.', $html);
        $this->assertStringContainsString('?page=2', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    /**
     * The contents rail is a SIBLING `<aside>` of the article, never a block
     * nested inside it: complementary navigation is a complementary landmark,
     * and nesting put it in `<main>` only by accident.
     */
    #[Test]
    public function articlePageKeepsTheContentsOutOfTheArticle(): void
    {
        $html = (new JednotkaTheme())->blogArticle(PageContext::forBlogArticle(
            Request::create('GET', '/blog/a'),
            self::THEME,
            'en',
            'a',
            'A title',
            '<p>body</p>',
            '12. 10. 2026',
            '',
            '',
            '',
            [['level' => 2, 'text' => 'Intro', 'anchor' => 'intro']],
            [],
        ));

        $articleEnd = strpos($html, '</article>');
        $toc = strpos($html, 'je-article-toc');
        $this->assertIsInt($articleEnd);
        $this->assertIsInt($toc);
        $this->assertGreaterThan($articleEnd, $toc, 'The contents rail is a sibling of the article, not a child.');
        $this->assertStringContainsString('data-toc-link data-heading="intro"', $html, 'The scrollspy contract.');
        $this->assertStringContainsString('<details class="je-article-toc__disclosure" open>', $html, '`open` is load-bearing.');
    }

    /**
     * The kernel's contract needs `errorContent`; the showcase class had none.
     * It must wear this theme's vocabulary, not a sibling's.
     */
    #[Test]
    public function errorContentWearsTheThemeVocabulary(): void
    {
        $html = (new JednotkaTheme())->errorContent(404, 'Not found', 'No such page.');

        $this->assertStringContainsString('404 Not found', $html);
        $this->assertStringContainsString('No such page.', $html);
        $this->assertStringContainsString('je-section__title', $html);

        foreach (['er-', 'mo-', 'st-'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'Foreign vocabulary in an error page.');
        }
    }

    /**
     * The design comparison, as a standing gate: every class this theme emits
     * has a rule in its own build — or is named below with the reason.
     *
     * All six are INHERITED from the showcase, none invented here: each one is
     * markup the original emitted while the stylesheet keys on something else
     * (an attribute, an inner element). The comparison that produced this list
     * is why the Blog-free card fallback no longer invents `je-blog-list` —
     * three classes with no rule anywhere, in a theme whose whole point is that
     * its markup is styled.
     *
     * @var array<string, string> class => why the design does not style it
     */
    private const array UNSTYLED_BY_DESIGN = [
        'je-nav__link--active' => 'the stylesheet keys the active state on aria-current="page"; the class variant is kept for the DOM-parity contract',
        'je-footer__nav' => 'the showcase wrapper; the design styles the inner list, item and link instead',
        'je-login-btn__text' => 'the design styles .je-login-btn; the label span is showcase markup',
        'je-blog-feed__link' => 'the design styles .je-blog-feed; the per-link class is showcase markup',
        'je-article-toc__item--sub' => 'showcase markup for a level-3 heading; the design styles the base item',
        'st-blog-index__list' => "Blog's own grid wrapper; the design styles .st-grid and the cards, not the grouping hook",
    ];

    #[Test]
    public function everyClassTheThemeEmitsIsStyledOrDeclared(): void
    {
        $css = $this->builtStylesheet();
        $emitted = [];

        foreach ($this->renderedSurfaces() as $name => $html) {
            foreach (self::classesOf($html) as $class) {
                $emitted[$class] = $name;
            }
        }

        $this->assertGreaterThan(80, count($emitted), 'Eleven surfaces, one vocabulary — a smaller set means a surface stopped rendering.');

        // The declared exemptions are checked in BOTH directions: an entry that
        // is no longer emitted, or that the design now styles, is a stale
        // exemption and must be dropped rather than left to rot.
        foreach (self::UNSTYLED_BY_DESIGN as $class => $reason) {
            $this->assertArrayHasKey($class, $emitted, sprintf('`%s` is declared unstyled but no longer emitted (%s) — drop the entry.', $class, $reason));
            $this->assertFalse(self::designStyles($css, $class), sprintf('`%s` is styled by the build now (%s) — drop the stale exemption.', $class, $reason));
        }

        $undeclared = [];

        foreach ($emitted as $class => $surface) {
            if (!self::designStyles($css, $class) && !isset(self::UNSTYLED_BY_DESIGN[$class])) {
                $undeclared[] = sprintf('%s (on %s)', $class, $surface);
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'Markup with no rule in the theme\'s own build — a class the design never learned: ' . implode(', ', $undeclared),
        );
    }

    /**
     * The Blog-free card grid is Blog's OWN markup contract, not this theme's
     * `je-card` (the homepage feature card — a different component) and not an
     * invented list. Pinned because the design comparison above is only as good
     * as the vocabulary it is comparing.
     */
    #[Test]
    public function blogGridFallsBackToTheDesignsGridVocabulary(): void
    {
        $html = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'en', [self::article('a', 'A')], page: 1, perPage: 9),
        );

        // Shapes the kernel handed over, not Blog entities — so the fallback drew it.
        $this->assertStringContainsString('class="st-blog-index__list st-grid"', $html);
        $this->assertStringContainsString('class="st-blog-index__card"', $html);
        $this->assertStringContainsString('class="st-blog-index__card-title"', $html);
        $this->assertStringContainsString('href="/blog/a">A</a>', $html);

        // The English locale keeps its own prefix, and no invented class survives.
        $en = (new JednotkaTheme())->blogIndex(
            PageContext::forBlogIndex(Request::create('GET', '/en/blog'), self::THEME, 'en_US', [self::article('a', 'A')], page: 1, perPage: 9),
        );
        $this->assertStringContainsString('href="/en/blog/a"', $en);
        $this->assertStringNotContainsString('je-blog-list', $html);
    }

    /**
     * The glyph choice is optional: without a stored value the theme keeps the
     * burger the header was ported with.
     */
    #[Test]
    public function theToggleKeepsTheBurgerByDefault(): void
    {
        foreach (['', 'auto'] as $stored) {
            $header = (new JednotkaTheme())->header(self::shellWithIcon($stored));

            $this->assertStringContainsString('<line x1="3" y1="6" x2="21" y2="6">', $header, 'stored=' . $stored);
            $this->assertStringNotContainsString('je-nav__toggle-icon--meatball', $header, 'stored=' . $stored);
        }
    }

    /**
     * The meatball is filled dots, not the stroke geometry the shell CSS
     * applies to every toggle SVG — the inline style is what keeps them from
     * rendering as three hollow rings.
     */
    #[Test]
    public function aStoredMeatballRendersThreeDotsOnTheSameLabel(): void
    {
        $header = (new JednotkaTheme())->header(self::shellWithIcon('meatball'));

        $this->assertStringContainsString('je-nav__toggle-icon--meatball', $header);
        $this->assertStringContainsString('<circle cx="12" cy="5" r="2">', $header);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="2">', $header);
        $this->assertStringContainsString('<circle cx="12" cy="19" r="2">', $header);
        $this->assertStringContainsString('style="fill:currentColor;stroke:none"', $header);
        $this->assertStringContainsString('je-nav__toggle-icon--close', $header, 'The open state stays the shared X.');
        $this->assertStringContainsString('id="je-nav-toggle"', $header);
        $this->assertStringContainsString('for="je-nav-toggle"', $header);
    }

    #[Test]
    public function theSchemaDeclaresTheNavIconChoice(): void
    {
        $schema = (new JednotkaTheme())->settingsSchema();

        $this->assertCount(1, $schema);
        $this->assertSame('nav_toggle_icon', $schema[0]->key);
        $this->assertSame('auto', $schema[0]->default, 'Unset keeps the theme\'s own burger.');
        $this->assertSame(['auto', 'burger', 'meatball'], array_keys($schema[0]->options));
    }

    /**
     * @param string $icon stored `nav_toggle_icon`; '' means "nothing stored"
     */
    private static function shellWithIcon(string $icon = ''): ShellContext
    {
        return ShellContext::from(
            'Site',
            [new ShellNavItem('Section', '/section', true)],
            [new ShellNavItem('Section', '/section')],
            '/section',
            null,
            'en_US',
            ['en_US'],
            false,
            '' === $icon ? [] : ['nav_toggle_icon' => $icon],
        );
    }

    /**
     * Every surface, rendered from the same shapes the kernel hands them.
     *
     * @return array<string, string>
     */
    private function renderedSurfaces(): array
    {
        $theme = new JednotkaTheme();
        $shell = static fn (bool $signedIn): ShellContext => ShellContext::from(
            'Nativa PHP Core',
            [new ShellNavItem('Blog', '/blog', true)],
            [new ShellNavItem('Blog', '/blog')],
            '/blog',
            null,
            'en_US',
            ['sk_SK', 'en_US'],
            $signedIn,
        );

        return [
            'homepage' => $theme->homepage(self::homepageContext('Nativa PHP Core')),
            'blogIndex' => $theme->blogIndex(PageContext::forBlogIndex(Request::create('GET', '/blog'), self::THEME, 'en', [self::article('a', 'A')], page: 1, perPage: 1)),
            'blogSearch' => $theme->blogSearch(PageContext::forBlogSearch(Request::create('GET', '/blog?q=x'), self::THEME, 'en', 'sqlite', [self::article('a', 'A')])),
            'blogArticle' => $theme->blogArticle(PageContext::forBlogArticle(
                Request::create('GET', '/blog/a'),
                self::THEME,
                'en',
                'a',
                'A title',
                '<p>body</p>',
                '12. 10. 2026',
                '',
                '',
                '',
                [['level' => 2, 'text' => 'Intro', 'anchor' => 'intro'], ['level' => 3, 'text' => 'Sub', 'anchor' => 'sub']],
                [],
                42,
                editUrl: '/mark/articles/a',
            )),
            'blogTags' => $theme->blogTags(PageContext::forBlogTags(Request::create('GET', '/blog/tags'), self::THEME, 'en', ['security'], ['security' => 2], null, [self::article('a', 'A')], totalArticles: 2, totalPages: 1)),
            'blogTagsFiltered' => $theme->blogTags(PageContext::forBlogTags(Request::create('GET', '/blog/tags/security'), self::THEME, 'en', ['security'], ['security' => 2], 'security', [self::article('a', 'A')], totalArticles: 2, totalPages: 3)),
            'blogCategories' => $theme->blogCategories(PageContext::forBlogCategories(Request::create('GET', '/blog/categories'), self::THEME, 'en', ['php'], ['php' => 1], null, [self::article('a', 'A')])),
            'authorProfile' => $theme->authorProfile(PageContext::forAuthorProfile(Request::create('GET', '/authors/x'), self::THEME, 'en', ['name' => 'Nataša', 'avatar' => '/img/a.png'], [self::article('a', 'A')], '2026-01-01', totalArticles: 2, totalPages: 2)),
            'errorContent' => $theme->errorContent(404, 'Not found', 'No such page.'),
            'header' => $theme->header($shell(true)),
            'footer' => $theme->footer($shell(false)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function classesOf(string $html): array
    {
        preg_match_all('/class="([^"]*)"/', $html, $matches);
        $classes = [];

        foreach ($matches[1] as $list) {
            foreach (preg_split('/\s+/', $list) ?: [] as $token) {
                if ('' !== $token) {
                    $classes[$token] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * Does the build carry a rule for this class?
     *
     * A TIGHT match: `.je-card` must not pass merely because `.je-card__title`
     * exists, which a plain `str_contains` would accept.
     */
    private static function designStyles(string $css, string $class): bool
    {
        return 1 === preg_match('/\.' . preg_quote($class, '/') . '(?![a-zA-Z0-9_-])/', $css);
    }

    private function builtStylesheet(): string
    {
        $manifest = AppPaths::instance()->public('dist/' . self::THEME . '/manifest.json');

        if (!is_file($manifest)) {
            $this->markTestSkipped('No `jednotka` build in public/dist/jednotka — run `make assets`.');
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $file = is_array($entries) ? ($entries['src/' . self::ENTRY . '.ts']['css'][0] ?? null) : null;

        if (!is_string($file)) {
            $this->markTestSkipped(sprintf('The `jednotka` build declares no CSS for entry `%s`.', self::ENTRY));
        }

        return (string) file_get_contents(AppPaths::instance()->public('dist/' . self::THEME . '/' . $file));
    }

    /**
     * A missing manifest on DISK is a build that has not run, not a defect —
     * `public/dist/` is gitignored. Skips with the command to run.
     *
     * The guard is the file, never `AssetManager::isAvailable()`: that call is
     * exactly what the defect breaks, so guarding on it turns the regression
     * into a skip instead of a failure.
     */
    private function requireBuiltBundle(): AssetManager
    {
        $manifest = AppPaths::instance()->public('dist/' . self::THEME . '/manifest.json');

        if (!is_file($manifest)) {
            $this->markTestSkipped('No `jednotka` build in public/dist/jednotka — run `make assets`.');
        }

        $this->assertContains(
            self::THEME,
            AssetManager::knownBundles(),
            'The build is on disk but the bundle is unknown, so AssetManager reads the kernel manifest instead.',
        );

        $assets = new AssetManager(self::THEME);

        $this->assertTrue(
            $assets->isAvailable(),
            'The `jednotka` build exists on disk but AssetManager did not resolve its manifest.',
        );

        return $assets;
    }

    private function assetPath(string $url): string
    {
        return AppPaths::instance()->public(ltrim($url, '/'));
    }

    private static function article(string $slug, string $title): object
    {
        $article = new \stdClass();
        $article->slug = $slug;
        $article->title = $title;
        $article->tags = ['security'];
        $article->excerpt = 'First words.';

        return $article;
    }

    private static function homepageContext(string $title): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'en',
            copy: ['title' => $title],
        );
    }

    /** The body of the single `<style>` the layout emits, without its trailing newline. */
    private function styleBlock(string $html): string
    {
        $matched = preg_match('~<style nonce="[^"]*">\n(.*?)\n</style>~s', $html, $matches);

        $this->assertSame(1, $matched, 'The document has no inlined <style> block.');

        return $matches[1];
    }
}
