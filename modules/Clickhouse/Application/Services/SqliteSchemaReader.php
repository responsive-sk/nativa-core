<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Application\Services;

use Modules\Clickhouse\Infrastructure\Persistence\Statement;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SqliteSchemaReader — what a kernel SQLite file declares, read without
 * writing a byte.
 *
 * The handle is opened with `PDO::SQLITE_OPEN_READONLY` and immediately says
 * `PRAGMA query_only = ON`, so even a bug here cannot write to a file the
 * kernel is serving. This is the only place the module opens a kernel database
 * directly; everything else goes through ClickHouse.
 *
 * `sqlite_%` tables are SQLite's own (`sqlite_sequence`, …) and are skipped:
 * ClickHouse cannot read them, and listing them would make every plan carry
 * three tables that are not data.
 *
 * A directory, a missing file or a handle SQLite refuses is a refusal, not an
 * empty schema: "this database has no tables" and "this database cannot be
 * read" must never print the same thing.
 */
final readonly class SqliteSchemaReader
{
    public function __construct(public string $path)
    {
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when the file cannot be opened read-only
     */
    public function tables(): array
    {
        $pdo = $this->connection();
        $statement = Statement::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $names = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * `PRAGMA table_info` in declaration order.
     *
     * @return list<array{name: string, declared: string}>
     *
     * @throws RuntimeException when the file cannot be opened read-only
     */
    public function columns(string $table): array
    {
        $pdo = $this->connection();
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $statement = Statement::query($pdo, 'PRAGMA table_info(' . $quoted . ')');
        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }

            $columns[] = [
                'name'     => $row['name'],
                'declared' => is_string($row['type'] ?? null) ? $row['type'] : '',
            ];
        }

        return $columns;
    }

    /**
     * @throws RuntimeException when the path is not a readable SQLite file
     */
    private function connection(): PDO
    {
        if (!is_file($this->path)) {
            throw new RuntimeException(sprintf('No SQLite file to read: %s', $this->path));
        }

        try {
            $pdo = new PDO(
                'sqlite:' . $this->path,
                null,
                null,
                [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('PRAGMA query_only = ON');
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Cannot open %s read-only: %s', $this->path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        return $pdo;
    }
}
