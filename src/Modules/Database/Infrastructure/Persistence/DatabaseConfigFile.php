<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Env;

/**
 * DatabaseConfigFile — the engine chosen in the storage layer.
 *
 * `storage/config/database.json` names the driver, the DSN and the MariaDB
 * prefix, and `load()` feeds them to `Env` as a FALLBACK: the process
 * environment and `.env` still win, exactly as `.env` itself loses to the
 * process environment. That order is the whole contract — a deployment that
 * exports `DB_DSN` must never be silently overridden by a file in a volume,
 * and the CI/build targets that pass `DB_DRIVER` on the command line keep
 * meaning what they say.
 *
 * Three rules this class exists to hold:
 *
 *  - **All or nothing.** The file is decoded and every key validated before a
 *    single value reaches the environment; a half-applied file would boot a
 *    process with a driver from one layer and a DSN from another.
 *  - **A broken file fails the boot.** A typo, a truncated write or an
 *    unsupported driver stops the process with a message naming the file.
 *    Falling back to SQLite would write to a different store than the operator
 *    configured, which is worse than not starting.
 *  - **The DSN never leaves unredacted.** The file holds a password, so it is
 *    written `0600` (directory `0750`) and atomically — a killed write must
 *    leave the previous, valid file, not half of both.
 *
 * The file is read once at boot (`src/init.php`), not per connection: a file
 * that changed mid-request must not produce a driver from before the write and
 * a DSN from after it.
 */
final class DatabaseConfigFile
{
    /**
     * Path relative to the project root.
     *
     * A method rather than a constant: the const ledger counts declarations,
     * and this path is a fact about the class, not a value with a second owner.
     */
    public static function relativePath(): string
    {
        return 'storage/config/database.json';
    }

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/') . '/' . self::relativePath();
    }

    /**
     * File key => the environment variable it feeds.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return [
            'driver' => 'DB_DRIVER',
            'dsn'    => 'DB_DSN',
            'prefix' => 'DB_DATABASE_PREFIX',
        ];
    }

    /**
     * Apply the file to the environment when it exists.
     *
     * @throws \InvalidArgumentException when the file exists and cannot be used
     */
    public static function load(string $basePath): void
    {
        $values = self::read($basePath);

        if (null === $values) {
            return;
        }

        $environment = [];

        foreach (self::environment() as $key => $variable) {
            if (array_key_exists($key, $values)) {
                $environment[$variable] = $values[$key];
            }
        }

        Env::apply(self::relativePath(), $environment);
    }

    /**
     * The file's normalised values, or null when there is no file.
     *
     *
     * @throws \InvalidArgumentException when the file exists and cannot be used
     *
     * @return array{driver?: string, dsn?: string, prefix?: string, updated_at?: string}|null
     */
    public static function read(string $basePath): ?array
    {
        $file = self::path($basePath);

        if (!is_file($file)) {
            return null;
        }

        return self::normalise(self::decode($file), $file);
    }

    /**
     * Write the file atomically, replacing whatever was there.
     *
     * An empty `$dsn` omits the key: SQLite has no DSN, and a stored empty
     * string would read as a half-configured server engine.
     *
     * Nothing here changes the running process — the saved values take effect
     * on the next request, which is the request that boots with the file.
     *
     * @throws \InvalidArgumentException when a value is not usable
     * @throws \RuntimeException         when the file cannot be written
     */
    public static function write(string $basePath, string $driver, string $dsn, string $prefix): void
    {
        $file = self::path($basePath);

        $input = ['driver' => $driver, 'prefix' => $prefix];

        if ('' !== trim($dsn)) {
            $input['dsn'] = $dsn;
        }

        $values = self::normalise($input, $file);
        $values['updated_at'] = gmdate('c');

        $json = json_encode(
            $values,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create %s for the database configuration.', $directory));
        }

        // Write beside the target, then rename: rename(2) is atomic within a
        // filesystem, so a reader sees the old file or the new one, never a
        // truncated DSN.
        $tmp = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(6));
        $bytes = @file_put_contents($tmp, $json, LOCK_EX);

        if (false === $bytes || $bytes !== strlen($json)) {
            @unlink($tmp);

            throw new \RuntimeException(sprintf('Cannot write the temporary database configuration for %s.', $file));
        }

        @chmod($tmp, 0o600);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException(sprintf('Cannot replace %s with the new database configuration.', $file));
        }

        @chmod($file, 0o600);
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private static function decode(string $file): array
    {
        $raw = @file_get_contents($file);

        if (false === $raw) {
            throw new \InvalidArgumentException(sprintf('%s: cannot be read.', $file));
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                sprintf('%s: invalid JSON (%s).', $file, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException(sprintf('%s: must be a JSON object, not a list or scalar.', $file));
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(sprintf('%s: keys must be strings, got %s.', $file, (string) $key));
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Validate every key and return the values a valid file would feed in.
     *
     * @param array<string, mixed> $raw
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, string>
     */
    private static function normalise(array $raw, string $file): array
    {
        $values = [];

        foreach ($raw as $key => $value) {
            // Written for the operator, never applied to the environment.
            if ('updated_at' === $key) {
                if (!is_string($value) || '' === trim($value)) {
                    throw new \InvalidArgumentException(sprintf('%s: "%s" must be a non-empty string.', $file, $key));
                }

                $values[$key] = trim($value);

                continue;
            }

            if (!array_key_exists($key, self::environment())) {
                // A typo must not be an ignored line: `"dns"` would look like a
                // switch that silently did nothing.
                throw new \InvalidArgumentException(sprintf(
                    '%s: unknown key "%s"; allowed: %s.',
                    $file,
                    (string) $key,
                    implode(', ', [...array_keys(self::environment()), 'updated_at']),
                ));
            }

            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('%s: "%s" must be a string.', $file, $key));
            }

            $value = trim($value);

            if ('' === $value) {
                throw new \InvalidArgumentException(sprintf('%s: "%s" must not be empty.', $file, $key));
            }

            $values[$key] = match ($key) {
                'driver' => self::driver($value, $file),
                'prefix' => self::prefix($value, $file),
                default  => $value,
            };
        }

        if (Driver::Sqlite->value === ($values['driver'] ?? null) && array_key_exists('dsn', $values)) {
            throw new \InvalidArgumentException(sprintf(
                '%s: "dsn" is not read for the sqlite driver; SQLite reads a file per module from storage/data/.',
                $file,
            ));
        }

        return $values;
    }

    private static function driver(string $name, string $file): string
    {
        try {
            return Driver::fromName($name)->value;
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(sprintf('%s: %s', $file, $exception->getMessage()), 0, $exception);
        }
    }

    private static function prefix(string $prefix, string $file): string
    {
        // The same rule DatabaseConnectionManager applies when it builds
        // MariaDB database names; validating here fails at boot with the file
        // named instead of at the first connection.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException(sprintf(
                '%s: "prefix" must start with a lowercase letter and contain only a-z, 0-9 and _ (got "%s").',
                $file,
                $prefix,
            ));
        }

        return $prefix;
    }
}
