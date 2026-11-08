<?php

declare(strict_types = 1);

namespace Tests\Content;

use Modules\Core\Application\Services\MarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MarkdownHeadingTest, the `Časť I > Title` heading shape.
 *
 * A section heading that opens with its own numbering reads as a label, not as
 * a sentence, so the renderer exposes the prefix and the title as separate
 * spans, separated by the responsive.sk `>`. The TOC still reads the full text,
 * and a title carrying inline markup is inlined on its own rather than shared
 * with the prefix.
 */
final class MarkdownHeadingTest extends TestCase
{
    #[Test]
    public function aPrefixedHeadingIsSplitIntoAPrefixAndATitle(): void
    {
        $renderer = new MarkdownRenderer();
        $html = $renderer->render("## Časť I > Prvý krok\n");

        $this->assertStringContainsString('<span data-heading-prefix>Časť I</span>', $html);
        $this->assertStringContainsString('<span data-heading-dash> &gt; </span>', $html);
        $this->assertStringContainsString('<span data-heading-title>Prvý krok</span>', $html);

        $headings = $renderer->extractHeadings($html);

        $this->assertSame('Časť I > Prvý krok', $headings[0]['text'], 'The TOC still reads the whole heading.');
    }

    #[Test]
    public function theRendererNeverInjectsAnEmDash(): void
    {
        $html = (new MarkdownRenderer())->render("## Časť I > Prvý krok\n");

        $this->assertStringNotContainsString('—', $html);
    }

    #[Test]
    public function aTitleWithInlineMarkupIsInlinedOnItsOwn(): void
    {
        $html = (new MarkdownRenderer())->render("## Časť IV > `invalid-method-access`: 313 → 0\n");

        $this->assertStringContainsString('<span data-heading-prefix>Časť IV</span>', $html);
        $this->assertStringContainsString(
            '<span data-heading-title><code>invalid-method-access</code>: 313 → 0</span>',
            $html,
        );
    }

    #[Test]
    public function aHeadingWithoutAPrefixIsNotSplit(): void
    {
        $html = (new MarkdownRenderer())->render("## `src/http.php` early logging\n");

        $this->assertStringContainsString('<code>src/http.php</code> early logging', $html);
        $this->assertStringNotContainsString('data-heading-prefix', $html);
    }
}
