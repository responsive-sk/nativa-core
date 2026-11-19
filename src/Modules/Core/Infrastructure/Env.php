<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure;

/**
 * Zero-dependency immutable environment loader.
 *
 * Parses a .env file (if present) and populates $_ENV / putenv() once.
 * Existing values in $_ENV / $_SERVER always win (immutable semantics).
 *
 * A second layer may be applied from a JSON file (`apply()`); it obeys the
 * same rule, so the order of application IS the precedence: process
 * environment, then .env, then the file. The winning source is remembered per
 * key so a screen can say which layer set a value instead of guessing.
 */
final class Env
{
    /** @var array<string, string> Cached parsed values. */
    private static array $cache = [];

    /** @var array<string, string> Source label of each applied key. */
    private static array $origins = [];

    private static bool $loaded = false;

    /**
     * Load the environment file. Values already present in $_ENV or $_SERVER
     * take precedence and are never overwritten (immutable).
     *
     * @param string $path absolute path to the .env file ('' = default)
     */
    public static function loadImmutable(string $path = ''): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $file = '' !== $path ? $path : APP_BASE_PATH . '/.env';
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and lines without '='
            if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ('' === $key || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
                continue;
            }

            // Strip surrounding quotes
            $value = self::stripQuotes($value);

            self::apply('.env', [$key => $value]);
        }
    }

    /**
     * Apply a batch of values immutably, remembering which source set them.
     *
     * A key already present in $_ENV, $_SERVER or the applied cache is left
     * alone — that is what makes a later layer (the JSON file) a fallback for
     * a deployment that exports the same variable.
     *
     * The whole batch must be validated by the caller first: a half-applied
     * batch is a process running with a combination nobody configured.
     *
     * @param string                $source label such as '.env'
     * @param array<string, string> $values
     */
    public static function apply(string $source, array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, $_ENV)
                || array_key_exists($key, $_SERVER)
                || array_key_exists($key, self::$cache)
            ) {
                continue;
            }

            $_ENV[$key] = $value;
            self::$cache[$key] = $value;
            self::$origins[$key] = $source;
            putenv($key . '=' . $value);
        }
    }

    /**
     * Which layer supplied a key, or null when nothing did.
     *
     * A key already in the process environment before any loader ran reads as
     * 'process' — that is the layer that wins, so it is the layer to report.
     */
    public static function origin(string $key): ?string
    {
        if (array_key_exists($key, self::$origins)) {
            return self::$origins[$key];
        }

        $env = getenv($key);
        if (false !== $env && '' !== trim($env)) {
            return 'process';
        }

        return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) ? 'process' : null;
    }

    /**
     * Reset all cached state — for test isolation only.
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$origins = [];
        self::$loaded = false;
    }

    /**
     * Read an environment value with an optional default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $envValue = $_ENV[$key] ?? null;
        if (is_string($envValue) && '' !== $envValue) {
            return $envValue;
        }

        $server = $_SERVER[$key] ?? null;
        if (is_string($server) && '' !== $server) {
            return $server;
        }

        $env = getenv($key);
        if (false !== $env) {
            return $env;
        }

        return $default;
    }

    /**
     * Read a boolean environment value ('true'/'1'/'yes' => true).
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if (null === $value) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read an integer environment value.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return null === $value || !is_numeric($value) ? $default : (int) $value;
    }

    /**
     * Whether the current request arrived over HTTPS.
     *
     * Uses direct server signals only (HTTPS / REQUEST_SCHEME / SERVER_PORT).
     * Forwarded headers (X-Forwarded-Proto) are deliberately ignored — they
     * are spoofable, the same discipline as TRUSTED_PROXIES for client IPs
     * (audit finding F-1). Behind a TLS-terminating proxy that hides HTTPS
     * from PHP, set SESSION_SECURE=true explicitly (audit finding F-5).
     */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? null;
        if (is_string($https) && ('on' === strtolower($https) || '1' === $https)) {
            return true;
        }

        $scheme = $_SERVER['REQUEST_SCHEME'] ?? null;
        if ('https' === $scheme) {
            return true;
        }

        return '443' === ($_SERVER['SERVER_PORT'] ?? null);
    }

    private static function stripQuotes(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
