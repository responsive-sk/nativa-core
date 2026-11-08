<?php

declare(strict_types = 1);

namespace Modules\Themes\Erik\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Themes\Erik\Theme\ErikTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ErikThemeTest (Boot 22) — the first theme this tree did not ship, and the
 * asset defect it was the first to hit.
 *
 * `erik` renders correctly and loads nothing. That is the whole failure: with
 * `AssetManager::knownBundles()` a literal list of the KERNEL's themes, the
 * theme resolved its assets as bundle `kernel`, looked for
 * `public/dist/kernel/manifest.json` (absent — the kernel theme is hand-written
 * and has no build), and inlined the 706-char critical subset where its own
 * build has 35 KB. HTTP 200, right markup, wrong stylesheet, empty log.
 *
 * So this file pins the three things that made it invisible:
 *
 * - the bundle each discovered theme declares is a KNOWN bundle (the list is
 *   derived from the runtime theme set, not written out a fourth time);
 * - the layout inlines the theme's own BUILD, byte for byte, and not the
 *   critical fallback;
 * - the document wears the theme's own vocabulary and no sibling's.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Erik is a module. A kernel-only tree has no ErikTheme to construct, so the
 * suite SKIPS rather than fails — the same contract TaxonomyRegistryTest and
 * KernelOnlyBootTest use. `tests/` is inside PHPStan's scope and `modules/` is
 * not, so a test naming an optional module's class cannot make the analyser of
 * a tree without that module fail.
 */
final class ErikThemeTest extends TestCase
{
    private const string MODULE = 'Erik';

    private const string THEME = 'erik';

    /** The markup prefix the theme owns (`er-`), against its siblings'. */
    private const string PREFIX = 'er';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the `erik` theme ships with this module.');
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
        $this->assertTrue(ThemeRegistry::has(self::THEME), 'Erik ships a theme; discovery did not find it.');

        $theme = ThemeRegistry::get(self::THEME);

        $this->assertInstanceOf(ErikTheme::class, $theme);
        $this->assertSame(self::THEME, $theme->name());
        $this->assertSame(self::THEME, $theme->bundle());

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
     * The regression itself, measured: the bytes in the document are the bytes
     * in `public/dist/erik/assets/erik-*.css`.
     */
    #[Test]
    public function layoutInlinesTheBuiltStylesheetAndNotTheCriticalSubset(): void
    {
        $assets = $this->requireBuiltBundle();

        $build = (string) file_get_contents($this->assetPath($assets->cssUrls(self::THEME)[0]));

        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);
        $inlined = $this->styleBlock($html);

        $this->assertSame(
            $build . "\n",
            $inlined,
            'The inlined CSS is not the theme build — the layout resolved another bundle.'
            . ' (The critical subset is ' . strlen(InlineCss::files((new ErikTheme())->criticalCss())) . ' chars.)',
        );

