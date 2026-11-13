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

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.2.3', $lock['modules']['Beta']['version']);
        self::assertSame('1.2.3', $lock['modules']['Alpha']['version']);
    }

    #[Test]
    public function refusesAnUpdateChecksumBeforeTouchingTheInstalledModule(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $first = $this->buildRegistry();
        $this->registry($first)->install('Beta');

        $this->writeModule('Beta', 'final class Beta { public function version(): string { return "2.0.0"; } }');
        $second = $this->buildRegistry('2.0.0', true);
        $transport = $this->transport($second, corrupt: 'Beta.tar.gz');

        try {
            $this->registry($second, $transport)->update('Beta');
            $this->fail('A bad update checksum should refuse the update.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('sha256 mismatch', $e->getMessage());
        }

        self::assertStringContainsString('final class Beta {}', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.2.3', $lock['modules']['Beta']['version']);
    }

    #[Test]
    public function updatesAndRollsBackARegistryModule(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $first = $this->buildRegistry();
        $this->registry($first)->install('Beta');

        $this->writeModule('Beta', 'final class Beta { public function version(): string { return "2.0.0"; } }');
        $second = $this->buildRegistry('2.0.0', true);

        $state = $this->registry($second)->state();
        self::assertSame('1.2.3', $state['updates']['Beta']['current']);
        self::assertSame('2.0.0', $state['updates']['Beta']['entry']['version']);

        $result = $this->registry($second)->update('Beta');

        self::assertSame('1.2.3', $result['from']);
        self::assertSame('2.0.0', $result['to']);
        self::assertStringContainsString('2.0.0', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0.0', $lock['modules']['Beta']['version']);
        $rollbackEntry = $lock['modules']['Beta']['rollback'];
        self::assertNotNull($rollbackEntry);
        self::assertSame('1.2.3', $rollbackEntry['version']);
        self::assertSame([], $this->registry($second)->state()['updates']);

        $rollback = $this->registry($second)->rollback('Beta');

        self::assertSame('1.2.3', $rollback['version']);
        self::assertStringContainsString('final class Beta {}', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.2.3', $lock['modules']['Beta']['version']);
        self::assertNull($lock['modules']['Beta']['rollback']);
    }

    #[Test]
    public function forgettingARegistryInstallClearsItsLockAndBackup(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $first = $this->buildRegistry();
        $this->registry($first)->install('Beta');

        $this->writeModule('Beta', 'final class Beta { public function version(): string { return "2.0.0"; } }');
        $second = $this->buildRegistry('2.0.0', true);
        $registry = $this->registry($second);
        $registry->update('Beta');

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        $rollbackEntry = $lock['modules']['Beta']['rollback'];
        self::assertNotNull($rollbackEntry);

        $backup = $this->root . '/storage/' . $rollbackEntry['catalogue'];
        self::assertDirectoryExists($backup);

        $registry->forget('Beta');

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('Beta', $lock['modules']);
        self::assertNull($lock['snapshot']);
        self::assertDirectoryDoesNotExist($backup);

        $state = $registry->state();
        self::assertArrayNotHasKey('Beta', $state['available']);
        self::assertSame([], $state['updates']);
        self::assertSame([], $state['rollbacks']);
    }

    #[Test]
    public function restoresTheCurrentVersionWhenARollbackFails(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $first = $this->buildRegistry();
        $this->registry($first)->install('Beta');

        $this->writeModule('Beta', 'final class Beta { public function version(): string { return "2.0.0"; } }');
        $second = $this->buildRegistry('2.0.0', true);
        $this->registry($second)->update('Beta');

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        $rollbackEntry = $lock['modules']['Beta']['rollback'];
        self::assertNotNull($rollbackEntry);

        $backup = $this->root . '/storage/' . $rollbackEntry['catalogue'];
        self::assertDirectoryExists($backup);
        $this->addUnknownImport($backup, 'Beta');

        try {
            $this->registry($second)->rollback('Beta');
            $this->fail('A rollback that imports an unavailable module should fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('current version restored', $e->getMessage());
            self::assertStringContainsString('Ghost', $e->getMessage());
        }

        self::assertStringContainsString('2.0.0', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));
        self::assertFileDoesNotExist($this->root . '/modules/Beta/src/Ghost.php');

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0.0', $lock['modules']['Beta']['version']);
        self::assertNotNull($lock['modules']['Beta']['rollback']);
        self::assertDirectoryExists($backup);

        /** @var array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index */
        $index = json_decode((string) file_get_contents($this->root . '/modules/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('Ghost', $index['modules']);

        // The failed rollback left its backup in place, so fixing the cause is
        // enough to retry without another update first.
        unlink($backup . '/src/Ghost.php');
        $retry = $this->registry($second)->rollback('Beta');

        self::assertSame('1.2.3', $retry['version']);
        self::assertStringContainsString('final class Beta {}', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));
        self::assertDirectoryDoesNotExist($backup);

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.2.3', $lock['modules']['Beta']['version']);
        self::assertNull($lock['modules']['Beta']['rollback']);
    }

    #[Test]
    public function restoresThePreviousVersionWhenAnUpdateFailsAfterReplacement(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $first = $this->buildRegistry();
        $this->registry($first)->install('Beta');

        $this->writeModule('Beta', 'final class Beta { public function version(): string { return "2.0.0"; } }');
        $second = $this->buildRegistry('2.0.0', true);

        // The registry index says Beta has no dependency, but the packaged code
        // imports Ghost. The installer only sees that after the catalogue has
        // been replaced, which is exactly the failure update must undo.
        $this->addUnknownDependency($second, 'Beta');

        try {
            $this->registry($second)->update('Beta');
            $this->fail('An update that imports an unavailable module should fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('previous version restored', $e->getMessage());
            self::assertStringContainsString('Ghost', $e->getMessage());
        }

        self::assertStringContainsString('final class Beta {}', (string) file_get_contents($this->root . '/modules/Beta/src/Beta.php'));
        self::assertFileDoesNotExist($this->root . '/modules/Beta/src/Ghost.php');

        /** @var array{schema_version: int, snapshot: ?string, modules: array<string, array{version: ?string, sha256: string, archive: string, rollback: ?array{version: ?string, sha256: string, archive: string, catalogue: string}}>} $lock */
        $lock = json_decode((string) file_get_contents($this->root . '/storage/module-registry.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.2.3', $lock['modules']['Beta']['version']);
        self::assertNull($lock['modules']['Beta']['rollback']);

        /** @var array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index */
        $index = json_decode((string) file_get_contents($this->root . '/modules/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('Ghost', $index['modules']);
    }

    #[Test]
    public function removesCopiedCataloguesWhenTheInstallerRefuses(): void
    {
        $this->writeModule('Alpha', 'final class Alpha {}');
        $this->writeModule('Beta', 'final class Beta {}');

        $registryDir = $this->buildRegistry();

        // A file where the slice directory belongs makes ModuleInstaller::add()
        // fail after the registry has already copied modules/Beta. The failed
        // install must not leave that catalogue entry behind as installable code.
        file_put_contents($this->root . '/src/Modules/Beta', 'blocked');

        try {
            $this->registry($registryDir)->install('Beta');
            $this->fail('The installer should refuse a slice path occupied by a file.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Cannot create directory', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/modules/Beta');
        self::assertFileExists($this->root . '/src/Modules/Beta');
        self::assertFileDoesNotExist($this->root . '/storage/module-registry.json');

        /** @var array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index */
        $index = json_decode((string) file_get_contents($this->root . '/modules/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('Beta', $index['modules']);
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

    private function buildRegistry(string $version = '1.2.3', bool $force = false): string
    {
        $registryDir = $this->root . '/registry';
        $arguments = [
            '--root=' . $this->root . '/source',
            '--out=' . $registryDir,
            '--version=' . $version,
        ];

        if ($force) {
            $arguments[] = '--force';
        }

        [$exit, $output] = $this->runBuilder(...$arguments);

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
            $this->root . '/storage/module-registry.json',
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

    /**
     * Add an import the registry index does not declare, emulating a package
     * whose own metadata is incomplete. The index checksum is updated so this
     * deterministic inconsistency reaches ModuleInstaller instead of stage().
     */
    private function addUnknownDependency(string $registryDir, string $name): void
    {
        $archive = $registryDir . '/archives/' . $name . '.tar.gz';
        $extract = $this->root . '/tamper-' . $name;

        self::removeTree($extract);
        mkdir($extract, 0o775, true);
        (new \PharData($archive))->extractTo($extract);

        $this->addUnknownImport($extract, $name);

        $tarPath = $registryDir . '/archives/' . $name . '-tampered.tar';
        $tar = new \PharData($tarPath);
        $prefix = rtrim($extract, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extract, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($prefix)));
            $tar->addFile($file->getPathname(), $relative);
        }

        $tar->compress(\Phar::GZ);
        unset($tar);
        @unlink($tarPath);
        rename($tarPath . '.gz', $archive);
        self::removeTree($extract);

        /** @var array{schema_version: int, version: string, modules: array<string, array{requires: list<string>, archive: string, sha256: string}>} $index */
        $index = json_decode((string) file_get_contents($registryDir . '/registry.json'), true, 512, JSON_THROW_ON_ERROR);
        $index['modules'][$name]['sha256'] = (string) hash_file('sha256', $archive);
        file_put_contents(
            $registryDir . '/registry.json',
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    private function addUnknownImport(string $directory, string $name): void
    {
        file_put_contents(
            $directory . '/src/Ghost.php',
            "<?php\n\ndeclare(strict_types = 1);\n\nnamespace Modules\\" . $name . ";\n\nuse Modules\\Ghost\\Ghost;\n\nfinal class GhostImport\n{\n    public function dependency(): string\n    {\n        return Ghost::class;\n    }\n}\n",
        );
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
