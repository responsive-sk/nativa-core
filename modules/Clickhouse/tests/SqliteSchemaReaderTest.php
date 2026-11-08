<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Tests;

use Modules\Clickhouse\Application\Services\SqliteSchemaReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PDO;
use RuntimeException;

/**
 * SqliteSchemaReaderTest — what a real SQLite file declares, read read-only.
 *
 * Fixtures are real files, not fakes: `PRAGMA table_info` is the only source
 * the planner has, and the declaration it returns is the entire input to type
 * inference. The `sqlite_sequence` table comes from AUTOINCREMENT and is the
 * reason the `sqlite_%` skip exists — without it every plan would name
 * SQLite's own bookkeeping as a data table.
 */
final class SqliteSchemaReaderTest extends TestCase
{
    private const MODULE = 'Clickhouse';

    /** @var list<string> */
    private array $created = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the reader lives in that module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->directories as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $this->created = [];
        $this->directories = [];
    }

    #[Test]
    public function tablesListsDataTablesAndSkipsSqliteInternals(): void
    {
        $path = $this->fixture(
            'CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, viewed_at DATETIME)',
            'CREATE TABLE notes (body TEXT)',
        );

        self::assertSame(['events', 'notes'], (new SqliteSchemaReader($path))->tables());
    }

    #[Test]
    public function columnsKeepTheDeclaredTypeInOrder(): void
    {
        $path = $this->fixture(
            'CREATE TABLE events (id INTEGER, viewed_at DATETIME, price DECIMAL(10,2), uid UUID)',
        );

        self::assertSame([
            ['name' => 'id', 'declared' => 'INTEGER'],
            ['name' => 'viewed_at', 'declared' => 'DATETIME'],
            ['name' => 'price', 'declared' => 'DECIMAL(10,2)'],
            ['name' => 'uid', 'declared' => 'UUID'],
        ], (new SqliteSchemaReader($path))->columns('events'));
    }

    #[Test]
    public function aDirectoryIsRefusedRatherThanReadAsEmpty(): void
    {
        $directory = sys_get_temp_dir() . '/clickhouse-schema-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o775, true);
        $this->directories[] = $directory;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No SQLite file to read');

        (new SqliteSchemaReader($directory))->tables();
    }

    #[Test]
    public function aMissingFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No SQLite file to read');

        (new SqliteSchemaReader(sys_get_temp_dir() . '/clickhouse-not-there-' . bin2hex(random_bytes(4)) . '.db'))->tables();
    }

    private function fixture(string ...$statements): string
    {
        $path = sys_get_temp_dir() . '/clickhouse-schema-' . bin2hex(random_bytes(4)) . '.db';
        $this->created[] = $path;

        $pdo = new PDO('sqlite:' . $path);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return $path;
    }
}
