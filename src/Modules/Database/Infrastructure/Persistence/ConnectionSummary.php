<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Env;

/**
 * ConnectionSummary — what the process is actually talking to, for a console or
 * an admin screen.
 *
 * The engine is chosen by configuration and nowhere else: `DB_DRIVER`,
 * `DB_DSN` and `DB_DATABASE_PREFIX` are read on every connection, so no row in
 * any database can select them (reading that row needs a connection first).
 * An operator asking "which engine is this panel on?" therefore has to be
 * answered from what the boot applied, which is what this value object reads.
 *
 * It says WHICH layer answered, too. Configuration arrives in three layers
 * (process environment, `.env`, `storage/config/database.json`) and only the
 * first one wins, so `driverSource` names the winner and `shadowed` lists the
 * keys where a lower layer disagrees. Without that, a file saved while the
 * environment still exports `DB_DRIVER` would look like a switch that silently
 * did nothing.
 *
 * The DSN is redacted here rather than at the render site: the panel renders
 * this class in a browser, and a password that leaves `redact()` is a password
 * on a screen, in a screenshot and in a cache. `shadowed` is redacted for the
 * same reason — it carries the file's DSN.
 *
 * Read-only by construction — there are no setters, and nothing in this class
 * opens a connection. A diagnostic that needs the database it is diagnosing is
 * a diagnostic that fails exactly when it is needed.
 */
final readonly class ConnectionSummary
{
    /**
     * @param list<array{key: string, file: string, effective: string}> $shadowed
     */
    private function __construct(
        public string $driver,
        public bool $driverIsDefault,
        public string $dsn,
        public string $prefix,
        public string $driverSource,
        public string $dsnSource,
        public string $prefixSource,
        public array $shadowed,
    ) {
    }

    /**
     * A snapshot of what the boot applied, plus what the file asked for.
     *
     * `$file` is the values `DatabaseConfigFile::read()` returned, or `[]` when
     * there is no file. It is passed in rather than read here: a summary that
     * did I/O would be a diagnostic that can fail for reasons other than the
     * configuration it reports.
     *
     * @param array{driver?: string, dsn?: string, prefix?: string, updated_at?: string} $file
     */
    public static function fromEnvironment(array $file = []): self
    {
        $configured = self::env('DB_DRIVER');
        $driver = $configured ?? Driver::Sqlite->value;

        return new self(
            $driver,
            null === $configured,
            self::redact(self::env('DB_DSN') ?? ''),
            self::env('DB_DATABASE_PREFIX') ?? 'nativa_',
            self::source('DB_DRIVER'),
            self::source('DB_DSN'),
            self::source('DB_DATABASE_PREFIX'),
            self::shadowed($file),
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
     * A message for the operator when the environment overrides the file, or
     * null when every layer agrees.
     */
    public function overrideNotice(): ?string
    {
        if ([] === $this->shadowed) {
            return null;
        }

        $parts = [];

        foreach ($this->shadowed as $entry) {
            $parts[] = sprintf(
                '%s is %s in the environment but %s in %s',
                $entry['key'],
                $entry['effective'],
                $entry['file'],
                DatabaseConfigFile::relativePath(),
            );
        }

        return implode('; ', $parts) . '. The environment wins; unset it to use the file.';
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

    /**
     * Where a key came from, in the words the panel uses.
     */
    private static function source(string $variable): string
    {
        $origin = Env::origin($variable);

        return match ($origin) {
            null      => 'default',
            'process' => 'environment',
            default   => (string) $origin,
        };
    }

    /**
     * Keys a lower layer set that the winning layer disagrees with.
     *
     * @param array{driver?: string, dsn?: string, prefix?: string, updated_at?: string} $file
     *
     * @return list<array{key: string, file: string, effective: string}>
     */
    private static function shadowed(array $file): array
    {
        $pairs = [
            'driver' => 'DB_DRIVER',
            'dsn'    => 'DB_DSN',
            'prefix' => 'DB_DATABASE_PREFIX',
        ];

        $shadowed = [];

        foreach ($pairs as $key => $variable) {
            $fileValue = $file[$key] ?? null;
            $effective = self::env($variable);

            if (null === $fileValue || null === $effective) {
                continue;
            }

            if (self::comparable($key, $fileValue) === self::comparable($key, $effective)) {
                continue;
            }

            $shadowed[] = [
                'key'       => $variable,
                // The DSN never leaves unredacted, including in a warning.
                'file'      => 'dsn' === $key ? self::redact($fileValue) : $fileValue,
                'effective' => 'dsn' === $key ? self::redact($effective) : $effective,
            ];
        }

        return $shadowed;
    }

    /**
     * Compare two values the way the connection factory reads them.
     */
    private static function comparable(string $key, string $value): string
    {
        if ('driver' !== $key) {
            return $value;
        }

        try {
            return Driver::fromName($value)->value;
        } catch (\InvalidArgumentException) {
            return $value;
        }
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
