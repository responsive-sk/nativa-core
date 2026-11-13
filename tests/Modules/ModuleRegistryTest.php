<?php

declare(strict_types = 1);

namespace Tests\Modules;

use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;
use Modules\Tooling\ModuleRegistry;
use Modules\Tooling\RegistryTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ModuleRegistryTest — the registry producer and consumer meet at a static
 * directory: the builder writes archives and checksums, the consumer verifies
 * them, stages the closure and lets ModuleInstaller perform the copy.
 */
final class ModuleRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/nativa-registry-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/modules', 0o775, true);
        mkdir($this->root . '/src/Modules', 0o775, true);
        mkdir($this->root . '/source/modules', 0o775, true);
        mkdir($this->root . '/source/src/Modules', 0o775, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function installsRemotelyWithDependencies(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule(
            'Beta',
            "use Modules\\Alpha\\Alpha;\n\nfinal class Beta\n{\n    public function dependency(): string\n    {\n        return Alpha::class;\n    }\n}",
        );

        $registryDir = $this->buildRegistry();
        self::assertSame('1.2.3', $this->registry($registryDir)->available()['Beta']['version']);

        $result = $this->registry($registryDir)->install('Beta');

        self::assertSame(['Alpha', 'Beta'], $result['installed']);
        self::assertDirectoryExists($this->root . '/modules/Alpha');
        self::assertDirectoryExists($this->root . '/modules/Beta');
        self::assertDirectoryExists($this->root . '/src/Modules/Alpha');
        self::assertDirectoryExists($this->root . '/src/Modules/Beta');
        self::assertSame([], $this->registry($registryDir)->available());

        /** @var array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index */
        $index = json_decode((string) file_get_contents($this->root . '/modules/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Alpha', 'Beta'], array_keys($index['modules']));
        self::assertSame(['Alpha'], $index['modules']['Beta']['requires']);
    }

    #[Test]
    public function refusesAChecksumMismatchBeforeCopying(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $registryDir = $this->buildRegistry();
        $transport = $this->transport($registryDir, corrupt: 'Beta.tar.gz');

        try {
            $this->registry($registryDir, $transport)->install('Beta');
            $this->fail('A bad checksum should refuse the install.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('sha256 mismatch', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/modules/Alpha');
        self::assertDirectoryDoesNotExist($this->root . '/modules/Beta');
        self::assertDirectoryDoesNotExist($this->root . '/src/Modules/Beta');
    }

    #[Test]
    public function refusesACycleBeforeCopying(): void
    {
        $this->writeModule(
            'Alpha',
            "use Modules\\Beta\\Beta;\n\nfinal class Alpha\n{\n    public function dependency(): string\n    {\n        return Beta::class;\n    }\n}",
        );
        $this->writeModule(
            'Beta',
            "use Modules\\Alpha\\Alpha;\n\nfinal class Beta\n{\n    public function dependency(): string\n    {\n        return Alpha::class;\n    }\n}",
        );

        $registryDir = $this->buildRegistry();

        try {
            $this->registry($registryDir)->install('Alpha');
            $this->fail('A registry dependency cycle should refuse the install.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('dependency cycle', $e->getMessage());
            self::assertStringContainsString('Alpha', $e->getMessage());
            self::assertStringContainsString('Beta', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/modules/Alpha');
        self::assertDirectoryDoesNotExist($this->root . '/modules/Beta');
    }

    private function buildRegistry(): string
    {
        $registryDir = $this->root . '/registry';
        [$exit, $output] = $this->runBuilder(
            '--root=' . $this->root . '/source',
            '--out=' . $registryDir,
            '--version=1.2.3',
        );

        self::assertSame(0, $exit, $output);

        return $registryDir;
    }

    private function registry(string $registryDir, ?RegistryTransport $transport = null): ModuleRegistry
    {
        $catalog = $this->catalog();

        return new ModuleRegistry(
            $catalog,
            new ModuleInstaller($catalog),
            $transport ?? $this->transport($registryDir),
            'https://registry.test/registry.json',
            $this->root . '/modules',
        );
    }

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog($this->root . '/modules', $this->root . '/src/Modules');
    }

    private function transport(string $registryDir, ?string $corrupt = null): RegistryTransport
    {
        return new class ($registryDir, $corrupt) implements RegistryTransport {
            public function __construct(
                private readonly string $directory,
                private readonly ?string $corrupt,
            ) {
            }

            public function get(string $url, int $limit): string
            {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $file = $this->directory . $path;

                if (!is_file($file)) {
                    throw new RuntimeException('Missing fixture: ' . $file);
                }

                $body = file_get_contents($file);

                if (false === $body) {
                    throw new RuntimeException('Cannot read fixture: ' . $file);
                }

                if (null !== $this->corrupt && str_ends_with($path, $this->corrupt)) {
                    $body .= 'tampered';
                }

                if (strlen($body) > $limit) {
                    throw new RuntimeException('Fixture is larger than the limit.');
                }

                return $body;
            }
        };
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runBuilder(string ...$arguments): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/build/module-registry.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        exec($command . ' 2>&1', $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }

    private function writeModule(string $name, string $body): void
    {
        $path = $this->root . '/source/modules/' . $name . '/src/' . $name . '.php';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        file_put_contents($path, "<?php\n\ndeclare(strict_types = 1);\n\nnamespace Modules\\" . $name . ";\n\n" . $body . "\n");
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
