<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Tooling\ModuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModuleDriftTest (task 4.1) — the catalogue is the source, the slice is a copy.
 *
 * `src/Modules` is generated and untracked, so git never compares the two and
 * a fix can land on one side only; the tree still looks green because the
 * slice is what runs. `ModuleCatalog::drift()` is the measurement: content
 * hashes per relative path, with the differing names kept, not just a boolean.
 *
 * The fixtures pin the four answers the CLI prints and, explicitly, the
 * tests-only exception: a catalogue-only test file is drift, but not RUNTIME
 * drift, because phpunit runs module tests from modules/ and the app never
 * loads them. A changed stylesheet beside the same test-only difference must
 * still read as runtime drift — otherwise the exception would hide exactly
 * the defect it exists to expose.
 */
final class ModuleDriftTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nativa-drift-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules', 0o775, true);
        mkdir($this->root . '/src/Modules', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (['/modules', '/src/Modules'] as $side) {
            $base = $this->root . $side;

            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }

            @rmdir($base);
        }

        @rmdir($this->root);
    }

    #[Test]
    public function identicalCopiesReportIdentical(): void
    {
        $this->write('modules/Alpha/src/Thing.php', '<?php // same');
        $this->write('src/Modules/Alpha/src/Thing.php', '<?php // same');

        $drift = $this->catalog()->drift('Alpha');

        self::assertSame('identical', $drift['status']);
        self::assertFalse($drift['testsOnly']);
        self::assertSame(1, $drift['catalog']);
        self::assertSame(1, $drift['installed']);
        self::assertSame([], $drift['changed']);
    }

    #[Test]
    public function aChangedFileIsNamedNotJustCounted(): void
    {
        $this->write('modules/Alpha/src/Thing.php', '<?php // new');
        $this->write('src/Modules/Alpha/src/Thing.php', '<?php // old');

        $drift = $this->catalog()->drift('Alpha');

        self::assertSame('drifted', $drift['status']);
        self::assertFalse($drift['testsOnly'], 'a PHP file is runtime code, not a test');
        self::assertSame(['src/Thing.php'], $drift['changed']);
    }

    #[Test]
    public function filesPresentOnOnlyOneSideAreReportedBySide(): void
    {
        $this->write('modules/Alpha/src/Thing.php', '<?php');
        $this->write('src/Modules/Alpha/src/Thing.php', '<?php');
        $this->write('modules/Alpha/src/New.php', '<?php // in catalog');
        $this->write('src/Modules/Alpha/src/Old.php', '<?php // only installed');

        $drift = $this->catalog()->drift('Alpha');

        self::assertSame(['src/New.php'], $drift['onlyInCatalog']);
        self::assertSame(['src/Old.php'], $drift['onlyInInstalled']);
        self::assertFalse($drift['testsOnly']);
    }

    #[Test]
    public function aTestOnlyDifferenceIsDriftButNotRuntimeDrift(): void
    {
        $this->write('modules/Alpha/src/Thing.php', '<?php');
        $this->write('src/Modules/Alpha/src/Thing.php', '<?php');
        $this->write('modules/Alpha/tests/NewTest.php', '<?php // catalog only');

        $drift = $this->catalog()->drift('Alpha');

        self::assertSame('drifted', $drift['status'], 'the report still says the copies differ');
        self::assertTrue($drift['testsOnly'], 'a catalogue-only test is the expected, harmless difference');
        self::assertSame(['tests/NewTest.php'], $drift['onlyInCatalog']);
    }

    #[Test]
    public function oneRuntimeDifferenceMakesATestOnlyDifferenceRuntime(): void
    {
        $this->write('modules/Alpha/src/Thing.php', '<?php // catalog');
        $this->write('src/Modules/Alpha/src/Thing.php', '<?php // installed');
        $this->write('modules/Alpha/assets/theme.css', '.a{color:red}');

        $drift = $this->catalog()->drift('Alpha');

        self::assertFalse(
            $drift['testsOnly'],
            'a stylesheet missing from the slice is exactly the drift this exception must not hide',
        );
    }

    #[Test]
    public function aCatalogueEntryWithNoSliceIsNotInstalled(): void
    {
        $this->write('modules/Beta/module.json', '{}');
        $this->write('modules/Beta/src/Thing.php', '<?php');

        $drift = $this->catalog()->drift('Beta');

        self::assertSame('not-installed', $drift['status']);
        self::assertSame(2, $drift['catalog']);
        self::assertSame(0, $drift['installed']);
    }

    #[Test]
    public function anInstalledModuleWithNoCatalogueEntryIsNotCatalogued(): void
    {
        $this->write('src/Modules/Gamma/src/Thing.php', '<?php');

        $drift = $this->catalog()->drift('Gamma');

        self::assertSame('not-catalogued', $drift['status']);
        self::assertSame(0, $drift['catalog']);
        self::assertSame(1, $drift['installed']);
    }

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog($this->root . '/modules', $this->root . '/src/Modules');
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($path, $content);
    }
}
