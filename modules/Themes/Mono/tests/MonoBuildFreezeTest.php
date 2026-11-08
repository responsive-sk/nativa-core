<?php

declare(strict_types = 1);

namespace Modules\Themes\Mono\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MonoBuildFreezeTest — the frozen `mono` bundle is committed, resolves,
 * and stays a single Chrome-70-safe file inside its size budget.
 *
 * `public/dist/` is gitignored everywhere EXCEPT `dist/griffe/` and
 * `dist/mono/` (see the freeze exceptions in .gitignore): a deploy without
 * Node serves these bytes, so their absence is a defect, not a build that
 * has not run — nothing here skips when the manifest is missing. Edit the
 * bundle only by rebuilding (`make assets`); a hand edit passes every
 * assertion below except the rebuild-identity the summary asks for, which
 * is why the follow-up checks `git status` after a rebuild instead of
 * asserting it here.
 */
final class MonoBuildFreezeTest extends TestCase
{
    private const string BUNDLE = 'mono';

    /**
     * Raw-byte budgets. JS 18 000 covers the Boson-style choice section, the
     * mask reveal and the navbar dot-grid mark (measured 16 777, ~7% headroom).
     * CSS 42 500 covers the same sections plus the steps accordion, the
     * hero-art double ring, the locale switch's phone rules and the opt-in
     * burger glyph with its open-state rule (measured 42 002 after the
     * per-theme `nav_toggle_icon` choice, ~1.2% headroom). The earlier freeze
     * was JS 10 370 / CSS 29 200, then CSS 40 000 at 38 624, then CSS 41 500
     * when the switch landed with 259 B spare, then CSS 42 000 at 41 725 —
     * each raise is a deliberate feature landing, never slack for edits.
     */
    private const int JS_BUDGET = 18000;

    private const int CSS_BUDGET = 42500;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/Mono')) {
            $this->markTestSkipped('Mono is not installed — the `mono` theme ships with this module.');
        }
    }

    #[Test]
    public function theFrozenManifestIsCommitted(): void
    {
        $manifest = AppPaths::instance()->public('dist/' . self::BUNDLE . '/manifest.json');

        $this->assertFileExists(
            $manifest,
            'The frozen mono manifest is not committed — rebuild it (`make assets`), never by hand.',
        );
    }

    #[Test]
    public function assetManagerResolvesTheFrozenEntry(): void
    {
        $assets = new AssetManager(self::BUNDLE);

        $this->assertTrue($assets->isAvailable(), 'AssetManager did not resolve the committed mono manifest.');

        $jsUrl = $assets->jsUrl(self::BUNDLE);

        $this->assertNotNull($jsUrl, 'The frozen manifest names no `mono` JS entry.');
        $this->assertFileExists(
            AppPaths::instance()->public(ltrim($jsUrl, '/')),
            'The manifest points at a JS file that is not committed.',
        );

        $cssUrls = $assets->cssUrls(self::BUNDLE);

        $this->assertNotSame([], $cssUrls, 'The frozen manifest names no `mono` CSS.');

        foreach ($cssUrls as $url) {
            $this->assertFileExists(
                AppPaths::instance()->public(ltrim($url, '/')),
                sprintf('The manifest points at a CSS file that is not committed: %s.', $url),
            );
        }
    }

    /**
     * One self-contained file per entry: a shared Rollup chunk would emit
     * `import` statements, and the deferred `<script>` (no `type="module"`)
     * cannot execute them on the Chrome 70 baseline.
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
            sprintf('Frozen mono JS is %d bytes over a %d budget.', strlen($js), self::JS_BUDGET),
        );
        $this->assertLessThanOrEqual(
            self::CSS_BUDGET,
            strlen($css),
            sprintf('Frozen mono CSS is %d bytes over a %d budget.', strlen($css), self::CSS_BUDGET),
        );
    }

    /**
     * The built stylesheet is a superset of the critical fallback: every
     * `mo-*` class the two fallback files declare must exist in the build,
     * or a page served from the manifest loses rules the no-build page
     * keeps. The dev variants and the token palette ride along — without
     * them the switcher reskins nothing and highlighted blocks fall back
     * to one colour.
     */
    #[Test]
    public function theBuiltStylesheetCoversEveryCriticalFallbackClass(): void
    {
        $fallback = InlineCss::file('Modules/Mono/assets/critical/mono.css')
            . InlineCss::file('Modules/Mono/assets/critical/mo-variants.css');
        $css = $this->frozenCss();

        preg_match_all('/\.mo-[a-z0-9-]+/', $fallback, $matches);

        $classes = array_values(array_unique($matches[0]));

        $this->assertNotSame([], $classes, 'The fallback files declare no mo-* class — the extractor is broken.');

        $missing = [];

        foreach ($classes as $class) {
            if (!str_contains($css, $class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Built mono CSS is missing fallback classes: ' . implode(', ', $missing),
        );

        foreach (['keyword', 'string', 'comment', 'number', 'function', 'operator'] as $token) {
            $this->assertStringContainsString(
                '.tok-' . $token,
                $css,
                'The token palette lost .' . $token . ' — highlighted blocks fall back to one colour.',
            );
        }

        foreach (['ember', 'glacier'] as $variant) {
            $this->assertStringContainsString(
                'data-variant=' . $variant,
                $css,
                'The ' . $variant . ' variant lost its rules — the switcher reskins nothing.',
            );
        }

        $this->assertStringContainsString('.mo-is-visible', $css, 'Without .mo-is-visible the hero title keeps opacity:0.');
        $this->assertStringContainsString('.mo-toc__link--active', $css, 'Without the active rule the scrollspy highlights invisibly.');
    }

    /**
     * The opening frame must not stack: the sync `mo-js` signal
     * (MonoTheme::themeInitScript) stages a `--multi` hero before first
     * paint, so the deferred bundle never reflows it. If this selector goes
     * missing from the build, every load flashes the stacked slides again.
     */
    #[Test]
    public function theFrozenStylesheetStagesTheMultiSliderPrePaint(): void
    {
        $css = $this->frozenCss();

        $this->assertStringContainsString(
            '.mo-js .mo-hero-slider--multi .mo-slide',
            $css,
            'Built CSS lost the pre-paint staging — the hero flashes stacked on every load.',
        );
        $this->assertStringContainsString(
            '.mo-js .mo-hero-slider--multi .mo-slide--active',
            $css,
            'Staging without a visible active slide ships an empty hero.',
        );
    }

    #[Test]
    public function theFrozenBundleShipsNoSourcemaps(): void
    {
        $maps = glob(AppPaths::instance()->public('dist/' . self::BUNDLE . '/assets/*.map') ?: []);

        $this->assertSame([], $maps, 'Sourcemaps are debug weight in a frozen production bundle.');
    }

    private function frozenJs(): string
    {
        $url = (new AssetManager(self::BUNDLE))->jsUrl(self::BUNDLE);

        $this->assertNotNull($url, 'The frozen manifest names no `mono` JS entry.');

        $js = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

        $this->assertIsString($js, 'The frozen JS file is unreadable.');

        return $js;
    }

    private function frozenCss(): string
    {
        $urls = (new AssetManager(self::BUNDLE))->cssUrls(self::BUNDLE);

        $this->assertNotSame([], $urls, 'The frozen manifest names no `mono` CSS.');

        $css = '';

        foreach ($urls as $url) {
            $content = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

            $this->assertIsString($content, sprintf('The frozen CSS file is unreadable: %s.', $url));

            $css .= $content;
        }

        return $css;
    }
}
