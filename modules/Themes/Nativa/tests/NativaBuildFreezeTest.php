<?php

declare(strict_types = 1);

namespace Modules\Themes\Nativa\Tests;

use Themes\AssetManager;
use View\InlineCss;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Themes\Nativa\Theme\NativaTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NativaBuildFreezeTest — the frozen `nativa` bundle is committed, resolves,
 * and stays a single Chrome-70-safe file inside its size budget.
 *
 * `public/dist/` is gitignored everywhere EXCEPT `dist/nativa/` (see the
 * freeze exception in .gitignore): a deploy without Node serves these bytes,
 * so their absence is a defect, not a build that has not run — nothing here
 * skips when the manifest is missing.
 *
 * The theme was build-free first, and the bundle does not change that: its
 * stylesheet is an import of `modules/Themes/Nativa/assets/critical/nativa.css`,
 * so the assertion that the build covers every fallback class is a statement
 * about one file, not two. What the build adds is the scrollspy and the
 * manifest. Edit the bundle only by rebuilding (`make assets`), never by hand.
 */
final class NativaBuildFreezeTest extends TestCase
{
    /** The bundle name, read from its owner — never a second copy of it. */
    private static function bundle(): string
    {
        return (new NativaTheme())->name();
    }

    /**
     * Budgets with headroom over the freezing build: one small behaviour, no
     * framework runtime, no highlighter. The ceiling is here to fail when a
     * library is imported into the entry, not to pin bytes.
     */
    private const int JS_BUDGET = 6000;

    /** The stylesheet is the module's own file plus minification headroom. */
    private const int CSS_BUDGET = 24000;

    protected function setUp(): void
    {
        if (!class_exists(NativaTheme::class)) {
            $this->markTestSkipped('Nativa is not installed — the `nativa` theme ships with this module.');
        }
    }

    #[Test]
    public function theFrozenManifestIsCommitted(): void
    {
        $this->assertFileExists(
            AppPaths::instance()->public('dist/' . self::bundle() . '/manifest.json'),
            'The frozen nativa manifest is not committed — rebuild it (`make assets`), never by hand.',
        );
    }

    #[Test]
    public function assetManagerResolvesTheFrozenEntry(): void
    {
        $assets = new AssetManager(self::bundle());

        $this->assertTrue($assets->isAvailable(), 'AssetManager did not resolve the committed nativa manifest.');

        $jsUrl = $assets->jsUrl(self::bundle());

        $this->assertNotNull($jsUrl, 'The frozen manifest names no `nativa` JS entry.');
        $this->assertFileExists(
            AppPaths::instance()->public(ltrim($jsUrl, '/')),
            'The manifest points at a JS file that is not committed.',
        );

        $cssUrls = $assets->cssUrls(self::bundle());

        $this->assertNotSame([], $cssUrls, 'The frozen manifest names no `nativa` CSS.');

        foreach ($cssUrls as $url) {
            $this->assertFileExists(
                AppPaths::instance()->public(ltrim($url, '/')),
                sprintf('The manifest points at a CSS file that is not committed: %s.', $url),
            );
        }
    }

    /**
     * One self-contained file per entry: a shared chunk would emit `import`
     * statements, and the deferred `<script>` (no `type="module"`) cannot
     * execute them on the Chrome 70 baseline.
     */
    #[Test]
    public function theFrozenScriptIsASingleFileWithNoModuleSyntax(): void
    {
        $js = $this->frozenJs();

        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^.\w])import[\s(]/',
            $js,
            'The frozen bundle carries an import — a shared chunk leaked in, and Chrome 70 cannot execute it.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^.\w])export[\s{]/',
            $js,
            'The frozen bundle carries an export — it is a module, not a classic script.',
        );
    }

    #[Test]
    public function theFrozenBundleHoldsItsSizeBudget(): void
    {
        $js = $this->frozenJs();
        $css = $this->frozenCss();

        $this->assertLessThanOrEqual(
            self::JS_BUDGET,
            strlen($js),
            sprintf('Frozen nativa JS is %d bytes over a %d budget — a library was imported into the entry.', strlen($js), self::JS_BUDGET),
        );
        $this->assertLessThanOrEqual(
            self::CSS_BUDGET,
            strlen($css),
            sprintf('Frozen nativa CSS is %d bytes over a %d budget.', strlen($css), self::CSS_BUDGET),
        );
    }

    /**
     * The built stylesheet IS the critical fallback: the package imports the
     * module's file instead of carrying a copy, so every `na-` class the
     * no-build page keeps must still be in the bundle. A class that goes
     * missing here is a page that loses rules the moment a manifest appears.
     */
    #[Test]
    public function theBuiltStylesheetCoversEveryCriticalFallbackClass(): void
    {
        $fallback = InlineCss::file((new NativaTheme())->criticalCss()[0]);
        $css = $this->frozenCss();

        preg_match_all('/\.na-[a-z0-9_-]+/', $fallback, $matches);

        $classes = array_values(array_unique($matches[0]));

        $this->assertNotSame([], $classes, 'The fallback file declares no na- class — the extractor is broken.');

        $missing = [];

        foreach ($classes as $class) {
            if (!str_contains($css, $class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Built nativa CSS is missing fallback classes: ' . implode(', ', $missing));

        $this->assertStringContainsString(
            '.nativa-shell',
            $css,
            'The built stylesheet lost the body rule — a page served from the manifest would be unstyled.',
        );
    }

    #[Test]
    public function theFrozenBundleShipsNoSourcemaps(): void
    {
        $maps = glob(AppPaths::instance()->public('dist/' . self::bundle() . '/assets/*.map') ?: []);

        $this->assertSame([], $maps, 'Sourcemaps are debug weight in a frozen production bundle.');
    }

    /**
     * The one behaviour is the scrollspy, and the only class it writes is the
     * one the stylesheet declares. A bundle that renamed the block would
     * highlight nothing while every other assertion still passed.
     */
    #[Test]
    public function theFrozenScriptWritesTheClassTheStylesheetDeclares(): void
    {
        $this->assertStringContainsString('na-toc__link', $this->frozenJs());
        $this->assertStringContainsString('.na-toc__link--active', $this->frozenCss());
    }

    private function frozenJs(): string
    {
        $url = (new AssetManager(self::bundle()))->jsUrl(self::bundle());

        $this->assertNotNull($url, 'The frozen manifest names no `nativa` JS entry.');

        $js = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

        $this->assertIsString($js, 'The frozen JS file is unreadable.');

        return $js;
    }

    private function frozenCss(): string
    {
        $urls = (new AssetManager(self::bundle()))->cssUrls(self::bundle());

        $this->assertNotSame([], $urls, 'The frozen manifest names no `nativa` CSS.');

        $css = '';

        foreach ($urls as $url) {
            $content = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

            $this->assertIsString($content, sprintf('The frozen CSS file is unreadable: %s.', $url));

            $css .= $content;
        }

        return $css;
    }
}
