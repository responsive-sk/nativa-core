<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Application\Services\View\InlineCss;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\ThemeRegistry;

/**
 * ThemeStickyRailTest — the one-line CSS change that silently unfloats every
 * "On this page" rail.
 *
 * Measured 2026-10-22, on the live site: the article rail declared
 * `position: sticky`, rendered with the right markup, and did not float. After
 * 1400px of scroll it sat at `top: -1249px` — indistinguishable from `static`.
 * The cause was two rules away from the rail: `html` and `body` carried
 * `overflow-x: hidden`, which makes both elements SCROLL CONTAINERS, and a
 * sticky element inside a container that never scrolls has nothing to stick to.
 * Replacing `hidden` with `clip` (which cuts the same overflow without creating
 * a scroll container) put the rail back at `top: 134` for the same scroll.
 *
 * The reason this needs a gate rather than a comment: the defect is invisible
 * in the markup, invisible in the CSS that owns the rail, and invisible to
 * every other test in this tree. `overflow-x: hidden` on `body` is a common
 * defensive line (it stops a wide table from scrolling the page sideways), so
 * the next person who adds it — or the next theme copied in with it — turns
 * off every sticky rail at once and nothing fails.
 *
 * The rule is narrow on purpose: it only reads themes whose critical CSS
 * actually declares a sticky rail, so a theme without one is not asked to keep
 * a promise it never made, and the fallback pair `overflow-x: hidden` followed
 * by `overflow-x: clip` passes — the later declaration is the one that counts,
 * which is exactly how the fix ships.
 */
final class ThemeStickyRailTest extends TestCase
{
    /** The declaration that makes a rail a promise to the reader. */
    private const string STICKY = 'position: sticky';

    /** The elements whose overflow makes the whole page a scroll container. */
    private const array CONTAINERS = ['html', 'body'];

    /** The value that turns a box into a scroll container. */
    private const string HIDDEN = 'hidden';

    #[Test]
    public function noThemesCriticalCssMakesThePageAScrollContainer(): void
    {
        $railsChecked = 0;

        foreach (ThemeRegistry::all() as $name => $class) {
            $theme = ThemeRegistry::get($name);

            if (null === $theme) {
                continue; // an unconstructable theme is skipped by the registry, with a log line
            }

            foreach ($theme->criticalCss() as $path) {
                $css = InlineCss::file($path);

                if (!str_contains($css, self::STICKY)) {
                    continue; // no sticky rail to protect in this stylesheet
                }

                $railsChecked++;

                foreach (self::CONTAINERS as $element) {
                    $this->assertNotSame(
                        self::HIDDEN,
                        self::effectiveOverflowX($css, $element),
                        sprintf(
                            'Theme `%s` sets `overflow-x: hidden` on `%s` in `%s`, which makes it a scroll container and'
                            . ' unfloats its sticky "On this page" rail — the rail renders, declares sticky, and scrolls'
                            . ' away with the page. Use `overflow-x: clip` (after the hidden line, as the fallback).',
                            $name,
                            $element,
                            $path,
                        ),
                    );
                }
            }
        }

        if (0 === $railsChecked) {
            $this->markTestSkipped('No installed theme declares a sticky rail — nothing to check.');
        }
    }

    /**
     * The detector proves itself in both directions: the LAST declaration wins
     * (so the shipping `hidden` + `clip` pair passes) and a class name that
     * merely ends in `body` is not mistaken for the element.
     */
    #[Test]
    public function theOverflowReaderKeepsTheLastDeclarationAndOnlyTheElement(): void
    {
        $this->assertSame(
            'clip',
            self::effectiveOverflowX('html { overflow-x: hidden; overflow-x: clip; }', 'html'),
            'The later declaration is the one the browser applies.',
        );

        $this->assertSame(
            'hidden',
            self::effectiveOverflowX("body { overflow-x: clip; }\nbody { overflow-x: hidden; }", 'body'),
            'A later block still wins over an earlier one.',
        );

        $this->assertNull(
            self::effectiveOverflowX('.bo-article__body { overflow-x: hidden; }', 'body'),
            'A BEM class ending in `body` is not the body element.',
        );

        $this->assertNull(
            self::effectiveOverflowX('html { overflow-x: hidden; }', 'body'),
            'The element must actually appear.',
        );
    }

    /**
     * The element's own `overflow-x` after the cascade of every block that
     * names it; null when the stylesheet never sets one.
     */
    private static function effectiveOverflowX(string $css, string $element): ?string
    {
        // A selector token, not a substring: `.bo-article__body` and
        // `#body` must not be read as the element.
        preg_match_all(
            '/(?<![-\\w.#])' . preg_quote($element, '/') . '\\s*\\{([^}]*)\\}/i',
            $css,
            $blocks,
        );

        $value = null;

        foreach ($blocks[1] as $block) {
            preg_match_all('/overflow-x\\s*:\\s*([a-z-]+)/i', $block, $declarations);

            foreach ($declarations[1] as $declared) {
                $value = strtolower($declared);
            }
        }

        return $value;
    }
}
