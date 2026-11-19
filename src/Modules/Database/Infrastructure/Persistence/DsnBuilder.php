<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * DsnBuilder — the one place a target becomes a PDO DSN.
 *
 * SQLite reads the target as a file path; PostgreSQL reads it as the DSN body
 * (`host=…;port=…;dbname=…`) shared by every module, because a DSN body is what
 * PDO expects after the scheme. Nothing here decides read-only routing or the
 * module schema; those are session settings in `SessionSettings` and
 * `DatabaseConnectionManager`.
 */
final class DsnBuilder
{
    public static function build(Driver $driver, string $target): string
    {
        return match ($driver) {
            Driver::Sqlite => 'sqlite:' . $target,
            Driver::Pgsql => 'pgsql:' . $target,
        };
    }
}
