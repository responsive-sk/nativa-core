<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * DsnBuilder — the one place a target becomes a PDO DSN.
 *
 * SQLite reads the target as a file path; PostgreSQL and MariaDB read it as
 * the DSN body (`host=…;port=…;dbname=…`) the driver expects after the scheme.
 * MariaDB speaks the `mysql:` PDO scheme, and its module database is selected
 * by replacing the body's `dbname`.
 *
 * Nothing here decides read-only routing or the module schema; those are
 * session settings in `SessionSettings` and `DatabaseConnectionManager`.
 */
final class DsnBuilder
{
    public static function build(Driver $driver, string $target): string
    {
        return match ($driver) {
            Driver::Sqlite => 'sqlite:' . $target,
            Driver::Pgsql => 'pgsql:' . $target,
            Driver::MariaDb => 'mysql:' . $target,
        };
    }

    /**
     * The same DSN body with one database selected.
     *
     * A MariaDB server DSN may already carry a `dbname` for a control
     * connection; the module database replaces it rather than being appended
     * beside it, because PDO would otherwise see two `dbname` keys.
     */
    public static function withDatabase(string $target, string $database): string
    {
        $body = self::withCharset(self::withoutDatabase($target));

        return ('' === $body ? '' : $body . ';') . 'dbname=' . $database;
    }

    /**
     * The same DSN body with no database selected.
     */
    public static function withoutDatabase(string $target): string
    {
        $parts = [];

        foreach (explode(';', $target) as $part) {
            if ('' === trim($part) || 1 === preg_match('/^\s*dbname\s*=/i', $part)) {
                continue;
            }

            $parts[] = $part;
        }

        return implode(';', $parts);
    }

    /**
     * Add the connection charset once, leaving an operator's choice alone.
     */
    public static function withCharset(string $target, string $charset = 'utf8mb4'): string
    {
        if (1 === preg_match('/(^|;)\s*charset\s*=/i', $target)) {
            return $target;
        }

        return $target . ('' === $target ? '' : ';') . 'charset=' . $charset;
    }
}
