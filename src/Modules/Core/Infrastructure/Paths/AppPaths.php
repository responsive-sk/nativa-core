<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Paths;

/**
 * AppPaths — filesystem path resolution.
 *
 * Single source of truth for project paths. Paths resolve relative to
 * APP_BASE_PATH (defined in src/init.php). Database paths honour a per-DB
 * environment override (DB_<NAME>) for deployments that keep SQLite files
 * outside the project.
 */
final class AppPaths
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    /**
     * Get the shared instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (test isolation).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Project root (APP_BASE_PATH from bootstrap).
     */
    public function base(): string
    {
        return APP_BASE_PATH;
    }

    /**
     * Join path segments safely relative to the project root.
     *
     * @param string ...$segments Path segments relative to the project root
     */
    public function join(string ...$segments): string
    {
        $clean = array_values(array_filter($segments, static fn (string $s): bool => '' !== $s));

        return $this->base() . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $clean);
    }

    /**
     * Ensure a directory exists, creating it (and parents) when missing.
     *
     * @throws \RuntimeException when the directory cannot be created
     */
    public function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Cannot create directory: %s', $path));
        }
    }

    /**
     * Path under storage/.
     */
    public function storage(string $relative = ''): string
    {
        return $this->join('storage', $relative);
    }

    /**
     * Path under storage/data/ (SQLite database files).
     */
    public function data(string $relative = ''): string
    {
        return $this->join('storage', 'data', $relative);
    }

    /**
     * Absolute path to a per-module SQLite database file.
     *
     * Honour the DB_<NAME> environment override (absolute or project-relative)
     * before falling back to storage/data/<name>.db.
     */
    public function database(string $name): string
    {
        $envKey = 'DB_' . strtoupper($name);
        $override = getenv($envKey);

        if (false !== $override && '' !== $override) {
            if (str_starts_with($override, '/')) {
                return $override;
            }

            return $this->join($override);
        }

        return $this->data($name . '.db');
    }

    /**
     * Path under storage/logs/ (optionally a concrete log file).
     */
    public function logs(string $file = ''): string
    {
        return $this->join('storage', 'logs', $file);
    }

    /**
     * Path under storage/cache/.
     */
    public function cache(string $relative = ''): string
    {
        return $this->join('storage', 'cache', $relative);
    }

    /**
     * Path under storage/sessions/.
     */
    public function sessions(string $relative = ''): string
    {
        return $this->join('storage', 'sessions', $relative);
    }

    /**
     * Path under src/.
     */
    public function src(string $relative = ''): string
    {
        return $this->join('src', $relative);
    }

    /**
     * Resolve a module-declared asset path — `Modules/<Name>/<tail>` — against
     * the installed tree.
     *
     * A module may be filed under a group (`src/Modules/<Group>/<Name>/`), and
     * the declaration deliberately does not say so: `criticalCss()` and the
     * inline scripts name the module, not the layout. The layout answers where
     * the bytes are. A flat module, a kernel module and a missing file all
     * resolve to `src(<path>)`, so the declared path is still what a failure
     * reports.
     */
    public function moduleFile(string $srcRelative): string
    {
        $direct = $this->src($srcRelative);

        if (is_file($direct) || !str_starts_with($srcRelative, 'Modules/')) {
            return $direct;
        }

        $parts = explode('/', substr($srcRelative, strlen('Modules/')), 2);
        $module = $parts[0];
        $tail = $parts[1] ?? '';

        if ('' === $tail || '' === $module || str_contains($module, '..')) {
            return $direct;
        }

        // One level of group: `src/Modules/<Group>/<Name>/<tail>`. The glob is
        // the generic form of "wherever the module is filed" — no group list
        // to keep in sync with the catalogue.
        foreach (glob($this->src('Modules/*/' . $module . '/' . $tail)) ?: [] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $direct;
    }

    /**
     * Path under public/.
     */
    public function public(string $relative = ''): string
    {
        return $this->join('public', $relative);
    }

    /**
     * Path under config/.
     */
    public function config(string $relative = ''): string
    {
        return $this->join('config', $relative);
    }
}
