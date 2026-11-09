<?php

declare(strict_types = 1);

namespace Modules\Themes\Nativa\Tests;

use Shell\ShellNavItem;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Themes\FrontendLayout;
use Shell\PageContext;
use Shell\ShellContext;
use Themes\ThemeKernel;
use Modules\Themes\Nativa\Theme\NativaTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NativaThemeTest — the house theme's contract and its surfaces.
 *
 * The theme is a MODULE, so a kernel-only tree has no class to instantiate and
 * the suite skips rather than fails — the same guard every module-carried
 * theme opens with. It lives beside the code for that reason, not under
 * `tests/`, which is inside PHPStan's scope.
 *
 * What is measured here is the part a theme cannot get wrong silently:
 *
 * - the four names that must agree (`name`, `bundle`, `prefix`, the critical
 *   path) because the ownership and bundle gates read them;
 * - that user input is escaped on every surface that echoes it, while the
 *   fields documented as raw HTML stay raw;
 * - that the surfaces Boson is the only other theme to answer — `/docs` and
 *   the auth page — actually answer here, since the grid and the login form
 *   are the reason this theme exists at all.
 */
final class NativaThemeTest extends TestCase
{
    private const string THEME = 'nativa';

    protected function setUp(): void
    {
        if (!class_exists(NativaTheme::class)) {
            $this->markTestSkipped('Nativa is not installed — the theme under test is this module.');
        }
    }

    #[Test]
    public function theFourNamesAgree(): void
    {
        $theme = new NativaTheme();

        $this->assertSame(self::THEME, $theme->name());
        $this->assertSame(self::THEME, $theme->bundle());
        $this->assertSame(self::THEME, $theme->jsKey());
        $this->assertSame('na', $theme->prefix());
    }

    #[Test]
    public function theDeclaredStylesheetExistsAndIsTheOnlyAsset(): void
    {
        $theme = new NativaTheme();

        $this->assertCount(1, $theme->criticalCss(), 'The theme is one stylesheet by design: no build, no second file.');
        $this->assertFileExists(AppPaths::instance()->moduleFile($theme->criticalCss()[0]));
    }

