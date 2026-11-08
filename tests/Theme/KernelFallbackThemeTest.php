<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Interfaces\HTTP\ThemeKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\kernel\KernelFallbackTheme;

/**
 * KernelFallbackThemeTest — the kernel's built-in default theme.
 *
 * It is what the kernel renders with when no theme module answers, so it
 * must be unstyled, escape its inputs, and never be discovered as a module.
 */
final class KernelFallbackThemeTest extends TestCase
{
    #[Test]
    public function fallbackShipsNoStylesheet(): void
    {
        $this->assertSame([], (new KernelFallbackTheme())->criticalCss());
    }

    #[Test]
    public function unknownThemeNameReachesTheFallback(): void
    {
        $this->assertInstanceOf(KernelFallbackTheme::class, ThemeKernel::for('no-such-theme'));
    }

    #[Test]
    public function errorBlockEscapesItsInputs(): void
    {
        $html = (new KernelFallbackTheme())->errorContent(404, 'Not <b>Found</b>', 'gone <script>');

        $this->assertStringContainsString('&lt;b&gt;Found&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
