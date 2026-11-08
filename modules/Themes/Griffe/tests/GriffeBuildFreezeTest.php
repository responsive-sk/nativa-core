<?php

declare(strict_types = 1);

namespace Modules\Themes\Griffe\Tests;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Application\Services\View\InlineCss;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Themes\Griffe\Theme\GriffeTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GriffeBuildFreezeTest — the frozen `griffe` bundle is committed, resolves,
 * and stays a single Chrome-70-safe file inside its size budget.
 *
 * `public/dist/` is gitignored everywhere EXCEPT `dist/griffe/` (see the
 * freeze exception in .gitignore): a deploy without Node serves these bytes,
 * so their absence is a defect, not a build that has not run — nothing here
 * skips when the manifest is missing. Edit the bundle only by rebuilding
 * (`make assets`); a hand edit passes every assertion below except the
 * rebuild-identity the summary asks for, which is why phase 4.7 checks
 * `git status` after a rebuild instead of asserting it here.
 */
final class GriffeBuildFreezeTest extends TestCase
{
    /**
     * Raw-byte budgets with ~30% headroom over the freezing build
     * (JS 28 806, CSS 17 996 — measured after the component layer was pruned
     * to what the PHP theme actually renders: `hero-layout`,
     * `horizontal-scroll-hero` and the three self-gating behaviours). The
     * previous freeze was JS 44 830 / CSS 17 996; before that JS 221 385 /
     * CSS 21 140, which shipped the whole resp-front library and the dead
     * griffe slider rules on every page.
     *
     * The 16 KB between those two freezes is the NET saving of dropping the
     * framework: the Lit runtime alone measured ~35 KB on a probe build, and
     * the native elements' own code is correspondingly larger than the five
     * template lines it replaced (see
     * `Frontend/packages/griffe/src/layout/hero-layout.ts`). The budget still
     * guards against the runtime creeping back — re-importing `lit` alone would
     * not fit under it.
     *
     * `theFrozenScriptIsASingleFileWithNoModuleSyntax` still proves one file,
     * and the deferred `<script>` keeps it off the critical path.
     */
    private const int JS_BUDGET = 38000;

    /**
     * The bundle name, read from its owner.
     *
     * `GriffeTheme::name()` and `::bundle()` both return 'griffe', and this
     * test used to declare a THIRD copy — rule 1 of
     * `NameOwnershipContractTest` counted it as two owners of one value. It is
     * not a copy worth keeping: nothing here asserts the name is 'griffe'
     * (`discoveryFindsTheTheme` in the sibling test does, and there the literal
     * must stay or the assertion becomes `assertSame($x, $x)`). Every use below
     * is an OPERATION on the bundle — a manifest path, an AssetManager lookup —
     * so the owner is the right place to ask.
     *
     * The freeze is what this test is for, and reading the owner makes that
     * stronger rather than weaker: renaming the bundle without re-freezing fails
     * here, because the committed `dist/<name>/` no longer resolves.
     */
    private static function bundle(): string
    {
        return (new GriffeTheme())->name();
    }