    #[Test]
    public function theHeaderCarriesTheMenuAndEscapesTheSiteName(): void
    {
        $html = (new NativaTheme())->header(ShellContext::from(
            siteName: 'A & B <script>',
            headerNav: [new ShellNavItem('Blog', '/blog', active: true)],
            footerNav: [],
            currentPath: '/blog',
            localeLinks: null,
            activeLocale: 'sk',
            availableLocales: ['sk'],
            signedIn: false,
        ));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('class="na-nav__link" href="/blog" aria-current="page"', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    #[Test]
    public function theShellClassIsSpelledWithTheBundleAndTheStylesheetIsInlined(): void
    {
        if (ThemeKernel::for(self::THEME)->name() !== self::THEME) {
            $this->markTestSkipped('Nativa is not installed, so the layout cannot render with it.');
        }

        $html = FrontendLayout::render('T', '<p>x</p>', theme: self::THEME);

        $this->assertStringContainsString('<body class="nativa-shell">', $html);
        $this->assertStringContainsString('.na-header', $html, 'The critical stylesheet is not inlined, so the theme would render unstyled.');
    }

    #[Test]
    public function theHomepageEscapesItsCopyAndLinksTheBlog(): void
    {
        $html = (new NativaTheme())->homepage(PageContext::forHomepage(
            Request::create('GET', '/'),
            self::THEME,
            'sk',
            copy: ['home.title' => 'A & B <script>', 'home.cta_start' => '"quoted"'],
            blogHref: '/blog',
        ));

        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;quoted&quot;', $html);
        $this->assertStringContainsString('href="/blog"', $html);
        $this->assertStringNotContainsString('<script>A', $html);
    }

    #[Test]
    public function theBlogIndexCarriesTheSearchControlOnTheLocaleBase(): void
    {
        $html = (new NativaTheme())->blogIndex(PageContext::forBlogIndex(
            Request::create('GET', '/blog'),
            self::THEME,
            'sk',
            [],
        ));

        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('action="/blog"', $html);
        $this->assertStringContainsString('Nothing published yet.', $html, 'An empty corpus renders the empty state, not a bare heading.');
    }

    #[Test]
    public function theSearchEchoesTheQueryEscaped(): void
    {
        $html = (new NativaTheme())->blogSearch(PageContext::forBlogSearch(
            Request::create('GET', '/blog', ['q' => 'prose"><script>alert(1)</script>']),
            self::THEME,
            'sk',
            'prose"><script>alert(1)</script>',
            [],
        ));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('value="prose&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    #[Test]
    public function theArticlePassesTheRenderedBodyThroughAndBuildsTheRail(): void
    {
        $html = (new NativaTheme())->blogArticle(PageContext::forBlogArticle(
            Request::create('GET', '/blog/a'),
            self::THEME,
            'sk',
            'a',
            'Title <script>',
            '<p>Rendered <strong>body</strong></p>',
            '<span>meta</span>',
            '',
            '',
            '',
            [['level' => 2, 'text' => 'First', 'anchor' => 'first']],
            [],
        ));

        $this->assertStringContainsString('<p>Rendered <strong>body</strong></p>', $html, 'bodyHtml is raw by contract.');
        $this->assertStringContainsString('<span>meta</span>', $html, 'metaLine is raw by contract.');
        $this->assertStringContainsString('Title &lt;script&gt;', $html);
        $this->assertStringContainsString('data-heading="first"', $html);
        $this->assertStringContainsString('class="na-toc"', $html);
    }

    #[Test]
    public function theDocsIndexLinksOnlyPublishedKeysAndSaysWhenThereAreNone(): void
    {
        $empty = (new NativaTheme())->docsIndex([]);
        $this->assertStringContainsString('No guides are published in this deployment.', $empty);

        $html = (new NativaTheme())->docsIndex([
            ['key' => 'intro', 'title' => 'Intro', 'description' => 'Where to start.'],
        ]);

        $this->assertStringContainsString('href="/docs/intro"', $html);
        $this->assertStringContainsString('Where to start.', $html);
    }

    #[Test]
    public function theDocsPageEscapesItsTitleAndKeepsTheBodyHtml(): void
    {
        $html = (new NativaTheme())->docsPage('intro', 'A & B', '<p>body</p>');

        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('<p>body</p>', $html);
    }

    #[Test]
    public function theAuthPageEscapesInputAndKeepsTheHiddenFieldsRaw(): void
    {
        $html = (new NativaTheme())->authPage(
            'Sign in',
            '/mark/login',
            'Sign in',
            [['name' => 'email', 'type' => 'email', 'value' => '"><script>', 'required' => true]],
            '<input type="hidden" name="_csrf" value="tok">',
            ['Bad <credentials>'],
            '<a href="/mark/forgot">Forgot?</a>',
            'Alert & text',
        );

        $this->assertStringContainsString('<input type="hidden" name="_csrf" value="tok">', $html, 'hiddenHtml is trusted HTML.');
        $this->assertStringContainsString('<a href="/mark/forgot">Forgot?</a>', $html, 'The footnote is a static link line, not user input.');
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $html);
        $this->assertStringContainsString('Bad &lt;credentials&gt;', $html);
        $this->assertStringContainsString('Alert &amp; text', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function theErrorSectionCarriesItsStatus(): void
    {
        $html = (new NativaTheme())->errorContent(404, 'Not found', 'There is nothing at this address.');

        $this->assertStringContainsString('Error 404', $html);
        $this->assertStringContainsString('Not found', $html);
        $this->assertStringContainsString('There is nothing at this address.', $html);
        $this->assertStringContainsString('href="/"', $html);
    }
}
