<?php

declare(strict_types = 1);

namespace Modules\Markdown\Tests;

use Modules\Markdown\Application\Services\MarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MarkdownBlockParagraphTest, where a block placeholder meets a paragraph.
 *
 * `{{form:<slug>}}` travels as markdown and expands to a `<section>` after the
 * HTML is built. A paragraph wraps what it holds in `<p>`, so a placeholder
 * alone on its line shipped `<p><section>`: nesting the browser silently
 * closes, leaving one empty paragraph beside every embedded form. The contact
 * page carried it until the forms module made the block real.
 *
 * The block here is one nothing owns, because the renderer's contract with a
 * placeholder is the half being tested, not any module's expansion of it.
 */
final class MarkdownBlockParagraphTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(MarkdownRenderer::class)) {
            $this->markTestSkipped('Markdown is not installed — the renderer ships with this module.');
        }
    }

    #[Test]
    public function aPlaceholderAloneInAParagraphIsNotWrapped(): void
    {
        $html = (new MarkdownRenderer())->render('{{none:thing}}' . "\n");

        $this->assertSame(
            '{{none:thing}}' . "\n",
            $html,
            'A block placeholder is not a paragraph, so nothing may put a <p> around it.',
        );
    }

    #[Test]
    public function aPlaceholderInsideASentenceKeepsItsParagraph(): void
    {
        $html = (new MarkdownRenderer())->render('Veta s {{none:thing}} v strede.' . "\n");

        $this->assertSame(
            '<p>Veta s {{none:thing}} v strede.</p>' . "\n",
            $html,
            'Text around a placeholder is still a sentence, and a sentence is a paragraph.',
        );
    }

    #[Test]
    public function aParagraphOfProseIsUnaffected(): void
    {
        $html = (new MarkdownRenderer())->render('Prvý riadok.' . "\n" . 'Druhý riadok.' . "\n");

        $this->assertSame('<p>Prvý riadok.<br>Druhý riadok.</p>' . "\n", $html);
    }

    #[Test]
    public function aSoftWrapJoinsWithASpace(): void
    {
        $html = (new MarkdownRenderer())->render('A long clause that' . "\n" . 'continues here.' . "\n");

        $this->assertSame(
            '<p>A long clause that continues here.</p>' . "\n",
            $html,
            'A break inside a sentence is a wrap, not something the reader sees.',
        );
    }

    #[Test]
    public function aLineOpeningWithAGlyphIsStillAQuote(): void
    {
        $html = (new MarkdownRenderer())->render('> quoted line' . "\n");

        $this->assertStringContainsString('<blockquote><p>quoted line</p></blockquote>', $html);
    }
}
