<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Export;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * DatabaseTransfer — one portable snapshot for every module database.
 *
 * The format is deliberately not a SQL dump. SQLite, PostgreSQL and MariaDB
 * DDL differ, and a dump that includes one engine's schema cannot be restored
 * into another. A snapshot carries the DATA only:
 *
 *   manifest.json      format, source driver, one entry per database, table
 *                      columns/types/dependencies/row counts and file hashes
 *   <database>.ndjson  one JSON object per row, in the manifest's column order
 *
 * The target schema is built by the real migrators before an import, so both
 * sides agree on table and column names. Values stay typed in JSON: null is
 * null, numbers are numbers, and a BOOLEAN is normalised to true/false even
 * when the source stores it as 1/0. Import reverses that for the target
 * column type. A TIMESTAMP is written as UTC without an offset, because SQLite
 * stores UTC text while PostgreSQL returns the same instant with the session's
 * offset attached; leaving either spelling alone would shift the value.
 *
 * Tables are written and read in foreign-key order. A schema with a cycle (a
 * tree whose parent is in the same table is the common one) falls back to a
 * stable alphabetical order rather than refusing the whole transfer.
 */
final readonly class DatabaseTransfer
{
    public function __construct(private DatabaseConnectionManager $connections)
    {
    }

    /**
     * Write every named database below $directory.
     *
     * The directory must not exist with content: an export that overwrote a
     * previous snapshot would destroy the only copy of the old data.
     *
     * @param list<string> $databases
     *
     * @return array{databases: int, tables: int, rows: int, directory: string}
     */
    public function export(array $databases, string $directory): array
    {
        $databases = $this->normaliseDatabases($databases);
        $this->prepareDirectory($directory);

        $manifest = [
            'format'       => 1,
            'source_driver' => $this->connections->driverName(),
            'created_at'   => gmdate('c'),
            'databases'    => [],
        ];

        $tableCount = 0;
        $rowCount = 0;

        foreach ($databases as $database) {
            $pdo = $this->connections->connection($database);
            $dialect = SqlDialect::forPdo($pdo);
            $tables = $dialect->tableNames($pdo);
            $dependencies = $dialect->foreignKeys($pdo);
            $ordered = $this->orderTables($tables, $dependencies);

            $file = $directory . DIRECTORY_SEPARATOR . $database . '.ndjson';
            $handle = fopen($file, 'wb');

            if (false === $handle) {
                throw new RuntimeException(sprintf('Cannot write the snapshot file %s.', $file));
            }

            $tableManifest = [];

            try {
                foreach ($ordered as $table) {
                    $types = $dialect->columnTypes($pdo, $table);

                    if ([] === $types) {
                        throw new RuntimeException(sprintf('Cannot read the columns of %s.%s.', $database, $table));
                    }

                    $columns = array_keys($types);
                    $tableRows = 0;
                    $statement = $pdo->query($this->selectSql($dialect, $table, $columns));

                    if (false === $statement) {
                        throw new RuntimeException(sprintf('Cannot read %s.%s.', $database, $table));
                    }

                    while (false !== ($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                        $values = [];

                        foreach ($columns as $column) {
                            /** @var mixed $value */
                            $value = $row[$column] ?? null;
                            $values[$column] = $this->exportValue($value, $types[$column]);
                        }

                        $line = json_encode(
                            $values,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        ) . "\n";

                        if (strlen($line) !== fwrite($handle, $line)) {
                            throw new RuntimeException(sprintf('Cannot write a row of %s.%s.', $database, $table));
                        }

                        $tableRows++;
                    }

                    $tableDependencies = [];

                    foreach ($dependencies[$table] ?? [] as $parent) {
                        if ($parent !== $table && in_array($parent, $tables, true)) {
                            $tableDependencies[] = $parent;
                        }
                    }

                    sort($tableDependencies);

                    $tableManifest[] = [
                        'name'         => $table,
                        'columns'      => $columns,
                        'types'        => $types,
                        'dependencies' => $tableDependencies,
                        'rows'         => $tableRows,
                    ];

                    $tableCount++;
                    $rowCount += $tableRows;
                }
            } finally {
                fclose($handle);
            }

            $hash = hash_file('sha256', $file);

            if (false === $hash) {
                throw new RuntimeException(sprintf('Cannot hash the snapshot file %s.', $file));
            }

            $manifest['databases'][] = [
                'name'   => $database,
                'driver' => $dialect->driver(),
                'file'   => basename($file),
                'sha256' => $hash,
                'tables' => $tableManifest,
            ];
        }

        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';

        if (strlen($encoded) !== file_put_contents($manifestPath, $encoded)) {
            throw new RuntimeException(sprintf('Cannot write the snapshot manifest %s.', $manifestPath));
        }

        return [
            'databases' => count($databases),
            'tables'    => $tableCount,
            'rows'      => $rowCount,
            'directory' => $directory,
        ];
    }

    /**
     * Restore a snapshot into the named databases.
     *
     * Without $replace, a target table with rows refuses the import before a
     * single write. With it, every non-empty target table is cleared in reverse
     * dependency order first; an empty table is already clear, which matters for
     * an append-only table whose trigger refuses a DELETE. The caller runs the
     * migrators before this method,
     * because the schema is engine-specific and the snapshot intentionally
     * carries no DDL.
     *
     * @param list<string> $databases
     *
     * @return array{databases: int, tables: int, rows: int, directory: string}
     */
    public function import(array $databases, string $directory, bool $replace): array
    {
        $databases = $this->normaliseDatabases($databases);
        $entries = $this->readManifest($directory);
        $allowed = array_fill_keys($databases, true);

        /** @var list<array{name: string, file: string, sha256: string, tables: array<string, array{columns: list<string>, rows: int, dependencies: list<string>}>}> $plans */
        $plans = [];

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;

            if (!is_string($name) || '' === $name) {
                throw new RuntimeException('The snapshot has a database entry without a name.');
            }

            if (!isset($allowed[$name])) {
                throw new RuntimeException(sprintf(
                    'The snapshot contains database "%s", which this tree does not install.',
                    $name,
                ));
            }

            $file = $entry['file'] ?? null;
            $sha256 = $entry['sha256'] ?? null;
            $tables = $entry['tables'] ?? null;

            if (!is_string($file) || basename($file) !== $file || !is_string($sha256) || !is_array($tables)) {
                throw new RuntimeException(sprintf('The snapshot entry for %s is incomplete.', $name));
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (!is_file($path)) {
                throw new RuntimeException(sprintf('The snapshot file for %s is missing: %s', $name, $path));
            }

            if (hash_file('sha256', $path) !== $sha256) {
                throw new RuntimeException(sprintf('The snapshot file for %s does not match its hash.', $name));
            }

            $tablePlans = [];

            foreach ($tables as $table) {
                if (!is_array($table)) {
                    throw new RuntimeException(sprintf('The snapshot entry for %s has an invalid table.', $name));
                }

                $tableName = $table['name'] ?? null;
                $columns = $table['columns'] ?? null;
                $rows = $table['rows'] ?? null;
                $dependencies = $table['dependencies'] ?? [];

                if (!is_string($tableName) || '' === $tableName || !is_array($columns) || !is_int($rows)) {
                    throw new RuntimeException(sprintf('The snapshot entry for %s has an invalid table.', $name));
                }

                $columnNames = [];

                foreach ($columns as $column) {
                    if (!is_string($column) || '' === $column) {
                        throw new RuntimeException(sprintf('The snapshot table %s has an invalid column.', $tableName));
                    }

                    $columnNames[] = $column;
                }

                $tableDependencies = [];

                foreach (is_array($dependencies) ? $dependencies : [] as $dependency) {
                    if (is_string($dependency) && '' !== $dependency) {
                        $tableDependencies[] = $dependency;
                    }
                }

                $tablePlans[$tableName] = [
                    'columns'      => $columnNames,
                    'rows'         => $rows,
                    'dependencies' => $tableDependencies,
                ];
            }

            $plans[] = [
                'name'   => $name,
                'file'   => $file,
                'sha256' => $sha256,
                'tables' => $tablePlans,
            ];
        }

        $tableCount = 0;
        $rowCount = 0;

        foreach ($plans as $plan) {
            $pdo = $this->connections->connection($plan['name']);
            $dialect = SqlDialect::forPdo($pdo);
            $targetTables = array_fill_keys($dialect->tableNames($pdo), true);
            $targetTypes = [];

            foreach ($plan['tables'] as $table => $metadata) {
                if (!isset($targetTables[$table])) {
                    throw new RuntimeException(sprintf(
                        'The target %s database has no table %s.',
                        $plan['name'],
                        $table,
                    ));
                }

                $types = $dialect->columnTypes($pdo, $table);

                foreach ($metadata['columns'] as $column) {
                    if (!array_key_exists($column, $types)) {
                        throw new RuntimeException(sprintf(
                            'The target %s.%s has no column %s.',
                            $plan['name'],
                            $table,
                            $column,
                        ));
                    }
                }

                $targetTypes[$table] = $types;
            }

            $dependencies = [];

            foreach ($plan['tables'] as $table => $metadata) {
                $dependencies[$table] = array_values(array_filter(
                    $metadata['dependencies'],
                    static fn (string $parent): bool => isset($plan['tables'][$parent]),
                ));
            }

            $ordered = $this->orderTables(array_keys($plan['tables']), $dependencies);
            $nonEmpty = [];
            $counts = [];

            foreach ($ordered as $table) {
                $statement = $pdo->query('SELECT COUNT(*) FROM ' . $dialect->quoteIdentifier($table));

                if (false === $statement) {
                    throw new RuntimeException(sprintf('Cannot count the rows of %s.%s.', $plan['name'], $table));
                }

                $count = (int) $statement->fetchColumn();
                $counts[$table] = $count;

                if ($count > 0) {
                    $nonEmpty[] = $table . ' (' . $count . ')';
                }
            }

            if ([] !== $nonEmpty && !$replace) {
                throw new RuntimeException(sprintf(
                    'The target %s database is not empty: %s. Re-run with --force to replace its rows.',
                    $plan['name'],
                    implode(', ', $nonEmpty),
                ));
            }

            $pdo->beginTransaction();

            try {
                if ($replace) {
                    foreach (array_reverse($ordered) as $table) {
                        if (0 === ($counts[$table] ?? 0)) {
                            continue;
                        }

                        $pdo->exec('DELETE FROM ' . $dialect->quoteIdentifier($table));
                    }
                }

                $handle = fopen($directory . DIRECTORY_SEPARATOR . $plan['file'], 'rb');

                if (false === $handle) {
                    throw new RuntimeException(sprintf('Cannot read the snapshot file for %s.', $plan['name']));
                }

                try {
                    foreach ($ordered as $table) {
                        $metadata = $plan['tables'][$table];
                        $columns = $metadata['columns'];
                        $identity = $dialect->identityColumns($pdo, $table);
                        $insert = 'INSERT INTO ' . $dialect->quoteIdentifier($table)
                            . ' (' . $this->quoteList($dialect, $columns) . ')'
                            . ([] === $identity ? '' : $dialect->overridingIdentity())
                            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                        $statement = $pdo->prepare($insert);

                        if (false === $statement) {
                            throw new RuntimeException(sprintf('Cannot prepare the insert for %s.%s.', $plan['name'], $table));
                        }

                        $imported = 0;

                        // Read exactly the rows the manifest promises for THIS
                        // table. Reading to EOF here would feed the next table's
                        // rows into this one, which is silent for nullable
                        // columns and a constraint error for the rest.
                        for ($rowNumber = 1; $rowNumber <= $metadata['rows']; $rowNumber++) {
                            $line = fgets($handle);

                            if (false === $line) {
                                throw new RuntimeException(sprintf(
                                    'The snapshot file for %s.%s ended after %d row(s), expected %d.',
                                    $plan['name'],
                                    $table,
                                    $imported,
                                    $metadata['rows'],
                                ));
                            }

                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                            if (!is_array($decoded)) {
                                throw new RuntimeException(sprintf(
                                    'The snapshot row %d of %s.%s is not an object.',
                                    $imported + 1,
                                    $plan['name'],
                                    $table,
                                ));
                            }

                            foreach ($columns as $index => $column) {
                                /** @var mixed $value */
                                $value = $decoded[$column] ?? null;
                                $value = $this->importValue($value, $targetTypes[$table][$column], $dialect);
                                $this->bindValue($statement, $index + 1, $value, $plan['name'], $column);
                            }

                            $statement->execute();
                            $imported++;
                        }

                        if ($imported !== $metadata['rows']) {
                            throw new RuntimeException(sprintf(
                                'The snapshot says %s.%s has %d row(s), but the file contains %d.',
                                $plan['name'],
                                $table,
                                $metadata['rows'],
                                $imported,
                            ));
                        }

                        foreach ($identity as $column) {
                            $dialect->resetIdentity($pdo, $table, $column);
                        }

                        $tableCount++;
                        $rowCount += $imported;
                    }
                } finally {
                    fclose($handle);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw new RuntimeException(
                    sprintf('Import of %s failed: %s', $plan['name'], $exception->getMessage()),
                    0,
                    $exception,
                );
            }
        }

        return [
            'databases' => count($plans),
            'tables'    => $tableCount,
            'rows'      => $rowCount,
            'directory' => $directory,
        ];
    }

    /**
     * @param list<string> $databases
     *
     * @return list<string>
     */
    private function normaliseDatabases(array $databases): array
    {
        $normalised = [];

        foreach ($databases as $database) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $database)) {
                throw new RuntimeException(sprintf('Invalid database name "%s" in the transfer.', $database));
            }

            $normalised[$database] = true;
        }

        $result = array_keys($normalised);
        sort($result);

        if ([] === $result) {
            throw new RuntimeException('No module databases were found to transfer.');
        }

        return $result;
    }

    private function prepareDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $entries = scandir($directory);

            if (false === $entries) {
                throw new RuntimeException(sprintf('Cannot read the export directory %s.', $directory));
            }

            if ([] !== array_diff($entries, ['.', '..'])) {
                throw new RuntimeException(sprintf(
                    'The export directory %s is not empty; choose a new directory.',
                    $directory,
                ));
            }

            return;
        }

        if (file_exists($directory)) {
            throw new RuntimeException(sprintf('%s exists and is not a directory.', $directory));
        }

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the export directory %s.', $directory));
        }
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private function readManifest(string $directory): array
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'manifest.json';

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No snapshot manifest at %s.', $path));
        }

        $raw = file_get_contents($path);

        if (false === $raw) {
            throw new RuntimeException(sprintf('Cannot read the snapshot manifest %s.', $path));
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || 1 !== ($decoded['format'] ?? null)) {
            throw new RuntimeException(sprintf('The snapshot manifest %s has an unsupported format.', $path));
        }

        $databases = $decoded['databases'] ?? null;

        if (!is_array($databases)) {
            throw new RuntimeException(sprintf('The snapshot manifest %s has no database list.', $path));
        }

        $entries = [];

        foreach ($databases as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $columns
     */
    private function selectSql(SqlDialect $dialect, string $table, array $columns): string
    {
        return 'SELECT ' . $this->quoteList($dialect, $columns) . ' FROM ' . $dialect->quoteIdentifier($table);
    }

    /**
     * @param list<string> $columns
     */
    private function quoteList(SqlDialect $dialect, array $columns): string
    {
        $quoted = [];

        foreach ($columns as $column) {
            $quoted[] = $dialect->quoteIdentifier($column);
        }

        return implode(', ', $quoted);
    }

    /**
     * @param list<string>                $tables
     * @param array<string, list<string>> $dependencies
     *
     * @return list<string>
     */
    private function orderTables(array $tables, array $dependencies): array
    {
        $remaining = array_fill_keys($tables, true);
        $order = [];

        while ([] !== $remaining) {
            $ready = [];

            foreach (array_keys($remaining) as $table) {
                $blocked = false;

                foreach ($dependencies[$table] ?? [] as $parent) {
                    if ($parent !== $table && isset($remaining[$parent])) {
                        $blocked = true;

                        break;
                    }
                }

                if (!$blocked) {
                    $ready[] = $table;
                }
            }

            if ([] === $ready) {
                $cycle = array_keys($remaining);
                sort($cycle);

                foreach ($cycle as $table) {
                    $order[] = $table;
                }

                break;
            }

            sort($ready);

            foreach ($ready as $table) {
                $order[] = $table;
                unset($remaining[$table]);
            }
        }

        return $order;
    }

    private function exportValue(mixed $value, string $type): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($this->isBooleanType($type)) {
            return $this->toBoolean($value);
        }

        if ($this->isTimestampType($type) && is_string($value)) {
            return $this->utcTimestamp($value);
        }

        return $value;
    }

    private function importValue(mixed $value, string $type, SqlDialect $dialect): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($this->isBooleanType($type)) {
            return $this->toBoolean($value);
        }

        if (!$dialect->isSqlite() && $this->isZonedTimestampType($type) && is_string($value)) {
            return $this->withUtcOffset($value);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function bindValue(PDOStatement $statement, int $position, mixed $value, string $database, string $column): void
    {
        if (null === $value) {
            $statement->bindValue($position, null, PDO::PARAM_NULL);

            return;
        }

        if (is_bool($value)) {
            $statement->bindValue($position, $value, PDO::PARAM_BOOL);

            return;
        }

        if (is_int($value)) {
            $statement->bindValue($position, $value, PDO::PARAM_INT);

            return;
        }

        if (is_float($value)) {
            $statement->bindValue($position, (string) $value, PDO::PARAM_STR);

            return;
        }

        if (is_string($value)) {
            $statement->bindValue($position, $value, PDO::PARAM_STR);

            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot bind a %s in %s.%s.',
            get_debug_type($value),
            $database,
            $column,
        ));
    }

    private function isBooleanType(string $type): bool
    {
        return str_contains(strtolower($type), 'bool');
    }

    private function isTimestampType(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'timestamp') || str_contains($type, 'datetime');
    }

    private function isZonedTimestampType(string $type): bool
    {
        $type = strtolower($type);

        return str_contains($type, 'with time zone') || str_contains($type, 'timestamptz');
    }

    /**
     * The snapshot's timestamp spelling: UTC, `Y-m-d H:i:s`, no offset.
     *
     * SQLite has no timezone type and stores the app's UTC text as-is. A
     * PostgreSQL TIMESTAMPTZ is read back with the session offset, so it is
     * converted to UTC here. A value without a parseable time is left alone
     * rather than guessed at.
     */
    private function utcTimestamp(string $value): string
    {
        $matches = $this->timestampParts($value);

        if (null === $matches || null === $matches['offset']) {
            return $value;
        }

        $date = new DateTimeImmutable($value);
        $timestamp = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if ('' !== $matches['fraction']) {
            $timestamp .= $matches['fraction'];
        }

        return $timestamp;
    }

    /**
     * PostgreSQL reads a bare timestamp in the session timezone. The snapshot
     * is UTC, so a bare value is given that offset explicitly.
     */
    private function withUtcOffset(string $value): string
    {
        $matches = $this->timestampParts($value);

        if (null === $matches || null !== $matches['offset']) {
            return $value;
        }

        return $value . '+00:00';
    }

    /**
     * @return array{date: string, time: string, fraction: string, offset: string|null}|null
     */
    private function timestampParts(string $value): ?array
    {
        $pattern = '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}(?::?\d{2})?)?$/i';

        if (1 !== preg_match($pattern, $value, $matches)) {
            return null;
        }

        return [
            'date'     => $matches[1],
            'time'     => $matches[2],
            'fraction' => $matches[3] ?? '',
            'offset'   => '' === ($matches[4] ?? '') ? null : $matches[4],
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return 0 !== (int) $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            return match ($normalised) {
                '1', 't', 'true', 'yes', 'on' => true,
                '0', 'f', 'false', 'no', 'off', '' => false,
                default => throw new RuntimeException(sprintf('Cannot read "%s" as a boolean.', $value)),
            };
        }

        throw new RuntimeException(sprintf('Cannot read a %s as a boolean.', get_debug_type($value)));
    }
}
