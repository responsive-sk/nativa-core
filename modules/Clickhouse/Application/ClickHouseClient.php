<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Application;

use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\ClickHouseError;
use RuntimeException;

/**
 * ClickHouseClient — statements in, rows out.
 *
 * The transport speaks HTTP; this class speaks SQL. `select()` asks for
 * `JSONEachRow` and parses it **line by line**, because that is what the
 * format is: one JSON object per line, and a newline inside a string value is
 * escaped as `\n` rather than sent raw. A whole-body `json_decode()` would
 * therefore read one row's JSON and discard the rest — silently, with an empty
 * second row or none. `select()` also refuses a body that is a JSON *array*:
 * that is `JSON` (or `JSONCompact`), a different format answering a different
 * question, and accepting it would make the client's contract depend on what
 * the server happened to return.
 *
 * `insert()` sends the rows back in the same format: an `INSERT … FORMAT
 * JSONEachRow` statement whose body is the rows, one object per line. The
 * values are encoded by `json_encode`, never concatenated, so a quote in a
 * string cannot end an object early.
 *
 * No method here decides whether a statement is safe to run — that is the
 * command's business (`--write` in Decision 5). This class is the client; it
 * executes what it is given and reports what came back.
 */
final readonly class ClickHouseClient
{
    public function __construct(private ClickHouseTransport $transport)
    {
    }

    /**
     * Run a read statement and return its rows.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ClickHouseError when the server refuses the statement
     * @throws RuntimeException when the answer is not JSONEachRow
     */
    public function select(string $sql, string $database = ''): array
    {
        return self::parseJsonEachRow($this->transport->execute(self::withJsonEachRow($sql), $database));
    }

    /**
     * Run a statement and return the raw body — DDL, mostly.
     *
     * `$database` is the ClickHouse `database` parameter, for the one command
     * that lets an operator name a database per run (`query --db`). Attach and
     * status always address the server default and put the name in the DDL.
     */
    public function emit(string $sql, string $database = ''): string
    {
        return $this->transport->execute($sql, $database);
    }

    /**
     * Insert rows into a table through `JSONEachRow`.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insert(string $table, array $rows): string
    {
        if ([] === $rows) {
            return '';
        }

        $lines = [];
        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $lines[] = $encoded;
        }

        return $this->transport->execute(
            'INSERT INTO ' . $table . ' FORMAT JSONEachRow' . "\n" . implode("\n", $lines),
        );
    }

    /**
     * Whether the server answers `SELECT 1` at all.
     */
    public function ping(): bool
    {
        try {
            return [] !== $this->select('SELECT 1');
        } catch (ClickHouseError) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        return self::names($this->select('SELECT name FROM system.databases ORDER BY name'));
    }

    /**
     * @return list<string>
     */
    public function tables(string $database): array
    {
        return self::names($this->select('SHOW TABLES FROM ' . $database));
    }

    /**
     * The server's own `CREATE DATABASE` statement for a database, which is
     * where `status` reads the attached path back from.
     *
     * @throws ClickHouseError when the database does not exist
     */
    public function createDatabase(string $database): string
    {
        $rows = $this->select('SHOW CREATE DATABASE ' . $database);
        $statement = $rows[0]['statement'] ?? null;

        return is_string($statement) ? $statement : '';
    }

    /**
     * The statement with `FORMAT JSONEachRow` appended, unless it already
     * names a format.
     */
    public static function withJsonEachRow(string $sql): string
    {
        return self::withFormat($sql, 'JSONEachRow');
    }

    /**
     * The statement with `FORMAT <name>` appended, unless it already names a
     * format.
     */
    public static function withFormat(string $sql, string $format): string
    {
        if (1 === preg_match('/\bFORMAT\b/i', $sql)) {
            return $sql;
        }

        $trimmed = rtrim(trim($sql), ';');

        return $trimmed . "\nFORMAT " . $format;
    }

    /**
     * Parse one JSON object per line. An empty body is zero rows.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when a line is not an object, or the body is a
     *                          JSON array (the wrong format)
     */
    public static function parseJsonEachRow(string $body): array
    {
        $trimmed = trim($body);

        if ('' === $trimmed) {
            return [];
        }

        if (str_starts_with($trimmed, '[')) {
            throw new RuntimeException(
                'ClickHouse returned a JSON array; select() needs JSONEachRow (one object per line).',
            );
        }

        $rows = [];

        foreach (explode("\n", $trimmed) as $number => $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf(
                    'ClickHouse returned a body that is not JSONEachRow: line %d does not decode to an object.',
                    $number + 1,
                ));
            }

            /** @var array<string, mixed> $decoded */
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
