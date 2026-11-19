<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * ConnectionSummary — what the process is actually talking to, for a console or
 * an admin screen.
 *
 * The engine is chosen by the environment and nowhere else: `DB_DRIVER`,
 * `DB_DSN` and `DB_DATABASE_PREFIX` are read on every connection, so no row in
 * any database can select them (reading that row needs a connection first).
 * An operator asking "which engine is this panel on?" therefore has to be
 * answered from the environment, which is what this value object reads.
 *
 * The DSN is redacted here rather than at the render site: the panel renders
 * this class in a browser, and a password that leaves `redact()` is a password
 * on a screen, in a screenshot and in a cache.
 *
 * Read-only by construction — there are no setters, and nothing in this class
 * opens a connection. A diagnostic that needs the database it is diagnosing is
 * a diagnostic that fails exactly when it is needed.
 */
final readonly class ConnectionSummary
{
    private function __construct(
        public string $driver,
        public bool $driverIsDefault,
        public string $dsn,
        public string $prefix,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $configured = self::env('DB_DRIVER');
        $driver = $configured ?? Driver::Sqlite->value;
        $prefix = self::env('DB_DATABASE_PREFIX');

        return new self(
            $driver,
            null === $configured,
            self::redact(self::env('DB_DSN') ?? ''),
            $prefix ?? 'nativa_',
        );
    }

    /**
     * The prefix only applies where a module owns a whole database; SQLite has
     * one file per module and PostgreSQL one schema, so the prefix is not a
     * fact about those targets and is not reported as one.
     */
    public function usesPrefix(): bool
    {
        return Driver::MariaDb->value === $this->driver;
    }

    /**
     * Mask every password value in a `key=value;key=value` DSN.
     *
     * The key is kept so the operator can see the DSN is complete, and every
     * other key is returned untouched — a redactor that hid the host too would
     * hide the one fact the screen exists to show.
     */
    public static function redact(string $dsn): string
    {
        if ('' === trim($dsn)) {
            return '';
        }

        $parts = [];

        foreach (explode(';', $dsn) as $part) {
            if (1 === preg_match('/^\s*(password|passwd|pass|pwd)\s*=/i', $part)) {
                $key = strstr($part, '=', true);
                $parts[] = (false === $key ? 'password' : $key) . '=***';

                continue;
            }

            $parts[] = $part;
        }

        return implode(';', $parts);
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        if (false === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }
}