    /**
     * The questions and embedded-form blocks joined the bundle in one change
     * (`questions.css` from `generate-questions-css.mjs`, `form.css` from
     * `generate-form-css.mjs`): a theme that styles neither leaves both blocks
     * at browser defaults inside an otherwise designed page, which is what
     * griffe did until then.
     *
     * Measured after the rebuild: CSS 25 426 B, against 20 954 B with the
     * questions rules alone and 18 243 B before either. The metric-matched
     * fallback pair is 204 B and 215 B, 419 B together, while the rebuild that
     * carried it moved the file by 305 B; those two numbers count different
     * things and the delta is not the size of the block. The ceiling moves to
     * 27 000, and deliberately not to the ~30% the paragraph above describes:
     * that headroom was sized for a build that had just been pruned, while this
     * growth is two fixed blocks of rules the theme owes its readers. ~1.9 KB is
     * enough for rule growth and still far below what a library creeping back
     * would need.
     */
    private const int CSS_BUDGET = 27000;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Themes/Griffe')) {
            $this->markTestSkipped('Griffe is not installed — the `griffe` theme ships with this module.');
        }
    }

    #[Test]
    public function theFrozenManifestIsCommitted(): void
    {
        $manifest = AppPaths::instance()->public('dist/' . self::bundle() . '/manifest.json');

        $this->assertFileExists(
            $manifest,
            'The frozen griffe manifest is not committed — rebuild it (`make assets`), never by hand.',
        );
    }

    #[Test]
    public function assetManagerResolvesTheFrozenEntry(): void
    {
        $assets = new AssetManager(self::bundle());

        $this->assertTrue($assets->isAvailable(), 'AssetManager did not resolve the committed griffe manifest.');

        $jsUrl = $assets->jsUrl(self::bundle());

        $this->assertNotNull($jsUrl, 'The frozen manifest names no `griffe` JS entry.');
        $this->assertFileExists(
            AppPaths::instance()->public(ltrim($jsUrl, '/')),
            'The manifest points at a JS file that is not committed.',
        );

        $cssUrls = $assets->cssUrls(self::bundle());

        $this->assertNotSame([], $cssUrls, 'The frozen manifest names no `griffe` CSS.');

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
            sprintf('Frozen griffe JS is %d bytes over a %d budget.', strlen($js), self::JS_BUDGET),
        );
        $this->assertLessThanOrEqual(
            self::CSS_BUDGET,
            strlen($css),
            sprintf('Frozen griffe CSS is %d bytes over a %d budget.', strlen($css), self::CSS_BUDGET),
        );
    }

    /**
     * The built stylesheet is a superset of the critical fallback: every
     * `gr-*` class the fallback file declares must exist in the build, or a
     * page served from the manifest loses rules the no-build page keeps.
     */
    #[Test]
    public function theBuiltStylesheetCoversEveryCriticalFallbackClass(): void
    {
        $fallback = InlineCss::file('Modules/Griffe/assets/critical/griffe.css');
        $css = $this->frozenCss();

        preg_match_all('/\.gr-[a-z0-9-]+/', $fallback, $matches);

        $classes = array_values(array_unique($matches[0]));

        $this->assertNotSame([], $classes, 'The fallback file declares no gr-* class — the extractor is broken.');

        $missing = [];

        foreach ($classes as $class) {
            if (!str_contains($css, $class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Built griffe CSS is missing fallback classes: ' . implode(', ', $missing),
        );

        foreach (['keyword', 'string', 'comment', 'number', 'function', 'operator'] as $token) {
            $this->assertStringContainsString(
                '.tok-' . $token,
                $css,
                'The token palette lost .' . $token . ' — highlighted blocks fall back to one colour.',
            );
        }
    }

    /**
     * The griffe slider surface is gone (the homepage is the resp-front
     * landing), so its rules and the `gr-js` staging signal that gated them are
     * gone with it. This is the inverse of the assertion that used to live
     * here: it fails if the dead CSS creeps back into the frozen bundle, which
     * is bytes every page inlines for markup nothing renders.
     */
    #[Test]
    public function theFrozenStylesheetCarriesNoDeadSliderRules(): void
    {
        $css = $this->frozenCss();

        foreach (['gr-hero-slider', 'gr-slide', 'gr-pag', 'gr-slider__arrow', 'gr-case'] as $dead) {
            $this->assertStringNotContainsString(
                $dead,
                $css,
                sprintf('Frozen griffe CSS still carries `%s` — no surface renders it.', $dead),
            );
        }
    }

    /**
     * The landing elements are native custom elements. The bundle carried the
     * Lit runtime once (~35 KB of 44 830 B) for two elements whose only
     * reactive state is a boolean and a slide index; these markers are what a
     * reintroduced `import 'lit'` (or a Lit-based component) drags back in.
     */
    #[Test]
    public function theFrozenScriptShipsNoFrameworkRuntime(): void
    {
        $js = $this->frozenJs();

        foreach (['lit-html', 'reactive-element', 'litElementVersions', '$litType$', 'CSSResult'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $js,
                sprintf(
                    'The frozen griffe bundle carries `%s` — the landing elements are plain HTMLElement subclasses and must stay that way.',
                    $marker,
                ),
            );
        }
    }

    #[Test]
    public function theFrozenBundleShipsNoSourcemaps(): void
    {
        $maps = glob(AppPaths::instance()->public('dist/' . self::bundle() . '/assets/*.map') ?: []);

        $this->assertSame([], $maps, 'Sourcemaps are debug weight in a frozen production bundle.');
    }

    private function frozenJs(): string
    {
        $url = (new AssetManager(self::bundle()))->jsUrl(self::bundle());

        $this->assertNotNull($url, 'The frozen manifest names no `griffe` JS entry.');

        $js = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

        $this->assertIsString($js, 'The frozen JS file is unreadable.');

        return $js;
    }

    private function frozenCss(): string
    {
        $urls = (new AssetManager(self::bundle()))->cssUrls(self::bundle());

        $this->assertNotSame([], $urls, 'The frozen manifest names no `griffe` CSS.');

        $css = '';

        foreach ($urls as $url) {
            $content = file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));

            $this->assertIsString($content, sprintf('The frozen CSS file is unreadable: %s.', $url));

            $css .= $content;
        }

        return $css;
    }
}
