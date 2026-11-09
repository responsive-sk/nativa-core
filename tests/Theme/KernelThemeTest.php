<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\KernelTheme\Theme\KernelTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use View\InlineCss;

/**
 * KernelThemeTest (task 2.2) — the trimmed design, pinned.
 *
 * The kernel theme is one hand-written file with no build step; what keeps
 * it that way is this test, not the docblock: the CSS file must exist,
 * survive the inline loader non-empty, style the theme's own vocabulary,
 * and ship none of the at-rules the loader strips. The error block must
 * escape its inputs and wear no other theme's class names.
 */
final class KernelThemeTest extends TestCase
{
    private const string CSS = 'Modules/KernelTheme/assets/critical/kernel.css';

    #[Test]
    public function criticalCssResolvesToShippedFile(): void
    {
        $this->assertSame([self::CSS], (new KernelTheme())->criticalCss());
        $this->assertFileExists(APP_BASE_PATH . '/src/' . self::CSS);
    }

    #[Test]
    public function inlineCssSurvivesTheLoader(): void
    {
        $source = (string) file_get_contents(APP_BASE_PATH . '/src/' . self::CSS);

        foreach (['@charset', '@import', '@layer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'InlineCss strips ' . $forbidden);
        }

        $inlined = InlineCss::files([self::CSS]);

        $this->assertNotSame('', $inlined);
        $this->assertStringContainsString('.k-header', $inlined);
        $this->assertStringContainsString('.k-error__code', $inlined);
        $this->assertStringContainsString('.k-footer', $inlined);
    }

    #[Test]
    public function kernelLayoutWearsItsOwnDesign(): void
    {
        $html = FrontendLayout::render('T', '<p>x</p>', theme: 'kernel');

        $this->assertStringContainsString('.k-header', $html);
        $this->assertStringContainsString('k-header__brand', $html);
    }

    #[Test]
    public function errorPageIsThemeVocabularyWithoutShowcase(): void
    {
        $response = ErrorPageRenderer::response(404, 'Not Found', 'gone <script>');

        $this->assertSame(404, $response->getStatusCode());

        $html = $response->getContent();
        $this->assertStringContainsString('k-error', $html);
        $this->assertStringNotContainsString('st-hero', $html);
        $this->assertStringNotContainsString('Nativa Starter', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
