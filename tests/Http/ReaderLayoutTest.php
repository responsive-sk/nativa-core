<?php

declare(strict_types = 1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\FrontendLayout;
use View\InlineJs;

/**
 * ReaderLayoutTest — the one reading surface shared by `/docs` and
 * `/blog/<slug>`.
 *
 * Written because the two defects it guards are both silent: two rails on one
 * page look like a styling choice, and a 180-character line looks like a design
 * decision until someone tries to read it. Neither throws, and neither is
 * visible to any other gate in this tree.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReaderLayoutTest extends TestCase
{
    /** @return list<array{level: int, text: string, anchor: string}> */
    private static function headings(int $count): array
    {
        $headings = [];

        for ($index = 1; $index <= $count; $index++) {
            $headings[] = ['level' => 2, 'text' => 'Section ' . $index, 'anchor' => 'section-' . $index];
        }

        return $headings;
    }

    #[Test]
    public function itAddsARailFromTheHeadingsItIsGiven(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', self::headings(3));

        self::assertStringContainsString('<article>body</article>', $html, 'The composed document was altered.');
        self::assertSame(
            3,
            substr_count($html, 'data-docs-toc__item'),
            'A rail entry is missing: every heading the renderer anchored belongs in the rail.',
        );
        self::assertStringContainsString('href="#section-1"', $html, 'The rail links to an anchor it invented.');
        self::assertStringContainsString('data-has-rail', $html, 'The two-column grid was not enabled.');
    }

    /**
     * The rule that keeps a page from showing two contents rails: a theme whose
     * article already composes one (Boson, Jednotka, Mono and Griffe all do) is
     * left exactly as it is.
     */
    #[Test]
    public function itNeverAddsASecondRail(): void
    {
        $composed = '<div class="bo-toc" aria-label="On this page"><ul><li>x</li></ul></div>';

        self::assertSame(
            $composed,
            FrontendLayout::readerLayout($composed, self::headings(5)),
            'A document that already carries a rail was wrapped again — the page would show two'
            . ' tables of contents and two scroll anchors for the same headings.',
        );
    }

    /**
     * A rail with one entry is furniture, and a one-entry rail would also turn on
     * the two-column grid, taking width from the prose to show a single link.
     */
    #[Test]
    public function fewerThanTwoHeadingsMeansNoRailAndTheFullMeasure(): void
    {
        foreach ([[], self::headings(1)] as $headings) {
            $html = FrontendLayout::readerLayout('<article>body</article>', $headings);

            self::assertStringNotContainsString('data-docs-toc__item', $html, 'A rail was built from fewer than two headings.');
            // The rule set always mentions the attribute; what must not appear is
            // the WRAPPER carrying it, which is what turns the grid on.
            self::assertStringNotContainsString(
                'data-docs-reader data-has-rail',
                $html,
                'The grid narrowed the prose for a rail that is not rendered.',
            );
            self::assertStringContainsString('max-width:68ch', $html, 'The measure is missing — lines would run the full viewport.');
        }
    }

    /**
     * Code and tables opt out of the measure — a sample squashed to 68
     * characters wraps mid-token and stops being copyable — but they do it
     * inside the column, never over the rail.
     *
     * Measured on `/docs/themes` at 1440px before this rule existed: the theme's
     * `.bo-page` (90vw) and its table were 1296px wide inside a 729px column,
     * ending 527px into the rail. `max-width:none` is what allowed it, and a
     * wide table needs a scroll box rather than a wider line.
     */
    #[Test]
    public function codeAndTablesScrollInsideTheColumnInsteadOfOverTheRail(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', self::headings(2));

        self::assertStringContainsString(
            '[data-docs-prose] pre,[data-docs-prose] table{max-width:100%}',
            $html,
            'A sample or a table is wider than the column — it would run under the rail.',
        );
        self::assertStringContainsString(
            '[data-docs-prose]>*{max-width:100%}',
            $html,
            'A theme section that sets its own width (Boson\'s `.bo-page` is 90vw) is not contained by the column.',
        );
        self::assertStringContainsString(
            '[data-docs-prose] table{display:block;overflow-x:auto}',
            $html,
            'A table too wide for the column has no scroll box: it would overlap the rail instead.',
        );
    }

    /**
     * The style block must reach the browser through the CSP nonce, or
     * `style-src` blocks it and the page renders unstyled while looking correct
     * in the HTML.
     */
    #[Test]
    public function theStyleBlockCarriesTheCspNoncePlaceholder(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', self::headings(2));

        self::assertStringContainsString(
            '<style nonce="__CSP_NONCE_PLACEHOLDER__">',
            $html,
            'The reader layout style block carries no nonce placeholder, so SecurityHeadersMiddleware'
            . ' cannot give it a nonce and the CSP blocks it.',
        );
    }

    /**
     * The rail must not be painted under the theme's pinned header. Measured on
     * `/docs/themes` at 1440px with the Boson theme: the rail stuck at `top: 24px`
     * while the fixed bar covered 0–71px, so the "On this page" title and the
     * first entries were behind it — the rail worked and was invisible.
     *
     * The offset is a custom property, not a number, because its value belongs to
     * the theme (Boson's bar is 100px, 70px once scrolled; Griffe and Mono pin a
     * sticky nav) and this layout must hold for a theme that is not installed yet.
     */
    #[Test]
    public function theRailIsOffsetByTheThemesPinnedHeader(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', self::headings(2));

        self::assertStringContainsString(
            '[data-docs-reader]{--docs-header-offset:0px;',
            $html,
            'The reader does not declare the header offset — the rail falls back to the top of the viewport.',
        );
        self::assertStringContainsString(
            'top:calc(var(--docs-header-offset) + 1.5rem)',
            $html,
            'The rail sticks at a constant top, so a fixed header covers it.',
        );
        self::assertStringContainsString(
            'max-height:calc(100vh - var(--docs-header-offset) - 3rem)',
            $html,
            'The rail may grow taller than the space below the header — its last entries would be unreachable.',
        );
    }

    /**
     * The offset is measured in the browser, so the style block and the script
     * must agree on one property name. A rename on either side is silent: the
     * rail simply goes back to `top: 1.5rem` under the header, and every other
     * assertion here still passes.
     */
    #[Test]
    public function theMeasuringScriptIsEmittedAndAgreesWithTheStyle(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', self::headings(2));
        $script = InlineJs::file('Themes/assets/scripts/docs-reader.js');

        self::assertNotSame('', $script, 'The docs-reader script asset is missing (path moved?).');
        self::assertStringContainsString('--docs-header-offset', $script, 'The script writes no offset, so the property stays 0px.');
        self::assertStringContainsString('<script nonce="__CSP_NONCE_PLACEHOLDER__">', $html, 'The measuring script is not emitted with a nonce.');
        self::assertStringContainsString($script, $html, 'The measuring script is not inlined into the reader layout.');

        // Without the script there is no offset to measure — an empty asset would
        // leave the rail under the header exactly as before.
        self::assertStringContainsString(
            'pinnedBottom',
            $script,
            'The script no longer measures a pinned header.',
        );
    }

    /**
     * The same measurement answers anchors. A heading scrolled to the top edge
     * sits under the theme's pinned bar, so every rail link lands on a heading
     * the reader cannot see — measured on `/docs/themes` in Boson, whose bar is
     * 101px, with the rail link's target at 124px only because the theme declares
     * `scroll-padding-top` itself.
     *
     * Themes do not all declare it (Boson is the only one), so the script writes
     * the padding — but only after READING the computed value: a theme that has
     * an opinion about its own chrome keeps it. Both halves are asserted here
     * because either one alone is silent: no read means every theme is
     * overridden, no write means anchors stay under the bar everywhere else.
     */
    #[Test]
    public function theMeasurementAlsoClearsTheThemesAnchors(): void
    {
        $script = InlineJs::file('Themes/assets/scripts/docs-reader.js');

        self::assertStringContainsString(
            'getComputedStyle(document.documentElement).scrollPaddingTop',
            $script,
            'The script no longer reads the theme\'s scroll-padding, so it would overwrite a declared one.',
        );
        self::assertStringContainsString(
            'style.scrollPaddingTop',
            $script,
            'The script sets no scroll-padding: an anchored heading lands under a pinned header on every theme that does not declare one.',
        );
    }

    /**
     * Rail text is escaped: a heading is content, and a heading containing markup
     * must not become markup in the rail.
     */
    #[Test]
    public function railTextIsEscaped(): void
    {
        $html = FrontendLayout::readerLayout('<article>body</article>', [
            ['level' => 2, 'text' => '<script>x</script>', 'anchor' => 'a'],
            ['level' => 2, 'text' => 'B & C', 'anchor' => 'b'],
        ]);

        self::assertStringNotContainsString('<script>x</script>', $html, 'Heading text reached the rail unescaped.');
        self::assertStringContainsString('B &amp; C', $html, 'Heading text was not escaped.');
    }
}
