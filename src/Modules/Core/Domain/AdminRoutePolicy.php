<?php

declare(strict_types = 1);

namespace Modules\Core\Domain;

use Modules\Core\Infrastructure\Env;

/**
 * AdminRoutePolicy — the admin area's URL prefix, and nothing else.
 *
 * The kernel knows exactly one thing about the panel: that `/mark` exists and
 * how it is spelled on this deployment. That fact is needed BEFORE routing
 * (`AuthMiddleware` and `AdminUnavailableMiddleware` see the URL the visitor
 * typed), by the router's canonicalization and by the 301 back to the public
 * spelling, so it has one owner here.
 *
 * ## The permission table is not here
 *
 * Measured 2026-11-12 in this kernel profile: the class also carried the
 * `/mark` path => permission table and its five readers — `permits()`,
 * `requiredPermission()`, `permissionForEntity()`, `bulkEntities()` and
 * `prefixes()` — and NOTHING under `src/`, `modules/`, `bin/` or `public/`
 * called any of them. Who may open which panel path is the PANEL's policy: it
 * classifies the routes the panel module declares, and it is the module that
 * can answer "this path has no row, so deny". The kernel stating that table
 * meant the kernel owned a rule it never applied, and a route table that the
 * routes' owner could not correct without editing the kernel.
 *
 * The table therefore belongs to the panel module (`modules/Cardboard` in the
 * source tree; it is not in this export profile). This class keeps the prefix
 * arithmetic the kernel does use, and states no permission and grants nothing.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminRoutePolicy
{
    /**
     * The default admin prefix — the spelling every route attribute declares.
     *
     * `ADMIN_PATH` overrides the PUBLIC spelling, but `/mark` remains the
     * INTERNAL one: routes and stored links are written in it. Keeping the
     * default as a constant (rather than retyping the literal in each method
     * here and at each call site) is what lets the ownership gate count the
     * `/mark` literals the tree still carries.
     */
    private const string DEFAULT_PREFIX = '/mark';

    /**
     * The admin surface's URL prefix — one owner, optional value.
     *
     * `ADMIN_PATH` is read from the environment and defaults to `/mark`, the
     * spelling every route attribute in this tree declares. The value is
     * validated rather than trusted: an absolute path of plain segments, no
     * trailing slash, no scheme, host, query or fragment. A typo falls back to
     * the default — an admin area that disappears because of a mistyped env
     * var is worse than a prefix nobody changed.
     *
     * This is the owner `SettingsShellContextProvider`, `AuthMiddleware`,
     * `PageCacheService` and the HTTP tests read instead of each keeping an
     * `ADMIN_PATH` constant (task 0.3: two constants, one name, two values).
     */
    public static function prefix(): string
    {
        $configured = Env::get('ADMIN_PATH', self::DEFAULT_PREFIX);
        $prefix = is_string($configured) ? trim($configured) : '';

        if (1 !== preg_match('~^/[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)*$~', $prefix)) {
            return self::DEFAULT_PREFIX;
        }

        return $prefix;
    }

    /**
     * The INTERNAL spelling of a path: the configured prefix is an alias for
     * `/mark`, which is what the route attributes and every link in this tree
     * spell. `/admin/users` becomes `/mark/users`; an internal path passes
     * through unchanged, so both spellings keep working and a hardcoded
     * `/mark/...` link does not 404 on a deployment that chose another prefix.
     */
    public static function canonicalPath(string $path): string
    {
        $prefix = self::prefix();

        if ($prefix === $path) {
            return self::DEFAULT_PREFIX;
        }

        if (str_starts_with($path, $prefix . '/')) {
            return self::DEFAULT_PREFIX . substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * The PUBLIC spelling of a path: the inverse of `canonicalPath()`.
     *
     * Routing happens on internal `/mark` paths, so a 301 the router builds
     * (trailing-slash canonicalization) would otherwise send a visitor from
     * `/admin/users/` to `/mark/users` — a working but leaking detour. The
     * query string is preserved; a path outside the admin area is returned
     * untouched.
     */
    public static function publicPath(string $path): string
    {
        $prefix = self::prefix();

        if (self::DEFAULT_PREFIX === $prefix) {
            return $path;
        }

        $query = '';
        $mark = strpos($path, '?');

        if (false !== $mark) {
            $query = substr($path, $mark);
            $path = substr($path, 0, $mark);
        }

        if (self::DEFAULT_PREFIX === $path) {
            return $prefix . $query;
        }

        if (str_starts_with($path, self::DEFAULT_PREFIX . '/')) {
            return $prefix . substr($path, \strlen(self::DEFAULT_PREFIX)) . $query;
        }

        return $path . $query;
    }

    /**
     * Is this path inside the admin area at all?
     *
     * Both spellings count: the configured prefix (`/admin` on a deployment
     * that set `ADMIN_PATH`) and the internal `/mark` the routes declare. The
     * middleware pipeline runs BEFORE the kernel canonicalizes, so
     * `AuthMiddleware` asks this question about the URL the visitor typed.
     */
    public static function isAdminPath(string $path): bool
    {
        return self::matches($path, self::DEFAULT_PREFIX) || self::matches($path, self::prefix());
    }

    /**
     * A prefix matches a path whole-segment only, so `/mark/users` cannot
     * authorize `/mark/usersomething` — the trailing part of the prefix has to
     * be either nothing or a `/`.
     */
    private static function matches(string $path, string $prefix): bool
    {
        if ($path === $prefix) {
            return true;
        }

        return str_starts_with($path, str_ends_with($prefix, '/') ? $prefix : $prefix . '/');
    }
}
