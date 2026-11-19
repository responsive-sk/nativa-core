<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Export\DatabaseTransfer;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Interfaces\CLI\Command\ExportCommand;
use Modules\Database\Interfaces\CLI\Command\ImportCommand;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SqliteDatabase;

/**
 * DatabaseTransferTest — the operator's engine-switch path, on real files.
 *
 * The format is data only, so the test builds the same schema on both sides
 * and lets the transfer do the work the command does: export, switch the
 * process to the target files, import, read the rows back. The boolean column
 * is the interesting one because SQLite stores 1/0 while the snapshot carries
 * true/false and the timestamp is stored without the offset PostgreSQL attaches.
 */
final class DatabaseTransferTest extends TestCase
{
    use SqliteDatabase;

    private string $directory = '';

    private string $sourceAlpha = '';

    private string $sourceBeta = '';

    private string $targetAlpha = '';

    private string $targetBeta = '';

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        $this->directory = sys_get_temp_dir() . '/nativa-transfer-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0o775, true);

        $this->sourceAlpha = $this->directory . '/source-alpha.db';
        $this->sourceBeta = $this->directory . '/source-beta.db';
        $this->targetAlpha = $this->directory . '/target-alpha.db';
        $this->targetBeta = $this->directory . '/target-beta.db';

        $this->useDatabase('alpha', $this->sourceAlpha);
        $this->useDatabase('beta', $this->sourceBeta);

        $this->createAlphaSchema($this->sourceAlpha, true);
        $this->createBetaSchema($this->sourceBeta, true);

        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        DatabaseConnectionManager::resetInstance();
        $this->restoreDatabaseEnvironment();

        if ('' !== $this->directory) {
            $this->removeTree($this->directory);
        }

