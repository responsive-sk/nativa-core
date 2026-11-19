<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * SessionSettings — what a fresh handle is told about its session.
 *
 * For SQLite these are the four pragmas the manager always ran
 * (`busy_timeout`, `foreign_keys`, `journal_mode`, `query_only`), moved here
 * unchanged so the driver seam does not alter a single statement of the path
 * every existing deployment runs. For PostgreSQL the counterpart of
 * `query_only` is a read-only session: a misrouted write fails at the server
 * instead of at a file mode.
 */
final class SessionSettings
{
    /**
     * @param string      $label  what the log line names: the file path for SQLite,
     *                            the module name for a DSN that may carry credentials
     * @param string|null $schema the PostgreSQL module schema, or null for SQLite
     *                            and the legacy per-module PostgreSQL layout
     */
    public static function apply(\PDO $pdo, Driver $driver, bool $readOnly, string $label, ?string $schema = null): void
    {
        if (Driver::Sqlite === $driver) {
            self::applySqlite($pdo, $readOnly, $label);

            return;
        }

        if ($readOnly) {
            $pdo->exec('SET default_transaction_read_only = on');
        }

        if (null !== $schema) {
            $pdo->exec('SET search_path TO ' . SqlDialect::forDriver($driver)->quoteIdentifier($schema));
        }
    }

    private static function applySqlite(\PDO $pdo, bool $readOnly, string $label): void
    {
        // Robustness + integrity pragmas for concurrent web requests.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // WAL improves concurrent read/write behaviour under FPM workers. It is
        // best-effort: a read-only or network filesystem refuses it and the
        // connection still works, so the refusal is logged rather than raised.
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException $refusal) {
            error_log(sprintf(
                '[db] %s: journal_mode WAL unavailable (%s), continuing without it',
                $label,
                $refusal->getMessage(),
            ));
        }

        if ($readOnly) {
            $pdo->exec('PRAGMA query_only = 1');
        }
    }
}
