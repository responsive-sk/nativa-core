<?php

declare(strict_types = 1);

namespace Tests\Modules;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModuleCatalogueIndexTest — `modules/catalogue.json` is generated from the
 * catalogue, so the gate must catch a missing or stale file, and the generator
 * must refuse an entry whose dependency nobody can install.
 */
final class ModuleCatalogueIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nativa-catalogue-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/modules', 0o775, true);
        mkdir($this->root . '/src/Modules', 0o775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function writesADeterministicIndexWithDependencies(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule(
            'Beta',
            "use Modules\\Alpha\\Alpha;\n\nfinal class Beta\n{\n    public function dependency(): string\n    {\n        return Alpha::class;\n    }\n}",
        );
        $this->writeModule('Nativa', 'final class Nativa {}', 'Themes');

        [$exit, $output] = $this->runGenerator('--write', '--root=' . $this->root);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Wrote modules/catalogue.json (3 modules)', $output);

        /** @var array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $data */
        $data = json_decode((string) file_get_contents($this->root . '/modules/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['schema_version']);
        self::assertSame(['Alpha', 'Beta', 'Nativa'], array_keys($data['modules']));
        self::assertSame('modules/Alpha', $data['modules']['Alpha']['source']);
        self::assertSame([], $data['modules']['Alpha']['requires']);
        self::assertSame(['Alpha'], $data['modules']['Beta']['requires']);
        self::assertSame('modules/Themes/Nativa', $data['modules']['Nativa']['source']);

        [$exit, $output] = $this->runGenerator('--check', '--root=' . $this->root);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('up to date (3 modules)', $output);
    }

    #[Test]
    public function theRepositoryIndexIsCurrent(): void
    {
        [$exit, $output] = $this->runGenerator('--check', '--root=' . dirname(__DIR__, 2));

        self::assertSame(0, $exit, $output);
    }

    #[Test]
    public function checkFailsWhenTheIndexIsStale(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        [$exit, $output] = $this->runGenerator('--write', '--root=' . $this->root);
        self::assertSame(0, $exit, $output);

        $this->writeModule('Beta', 'final class Beta {}');

        [$exit, $output] = $this->runGenerator('--check', '--root=' . $this->root);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('stale', $output);
        self::assertStringContainsString('missing: Beta', $output);
        self::assertStringContainsString('--write', $output);
    }

    #[Test]
    public function refusesADependencyThatIsNotCatalogued(): void
    {
        $this->writeModule(
            'Alpha',
            "use Modules\\Nope\\Nope;\n\nfinal class Alpha\n{\n    public function dependency(): string\n    {\n        return Nope::class;\n    }\n}",
        );

        [$exit, $output] = $this->runGenerator('--write', '--root=' . $this->root);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString("Module 'Alpha' requires 'Nope', which is not catalogued", $output);
        self::assertFileDoesNotExist($this->root . '/modules/catalogue.json');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runGenerator(string ...$arguments): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/build/module-catalogue.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        exec($command . ' 2>&1', $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }

    private function writeModule(string $name, string $body, ?string $group = null): void
    {
        $relative = 'modules/' . (null === $group ? '' : $group . '/') . $name . '/src/' . $name . '.php';
        $path = $this->root . '/' . $relative;
        $namespace = 'Modules\\' . (null === $group ? '' : $group . '\\') . $name;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        file_put_contents($path, "<?php\n\ndeclare(strict_types = 1);\n\nnamespace " . $namespace . ";\n\n" . $body . "\n");
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
