<?php

declare(strict_types = 1);

namespace Tests\Kernel;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Kernel\Contracts\PageShellInterface;
use Modules\Core\Kernel\PageShell\PageShellRegistry;
use Modules\Core\Kernel\PageShell\PlainPageShell;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageShellPortTest — the page-shell port, pinned where the boot test cannot
 * see it.
 *
 * `KernelOnlyBootTest` asserts status codes through a boot that has `Layout`
 * installed, so the dependency-free path it exists to guarantee is never the
 * one that runs there. This file runs that path directly: `PlainPageShell` is
 * what a tree with no layout module answers with, and it has to render and
 * escape without a database, a theme or a module.
 *
 * The registry assertion stays permissive about which module is installed —
 * the kernel-only export ships no layout module at all — and names only the
 * port, never the module that implements it.
 */
final class PageShellPortTest extends TestCase
{
    protected function tearDown(): void
    {
        PageShellRegistry::reset();
    }

    #[Test]
    public function plainShellRendersHomeWithoutADatabase(): void
    {
        $html = (new PlainPageShell())->home(
            Request::create('GET', '/'),
            'Nativa',
            'sk',
            ['title' => 'Privítanie'],
        );

        self::assertStringContainsString('<html lang="sk">', $html);
        self::assertStringContainsString('<title>Privítanie</title>', $html);
        self::assertStringContainsString('Nativa', $html);
    }

    #[Test]
    public function plainShellFallsBackToTheSiteNameForAMissingTitle(): void
    {
        $html = (new PlainPageShell())->home(Request::create('GET', '/'), 'Nativa', 'en', []);

        self::assertStringContainsString('<title>Nativa</title>', $html);
    }

    #[Test]
    public function plainShellEscapesEveryValueItRenders(): void
    {
        $shell = new PlainPageShell();

        $home = $shell->home(Request::create('GET', '/'), '<script>x</script>', 'sk', []);

        self::assertStringNotContainsString('<script>x</script>', $home);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $home);

        $error = $shell->error(404, 'Not found', '<b>detail</b>');

        self::assertStringNotContainsString('<b>detail</b>', $error);
        self::assertStringContainsString('&lt;b&gt;detail&lt;/b&gt;', $error);
    }

    #[Test]
    public function plainShellErrorCarriesTheStatusItWasAskedFor(): void
    {
        $error = (new PlainPageShell())->error(404, 'Not found', 'No page here');

        self::assertStringContainsString('<title>404 Not found</title>', $error);
        self::assertStringContainsString('<h1 style="font-size:1.5rem">404 Not found</h1>', $error);
    }

    #[Test]
    public function theRegistryAlwaysAnswersWithThePort(): void
    {
        $shell = PageShellRegistry::current();

        self::assertInstanceOf(PageShellInterface::class, $shell);

        $installed = \dirname(__DIR__, 2) . '/src/Modules/Layout/Interfaces/HTTP/PageShell.php';

        if (is_file($installed)) {
            self::assertNotSame(
                PlainPageShell::class,
                $shell::class,
                'an installed layout module must supply the shell, not the kernel fallback',
            );
        }

        $class = $shell::class;
        PageShellRegistry::reset();

        self::assertSame($class, PageShellRegistry::current()::class, 'discovery is stable across a reset');
    }
}
