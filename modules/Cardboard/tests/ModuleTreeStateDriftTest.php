<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Application\Services\ModuleTreeState;
use Modules\Tooling\ModuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModuleTreeStateDriftTest — the admin module tree measures the CATALOGUE a
 * theme actually lives in.
 *
 * `ModuleTreeState::differences()` used to build `base() . '/modules/' . $name`
 * itself. Once the themes moved to `modules/Themes/<Name>/`, that path no longer
 * existed, `is_dir()` failed and the method returned `[]` — which the caller
 * reads as "identical". `/mark/settings/modules` and the `modules` stat card
 * would then report peace for every theme, drifted or not, and no existing test
 * could tell, because "no differences" is also the correct answer for a slice
 * that really matches.
 *
 * So this test does not touch the working tree. It builds a catalogue and an
 * installed tree in a temp directory — one grouped entry, one flat entry — and
 * asserts the answer is a MEASUREMENT: a changed file is named, matching trees
 * are empty, and a name with no catalogue entry is empty rather than a crash.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModuleTreeStateDriftTest extends TestCase
{
    private const string MODULE = 'Cardboard';

    private string $root;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — this service ships with the module.');
        }

        $this->root = sys_get_temp_dir() . '/module-tree-state-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->remove($this->root);
        }
    }

    private function catalogue(): ModuleCatalog
    {
        return new ModuleCatalog($this->root . '/catalogue', $this->root . '/installed');
    }

    private function tree(): ModuleTreeState
    {
        return new ModuleTreeState($this->catalogue(), $this->root . '/installed');
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    #[Test]
    public function aChangedFileInAGroupedThemeIsReported(): void
    {
        $this->write('catalogue/Themes/Mono/Theme/MonoTheme.php', "<?php // catalogue\n");
        $this->write('installed/Mono/Theme/MonoTheme.php', "<?php // installed\n");

        $this->assertSame(
            ['Theme/MonoTheme.php'],
            $this->tree()->differences($this->catalogue(), 'Mono'),
            'A theme filed under modules/Themes/ must be compared, not silently called identical.',
        );
    }

    #[Test]
    public function aFlatModuleIsStillCompared(): void
    {
        $this->write('catalogue/Blog/Theme/BlogTheme.php', "<?php // catalogue\n");
        $this->write('installed/Blog/Theme/BlogTheme.php', "<?php // installed\n");

        $this->assertSame(
            ['Theme/BlogTheme.php'],
            $this->tree()->differences($this->catalogue(), 'Blog'),
            'The grouped lookup must not break the flat catalogue shape.',
        );
    }

    #[Test]
    public function matchingTreesHaveNoDifferences(): void
    {
        $this->write('catalogue/Themes/Mono/Theme/MonoTheme.php', "<?php // same\n");
        $this->write('installed/Mono/Theme/MonoTheme.php', "<?php // same\n");

        $this->assertSame([], $this->tree()->differences($this->catalogue(), 'Mono'));
    }

    #[Test]
    public function aModuleWithNoCatalogueEntryIsEmptyNotAFailure(): void
    {
        $this->write('installed/Ghost/Theme/GhostTheme.php', "<?php // installed only\n");

        $this->assertSame(
            [],
            $this->tree()->differences($this->catalogue(), 'Ghost'),
            'No catalogue entry means nothing to compare — and the page says so elsewhere.',
        );
    }

    private function remove(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
