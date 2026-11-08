<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Domain;

/**
 * SqliteAttachment — one kernel SQLite file, as a ClickHouse database.
 *
 * The DDL lives here and nowhere else: `clickhouse:attach --dry-run` prints
 * exactly these statements, `apply()` sends exactly these statements, and the
 * tests assert these strings. A second copy of the format string would be a
 * second thing to keep in step with the doc.
 *
 * The path in `ENGINE = SQLite('…')` is resolved **on the ClickHouse host**,
 * not in PHP (proposal, Decision 1). This value object therefore carries the
 * absolute path it was handed and says nothing about whether the server can
 * reach it; `status` compares the path the server reports with this one and
 * calls a difference drift.
 */
final readonly class SqliteAttachment
{
    public function __construct(
        public string $databaseName,
        public string $path,
        public string $file,
    ) {
    }

    /**
     * The `CREATE DATABASE` statement. Idempotent: `IF NOT EXISTS`, because
     * re-pointing an engine is the `--force` path and ClickHouse cannot do it
     * any other way.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return [
            'CREATE DATABASE IF NOT EXISTS ' . $this->databaseName
            . " ENGINE = SQLite('" . self::escape($this->path) . "')",
        ];
    }

    public function detachStatement(): string
    {
        return 'DROP DATABASE IF EXISTS ' . $this->databaseName;
    }

    /**
     * The same database pointing at a different file — what `--force` and the
     * drift comparison need.
     */
    public function withPath(string $path): self
    {
        return new self($this->databaseName, $path, basename($path));
    }

    /**
     * A quote ends the string literal and a backslash changes what follows it;
     * `plan()` refuses both before a value object exists, so this is the last
     * line of defence rather than the first.
     */
    private static function escape(string $path): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $path);
    }
}
