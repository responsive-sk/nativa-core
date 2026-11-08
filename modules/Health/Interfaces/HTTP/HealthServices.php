<?php

declare(strict_types = 1);

namespace Modules\Health\Interfaces\HTTP;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Health\Checks\DatabaseHealthCheck;
use Modules\Health\Checks\DistManifestHealthCheck;
use Modules\Health\Checks\FilesystemHealthCheck;
use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Registry\HealthCheckRegistry;

/**
 * HealthServices — the registry the endpoint runs, built once per process.
 *
 * Health is a LEAF module: it names nothing but the kernel (`AppPaths`) and its
 * own checks. It does not import `Modules\Themes` to ask which bundles exist,
 * it scans `public/dist`; it does not ask each module for its database, it
 * resolves the files. That is what lets the module be installed on a tree where
 * nothing else is — and what keeps its closure small enough for `module:show`.
 *
 * ## Which databases get probed
 *
 * Not `ModuleCatalog` and not the module names: the module→file mapping is a
 * convention with real exceptions (`Settings` owns `config.db`, `Audit` owns
 * `audit.db`), so deriving a filename from a module name would probe a file
 * that does not exist and report a healthy site as down. The names come from
 * the files that are there — `storage/data/*.db` — plus every `DB_<NAME>`
 * override whose resolved file exists, because the documented deployment shape
 * keeps SQLite outside the project (`AppPaths::database()` honours the
 * override, so the check then opens the relocated file).
 */
final class HealthServices
{
    private static ?HealthCheckRegistry $registry = null;

    /**
     * The registry /health runs, built on first use and kept for the process
     * (one request under FPM, the whole run under the built-in server).
     */
    public static function registry(): HealthCheckRegistry
    {
        if (null !== self::$registry) {
            return self::$registry;
        }

        $registry = new HealthCheckRegistry();

        foreach (self::checks() as $check) {
            $registry->register($check);
        }

        return self::$registry = $registry;
    }

    /**
     * The probes this tree registers, in payload order: data first, then the
     * directory a request writes to, then the assets it serves. A reader
     * scanning a /health response reads the same order a request depends on.
     *
     * ONE storage directory, not two. `storage/logs` was probed at first and
     * was wrong for the reason `FilesystemHealthCheck` excludes
     * `storage/uploads`: on most hosts PHP's `error_log` is a php-fpm channel
     * or a root-owned 775 file, so an unwritable `storage/logs` is harmless
     * while every page still serves — reporting that as an outage would page
     * someone for a log directory. `storage/cache` is different: it is where
     * the page cache writes, and a request that cannot write it pays the full
     * render on every visit.
     *
     * @return list<HealthCheckInterface>
     */
    public static function checks(?AppPaths $paths = null): array
    {
        $paths ??= AppPaths::instance();

        return [
            new DatabaseHealthCheck(self::databaseNames($paths)),
            new FilesystemHealthCheck([$paths->cache()]),
            new DistManifestHealthCheck($paths->public('dist')),
        ];
    }

    /**
     * Module database names whose file exists, sorted.
     *
     * The `DB_<NAME>` half takes only overrides whose value ends in `.db`. The
     * override exists for deployments that keep SQLite outside the project, and
     * without the suffix test an unrelated `DB_HOST=db.internal` from some other
     * tool's environment would become "module database `host`" — a probe against
     * a file that is not a database, and a false 503 on a site that serves fine.
     *
     * @return list<string>
     */
    public static function databaseNames(?AppPaths $paths = null): array
    {
        $paths ??= AppPaths::instance();

        /** @var array<string, true> $names */
        $names = [];

        $files = glob($paths->data('*.db'));

        if (false !== $files) {
            foreach ($files as $file) {
                $names[basename($file, '.db')] = true;
            }
        }

        $environment = getenv();

        if (is_array($environment)) {
            foreach ($environment as $key => $value) {
                if (!str_starts_with($key, 'DB_') || 'DB_' === $key) {
                    continue;
                }

                if (!str_ends_with($value, '.db')) {
                    continue;
                }

                $names[strtolower(substr($key, 3))] = true;
            }
        }

        $existing = array_values(array_filter(
            array_keys($names),
            static fn (string $name): bool => is_file($paths->database($name)),
        ));

        sort($existing);

        return $existing;
    }

    /**
     * Reset the built registry (test isolation).
     */
    public static function reset(): void
    {
        self::$registry = null;
    }
}