        // The fallback is what the defect served, so name it explicitly: an
        // equal-bytes assertion on the build is enough only as long as the
        // fallback is genuinely smaller — which this says out loud.
        $this->assertNotSame(
            InlineCss::files((new ErikTheme())->criticalCss()),
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
    public function documentWearsTheErikVocabularyAndNoSiblings(): void
    {
        $html = FrontendLayout::render('Test', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString('<body class="' . self::THEME . '-shell">', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-header"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-main"', $html);
        $this->assertStringContainsString('class="' . self::PREFIX . '-footer"', $html);

        foreach (['k-header', 'k-footer', 'mo-header', 'mo-footer'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, 'Another theme reached this document: ' . $foreign);
        }
    }

    /**
     * The one assertion that has to survive the module set changing: whichever
     * theme the providers vote for, the live surface serves THAT theme's build.
     *
     * Written against the resolver's answer rather than against `erik`, so
     * installing another theme provider re-points the test instead of breaking
     * it — while the defect (a page carrying the kernel fallback) still fails.
     */
    #[Test]
    public function liveSurfacesInlineTheActiveThemesOwnBuild(): void
    {
        $active = ThemeResolver::resolvePath('/');
        $theme = ThemeKernel::for($active);

        $this->assertContains(
            $theme->name(),
            AssetManager::knownBundles(),
            sprintf('The active theme `%s` is not a known asset bundle.', $theme->name()),
        );

        $assets = new AssetManager($theme->name());

        if (!$assets->isAvailable()) {
            $this->markTestSkipped(sprintf('No build for bundle `%s` — run `make assets`.', $theme->name()));
        }

        $build = (string) file_get_contents($this->assetPath($assets->cssUrls($theme->jsKey())[0]));

        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            $build,
            $response->getContent(),
            sprintf('The live homepage does not serve the `%s` build — it fell back to critical CSS.', $theme->name()),
        );
    }

    /**
     * The minimal home: hero with eyebrow, title, lede and both actions,
     * four eyebrowed sections in order, the proof quote and the closing CTA.
     * Copy-driven (showcase strings by default), actions pointing at the
     * blog and the kernel admin surface.
     */
    #[Test]
    public function homepageRendersTheMinimalSectionsInOrder(): void
    {
        $html = (new ErikTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('<section class="er-hero">', $html);
        $this->assertStringContainsString('er-hero__eyebrow', $html);
        $this->assertStringContainsString('<h1 class="er-hero__title">Tailored start</h1>', $html);
        $this->assertStringContainsString('er-hero__visual', $html, 'The line-art visual is inline SVG, no external asset.');
        $this->assertStringNotContainsString('.webp', $html, 'The showcase WebP underlay stays out of the kernel port.');
        $this->assertStringContainsString('href="/blog">Read the blog</a>', $html);
        $this->assertStringContainsString('href="/mark">Open admin</a>', $html);

        foreach (['er-section--features', 'er-section--steps', 'er-section--news', 'er-section--comparison'] as $modifier) {
            $this->assertStringContainsString($modifier, $html);
        }

        $features = strpos($html, 'er-section--features');
        $steps = strpos($html, 'er-section--steps');
        $news = strpos($html, 'er-section--news');
        $comparison = strpos($html, 'er-section--comparison');
        $cta = strpos($html, 'er-closing-cta');
        $this->assertIsInt($features);
        $this->assertLessThan($steps, $features);
        $this->assertLessThan($news, $steps);
        $this->assertLessThan($comparison, $news);
        $this->assertLessThan($cta, $comparison);

        $this->assertSame(3, substr_count($html, 'er-feature-card">'), 'Three feature cards.');
        $this->assertSame(3, substr_count($html, '<article class="er-step">'), 'Three steps.');
        $this->assertStringContainsString('er-proof__quote', $html);
        $this->assertSame(3, substr_count($html, 'er-comparison__row">'), 'Three comparison rows.');
        $this->assertStringContainsString('Explore the blog', $html);
    }

    /**
     * News cards map slug+title (required) and excerpt/date/category/author
     * image (optional) — the kernel HomepageAction passes no articles, so
     * every field beyond the pair must degrade, never fatal.
     */
    #[Test]
    public function homepageNewsMapsTheArticleShape(): void
    {
        $full = new \stdClass();
        $full->slug = 'alpha';
        $full->title = 'Alpha';
        $full->excerpt = 'First words.';
        $full->date = '2026-10-12';
        $full->category = 'Architektúra';
        $full->author = 'Nativa Dev';
        $full->image = '/img/alpha.svg';

        $html = (new ErikTheme())->homepage(self::homepageContext([$full, self::article('beta', 'Beta')]));

        $this->assertSame(2, substr_count($html, '<article class="er-news-card">'));
        $this->assertStringContainsString('src="/img/alpha.svg"', $html);
        $this->assertStringContainsString('Architektúra · 12. 10. 2026 · Nativa Dev', $html, 'Meta bits join with middots, date numeric.');
        $this->assertStringContainsString('First words.', $html);
        $this->assertStringContainsString('er-news-card__placeholder', $html, 'No image, placeholder span.');
        $this->assertStringContainsString('href="/blog/beta">Beta</a>', $html);
    }

    #[Test]
    public function homepageNewsEmptyStateKeepsTheSections(): void
    {
        // Articles without slug+title are skipped, never fatal — and an
        // all-skipped list must still render the placeholder, not an empty
        // grid. (Passing [] would take the latestFallback path into the real
        // Blog, so emptiness is forced with shapes the mapper rejects.)
        $html = (new ErikTheme())->homepage(self::homepageContext([new \stdClass()]));

        $this->assertStringContainsString('Nothing published yet.', $html);
        $this->assertStringNotContainsString('er-news-card">', $html);
        $this->assertStringContainsString('er-section--news', $html, 'The section survives an empty grid.');
        $this->assertStringContainsString('er-section--features', $html);
    }

    /**
     * Surfaces return bare sections: FrontendLayout owns the single wrap,
     * so a homepage that wraps itself nests <main> (the double-wrap the
     * older surfaces still carry — this one must not).
     */
    #[Test]
    public function homepageDoesNotWrapItself(): void
    {
        $html = (new ErikTheme())->homepage(self::homepageContext());

        $this->assertStringNotContainsString('<main', $html, 'One wrap, owned by FrontendLayout — never two.');
        $this->assertStringContainsString('<section class="er-hero">', $html);
    }

    /**
     * The home surface's OWN keys win — over the bare keys AND over the theme's
     * defaults — because those are the rows the translation admin edits.
     *
     * Measured 2026-10-17, with the site switched to `mono`: on a `sk_SK` site
     * this hero answered `NATIVA MINIMAL`, the SITE NAME as its `<h1>` and the
     * English lede, while `home.title` held `Postavte Niečo Skvelé` in the same
     * database. Nothing failed; the rows were simply unread by every theme.
     */
    #[Test]
    public function theHomeGroupKeysBeatTheBareKeysAndTheDefaults(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'erik',
            'sk_SK',
            copy: [
                'title'          => 'the site name',
                'lede'           => 'bare lede',
                'hero_eyebrow'   => 'BARE EYEBROW',
                'home.eyebrow'   => 'Nativa Minimal',
                'home.title'     => 'Postavte Niečo Skvelé',
                'home.message'   => 'Malý a čitateľný základ.',
                'home.cta_blog'  => 'Prejsť na blog',
                'home.cta_admin' => 'Otvoriť admin',
            ],
            blogHref: '/blog',
        );

        $html = (new ErikTheme())->homepage($context);

        $this->assertStringContainsString('<p class="er-hero__eyebrow">Nativa Minimal</p>', $html);
        $this->assertStringContainsString('<h1 class="er-hero__title">Postavte Niečo Skvelé</h1>', $html);
        $this->assertStringContainsString('Malý a čitateľný základ.', $html);
        $this->assertStringContainsString('>Prejsť na blog</a>', $html, 'The primary action reads home.cta_blog, not its own English label.');
        $this->assertStringContainsString('>Otvoriť admin</a>', $html, 'The secondary action reads home.cta_admin.');
        $this->assertStringNotContainsString('Explore the blog', $html, 'The closing CTA button reads the same key as the hero\'s primary action — one link, one label.');
        $this->assertStringNotContainsString('BARE EYEBROW', $html, 'The bare key must be shadowed, not merged.');
        $this->assertStringContainsString('Build the part that matters.', $html, 'The closing section\'s own design copy has no row and stays as written.');
    }

    /**
     * ...and the floor is intact: with no row for a key the theme answers
     * exactly what it answered before this change — the bare key if the caller
     * passed one (the kernel passes the site name as `title`), the showcase
     * string if it did not.
     */
    #[Test]
    public function withoutTheGroupTheBareKeysAndTheDefaultsStillAnswer(): void
    {
        $html = (new ErikTheme())->homepage(self::homepageContext());

        $this->assertStringContainsString('<h1 class="er-hero__title">Tailored start</h1>', $html, 'The bare key still answers when the group carries nothing.');
        $this->assertStringContainsString('>Read the blog</a>', $html, 'With no row for home.cta_blog the theme\'s own label stands.');

        $bare = (new ErikTheme())->homepage(
            PageContext::forHomepage(Request::create('GET', '/'), 'erik', 'en'),
        );

        $this->assertStringContainsString('<h1 class="er-hero__title">Fewer layers. More control.</h1>', $bare, 'With neither, the showcase string is the floor.');
        $this->assertStringContainsString('<p class="er-hero__eyebrow">NATIVA MINIMAL</p>', $bare);
    }

    #[Test]
    public function homepageEscapesUntrustedCopy(): void
    {
        $context = PageContext::forHomepage(
            Request::create('GET', '/'),
            'erik',
            'en',
            copy: ['title' => 'A & B <script>', 'lede' => '"quoted"'],
        );

        $html = (new ErikTheme())->homepage($context);

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;quoted&quot;', $html);
        $this->assertStringNotContainsString('<script>A', $html);

        // The group's rows travel the same path and are user input by
        // definition — they are what the translation admin types.
        $group = (new ErikTheme())->homepage(
            PageContext::forHomepage(
                Request::create('GET', '/'),
                'erik',
                'sk_SK',
                copy: ['home.title' => 'A & B <script>', 'home.cta_blog' => '"quoted"'],
            ),
        );

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $group);
        $this->assertStringContainsString('&quot;quoted&quot;', $group);
        $this->assertStringNotContainsString('<script>A', $group);
    }

    /**
     * Every er-* class the home surface emits has a rule in the vendored
     * stylesheet — the Mono article-view audit as a standing assertion, so
     * the next markup addition without its rule fails here, not in a
     * browser. Structural element selectors (h3, p, span) need none.
     */
    #[Test]
    public function homepageVocabularyIsCoveredByTheStylesheet(): void
    {
        $html = (new ErikTheme())->homepage(self::homepageContext());
        $css = InlineCss::file('Modules/Erik/assets/critical/erik.css');

        // Class attributes only: the hero SVG carries an id="er-glow"
        // gradient reference, which is an identifier, not a class needing
        // a rule — a bare er- scan would cry wolf on it.
        preg_match_all('/class="([^"]*)"/', $html, $matches);

        $classes = [];

        foreach ($matches[1] as $list) {
            foreach (preg_split('/\s+/', $list) ?: [] as $token) {
                if (str_starts_with($token, 'er-')) {
                    $classes[] = $token;
                }
            }
        }

        $classes = array_unique($classes);

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $this->assertStringContainsString(
                '.' . $class,
                $css,
                sprintf('Homepage emits `%s` with no rule — unstyled markup.', $class),
            );
        }
    }