        parent::tearDown();

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function aSnapshotRoundTripsRowsBooleansAndNulls(): void
    {
        $export = $this->directory . '/export';
        $exported = (new DatabaseTransfer(DatabaseConnectionManager::instance()))
            ->export(['beta', 'alpha'], $export);

        self::assertSame(2, $exported['databases']);
        self::assertSame(3, $exported['tables']);
        self::assertSame(5, $exported['rows']);
        self::assertFileExists($export . '/manifest.json');
        self::assertFileExists($export . '/alpha.ndjson');
        self::assertFileExists($export . '/beta.ndjson');

        $manifest = json_decode((string) file_get_contents($export . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['format']);
        self::assertSame('sqlite', $manifest['source_driver']);

        $imported = $this->switchToTargets()->import(['alpha', 'beta'], $export, false);

        self::assertSame(2, $imported['databases']);
        self::assertSame(3, $imported['tables']);
        self::assertSame(5, $imported['rows']);

        $pdo = new PDO('sqlite:' . $this->targetAlpha);
        $parents = $this->rows($pdo, 'SELECT id, name, active, created_at FROM parents ORDER BY id');

        self::assertCount(2, $parents);
        self::assertSame(1, $parents[0]['id']);
        self::assertSame('one', $parents[0]['name']);
        self::assertSame(1, $parents[0]['active']);
        self::assertSame('2026-11-19 07:41:02', $parents[0]['created_at']);
        self::assertSame(0, $parents[1]['active']);
        self::assertSame('2026-11-19 07:41:02', $parents[1]['created_at'], 'an offset is normalised to the same UTC instant');

        $children = $this->rows($pdo, 'SELECT id, parent_id, note FROM children ORDER BY id');

        self::assertCount(2, $children);
        self::assertNull($children[0]['note']);
        self::assertSame('kept', $children[1]['note']);
    }

    #[Test]
    public function aNonEmptyTargetIsRefusedUnlessTheImportIsForced(): void
    {
        $export = $this->directory . '/export';
        (new DatabaseTransfer(DatabaseConnectionManager::instance()))->export(['alpha', 'beta'], $export);

        $transfer = $this->switchToTargets();
        $pdo = new PDO('sqlite:' . $this->targetAlpha);
        $pdo->exec("INSERT INTO parents (id, name, active, created_at) VALUES (99, 'local', 0, '2026-11-19 07:41:02')");

        try {
            $transfer->import(['alpha', 'beta'], $export, false);
            self::fail('a non-empty target must be refused without --force');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not empty', $exception->getMessage());
            self::assertStringContainsString('--force', $exception->getMessage());
        }

        $result = $transfer->import(['alpha', 'beta'], $export, true);

        self::assertSame(5, $result['rows']);
        $count = $pdo->query('SELECT COUNT(*) FROM parents');
        self::assertNotFalse($count);
        self::assertSame(2, $count->fetchColumn());
    }

    #[Test]
    public function anEmptyAppendOnlyTableSurvivesAForcedImport(): void
    {
        $this->createEventSchema($this->sourceAlpha);
        $export = $this->directory . '/append-only-export';
        (new DatabaseTransfer(DatabaseConnectionManager::instance()))->export(['alpha'], $export);

        $this->switchToTargets();
        $this->createEventSchema($this->targetAlpha);

        $result = (new DatabaseTransfer(DatabaseConnectionManager::instance()))
            ->import(['alpha'], $export, true);

        self::assertSame(1, $result['databases']);
        self::assertSame(3, $result['tables']);
        self::assertSame(4, $result['rows']);
    }

    #[Test]
    public function theCommandsExportAndImportTheSameSnapshot(): void
    {
        $export = $this->directory . '/command-export';
        $output = new TransferOutput();
        $exit = (new ExportCommand(
            new DatabaseTransfer(DatabaseConnectionManager::instance()),
            ['alpha', 'beta'],
        ))->run(['db:export', $export], $output);

        self::assertSame(0, $exit, $output->text());
        self::assertStringContainsString('db:export: 2 database(s), 3 table(s), 5 row(s).', $output->text());

        $importOutput = new TransferOutput();
        $exit = (new ImportCommand($this->switchToTargets(), ['alpha', 'beta']))
            ->run(['db:import', $export, '--force'], $importOutput);

        self::assertSame(0, $exit, $importOutput->text());
        self::assertStringContainsString('db:import: 2 database(s), 3 table(s), 5 row(s).', $importOutput->text());
    }

    private function switchToTargets(): DatabaseTransfer
    {
        putenv('DB_ALPHA=' . $this->targetAlpha);
        putenv('DB_BETA=' . $this->targetBeta);
        DatabaseConnectionManager::resetInstance();

        $this->createAlphaSchema($this->targetAlpha, false);
        $this->createBetaSchema($this->targetBeta, false);

        return new DatabaseTransfer(DatabaseConnectionManager::instance());
    }

    private function createAlphaSchema(string $path, bool $source): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec(
            'CREATE TABLE parents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                active BOOLEAN NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL
            )',
        );
        $pdo->exec(
            'CREATE TABLE children (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NOT NULL REFERENCES parents(id),
                note TEXT NULL
            )',
        );

        if ($source) {
            $pdo->exec("INSERT INTO parents (id, name, active, created_at) VALUES (1, 'one', 1, '2026-11-19 07:41:02'), (2, 'two', 0, '2026-11-19 08:41:02+01')");
            $pdo->exec("INSERT INTO children (id, parent_id, note) VALUES (10, 1, NULL), (11, 2, 'kept')");
        }
    }

    private function createEventSchema(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $pdo->exec(
            "CREATE TRIGGER events_no_delete BEFORE DELETE ON events BEGIN
                SELECT RAISE(ABORT, 'events is immutable');
            END",
        );
    }

    private function createBetaSchema(string $path, bool $source): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec(
            'CREATE TABLE notes (
                id INTEGER PRIMARY KEY,
                body TEXT NOT NULL
            )',
        );

        if ($source) {
            $pdo->exec("INSERT INTO notes (id, body) VALUES (1, 'first')");
        }
    }

    private function useDatabase(string $name, string $path): void
    {
        putenv('DB_' . strtoupper($name) . '=' . $path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        if (false === $statement) {
            throw new RuntimeException('Cannot read the target database.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}

/**
 * A CliOutput that keeps every line for the command assertions.
 */
final class TransferOutput implements CliOutput
{
    /** @var list<string> */
    public array $lines = [];

    public function writeln(string $message): void
    {
        $this->lines[] = $message;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}