    #[Test]
    public function homepageLeaksNoForeignVocabulary(): void
    {
        $html = (new ErikTheme())->homepage(self::homepageContext());

        foreach (['mo-', 'st-', 'gr-', 'k-header', 'k-footer'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html, sprintf('Foreign vocabulary `%s` in erik output.', $foreign));
        }
    }

    /**
     * The article surface: the headings the renderer already anchored become
     * the "On this page" rail, the rail sits beside the prose in the theme's
     * own grid, and the kernel's reader layout steps aside for it.
     *
     * The rail used to be stylesheet-only: `er-toc*` rules and a scrollspy
     * shipped in the bundle, while `blogArticle()` emitted neither, and the
     * scrollspy's own header comment recorded erik as the theme whose links
     * carried no `data-heading`. A rule the reader cannot see is not a feature.
     */
    #[Test]
    public function theArticleRailIsBuiltFromTheRenderersHeadings(): void
    {
        $headings = [
            ['level' => 2, 'text' => 'What it was before', 'anchor' => 'what-it-was-before'],
            ['level' => 2, 'text' => 'The contract', 'anchor' => 'the-contract'],
            ['level' => 3, 'text' => 'The connector', 'anchor' => 'the-connector'],
        ];

        $html = (new ErikTheme())->blogArticle(self::articleContext($headings));

        $this->assertSame(1, substr_count($html, 'aria-label="On this page"'), 'One rail, never two.');
        $this->assertStringContainsString('<div class="er-article-layout">', $html);
        $this->assertStringContainsString('<aside class="er-toc" aria-label="On this page">', $html);
        $this->assertLessThan(
            strpos($html, 'er-toc__nav'),
            strpos($html, 'er-article__body'),
            'The rail follows the prose it describes.',
        );

        $this->assertSame(3, substr_count($html, 'data-toc-link'), 'Every heading stays reachable.');
        $this->assertStringContainsString('href="#the-contract"', $html);
        $this->assertStringContainsString(
            'data-heading="the-connector"',
            $html,
            'The scrollspy pairs a link with its heading by data-heading — without it the rail never highlights.',
        );

        $wrapped = FrontendLayout::readerLayout($html, $headings);

        $this->assertSame(
            $html,
            $wrapped,
            'The kernel wrapped a rail the theme already composed: the page would render two tables of contents.',
        );
    }

    /**
     * Fewer than two headings is no rail and no grid: a 15rem column holding a
     * single link narrows the prose to show it, which is the article reading
     * worse for having a table of contents.
     */
    #[Test]
    public function aLoneHeadingGetsNoRailAndTheFullMeasure(): void
    {
        $html = (new ErikTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'Only one', 'anchor' => 'only-one'],
        ]));

        $this->assertStringNotContainsString('er-toc', $html, 'One entry is furniture.');
        $this->assertStringNotContainsString('er-article-layout', $html, 'No rail, no grid.');
        $this->assertStringContainsString('<article class="er-article">', $html);
    }

    #[Test]
    public function theRailEscapesHeadingTextItDidNotWrite(): void
    {
        $html = (new ErikTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'A & B <script>', 'anchor' => 'a-b'],
            ['level' => 2, 'text' => 'Second', 'anchor' => 'second'],
        ]));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    /**
     * The markup above is what a tree WITHOUT a built bundle renders (the
     * critical subset is the fallback), so the fallback has to carry a rule for
     * every class the article emits — the Mono audit's assertion, applied to
     * the one surface it could not see before.
     */
    #[Test]
    public function theArticleVocabularyIsCoveredByTheCriticalStylesheet(): void
    {
        $html = (new ErikTheme())->blogArticle(self::articleContext([
            ['level' => 2, 'text' => 'One', 'anchor' => 'one'],
            ['level' => 2, 'text' => 'Two', 'anchor' => 'two'],
        ]));

        $css = InlineCss::file('Modules/Erik/assets/critical/erik.css');

        preg_match_all('/class="([^"]*)"/', $html, $matches);

        $classes = [];

        foreach ($matches[1] as $list) {
            foreach (preg_split('/\s+/', $list) ?: [] as $token) {
                if (str_starts_with($token, 'er-')) {
                    $classes[] = $token;
                }
            }
        }

        $classes = array_unique($classes);

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $this->assertStringContainsString(
                '.' . $class,
                $css,
                sprintf('The article emits `%s` with no rule in the critical subset — unstyled markup in a tree with no build.', $class),
            );
        }
    }

    /**
     * The article payload. The locale is the tree's configured default rather
     * than a spelled code: the rail is locale-neutral, and a hardcoded one is a
     * literal the name-ownership gate counts as a duplicate of the mapper's own
     * value (measured — it moved the ceiling by exactly one).
     *
     * @param list<array{level: int, text: string, anchor: string}> $headings
     */
    private static function articleContext(array $headings): PageContext
    {
        return PageContext::forBlogArticle(
            Request::create('GET', '/blog/what-it-was-before'),
            self::THEME,
            ContentServices::mapper()->defaultLocale(),
            'what-it-was-before',
            'What it was before',
            '<p>Body</p>',
            '',
            '',
            '',
            '',
            $headings,
            [],
        );
    }

    /**
     * A missing manifest on DISK is a build that has not run, not a defect —
     * `public/dist/` is gitignored. Skips with the command to run.
     *
     * The guard is the file, never `AssetManager::isAvailable()`: that call is
     * exactly what the defect breaks, so guarding on it turns the regression
     * into a skip instead of a failure — measured, and the reason this helper
     * reads the path itself before the assertions that follow.
     */
    private function requireBuiltBundle(): AssetManager
    {
        $manifest = AppPaths::instance()->public('dist/' . self::THEME . '/manifest.json');

        if (!is_file($manifest)) {
            $this->markTestSkipped('No `erik` build in public/dist/erik — run `make assets`.');
        }

        $this->assertContains(
            self::THEME,
            AssetManager::knownBundles(),
            'The build is on disk but the bundle is unknown, so AssetManager reads the kernel manifest instead.',
        );

        $assets = new AssetManager(self::THEME);

        $this->assertTrue(
            $assets->isAvailable(),
            'The `erik` build exists on disk but AssetManager did not resolve its manifest.',
        );

        return $assets;
    }

    private function assetPath(string $url): string
    {
        return AppPaths::instance()->public(ltrim($url, '/'));
    }

    /**
     * The blog's search control lives in the page, because this shell has none:
     * without it `/blog` is searchable only by typing `?q=` by hand.
     */
    #[Test]
    public function theBlogIndexCarriesASearchField(): void
    {
        $html = (new ErikTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('action="/blog"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    #[Test]
    public function theBlogSearchCarriesTheQueryEscaped(): void
    {
        $html = (new ErikTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog?q=%3Cb%3E'),
            self::THEME,
            'sk',
            '<b>',
            [self::article('a', 'Alpha')],
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
        $english = (new ErikTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'en_US',
            [self::article('a', 'Alpha')],
        ));
        $slovak = (new ErikTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk_SK',
            [self::article('a', 'Alpha')],
        ));

        $this->assertStringContainsString('action="/en/blog"', $english);
        $this->assertStringNotContainsString('action="/blog"', $english);
        $this->assertStringContainsString('action="/blog"', $slovak);
    }

    private static function article(string $slug, string $title): object
    {
        $article = new \stdClass();
        $article->slug = $slug;
        $article->title = $title;

        return $article;
    }

    /**
     * @param list<object> $articles
     */
    private static function homepageContext(array $articles = []): PageContext
    {
        return PageContext::forHomepage(
            Request::create('GET', '/'),
            'erik',
            'en',
            copy: ['title' => 'Tailored start', 'lede' => 'Begin small.', 'hero_eyebrow' => 'PROOF'],
            blogHref: '/blog',
            latestArticles: $articles,
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
