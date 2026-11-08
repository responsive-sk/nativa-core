<?php

declare(strict_types = 1);

namespace Modules\Core\Domain;

use Modules\Core\Infrastructure\Env;

/**
 * AdminRoutePolicy — which permission each `/mark` path needs.
 *
 * ## Why this lives in the kernel and not in `Modules\Users`
 *
 * Measured 2026-10-17, while auditing who owned a name. This was
 * `Modules\Core\Domain\AdminRoutePolicy`, and it turned out no single module
 * could own it:
 *
 *   - `Cardboard` kept a **local copy** of this table to avoid importing Users,
 *     documented in `AdminNavService.php:219–223` as *"no Users import —
 *     Cardboard stays a leaf for the install-closure scanner"*;
 *   - the kernel's `ThemeResolver::resolvePath()` **duplicated**
 *     `isAdminPath()` line for line, because Core may not import a feature
 *     module;
 *   - `Outbox` and `Questions` both reached across for it.
 *
 * The kernel already knows the admin area exists — `ThemeResolver::ADMIN_THEME`,
 * `AuthMiddleware`'s redirect and `PageCacheService`'s bypass list all carry
 * `/mark` — so it had the fact three times over without owning it once. A route
 * table is a property of the ROUTES, not of the module that happens to hold the
 * accounts, so it belongs beside them in Core.
 *
 * `permits()` used to take a `User` entity, which is the one thing that could
 * not cross this line. It now duck-types the session object the same way
 * `Cardboard`'s gate already did — `BulkEntityGate::hasPermission()` uses
 * `method_exists($user, 'hasPermission')` for exactly this reason.
 *
 * `/mark` was one gate on one hardcoded role name, which meant the whole
 * permission graph — five permissions, a roles UI, per-role assignment — was
 * decorative: an administrator could grant an author role every right in the
 * system and the author still got a 403, because `admin` was a literal.
 *
 * This is the table that makes those grants mean something. It is a TABLE and
 * not a chain of `if` statements on purpose: a new admin route that nobody
 * classified must fail CLOSED. An unmapped `/mark/whatever` resolves to
 * `null`, and `null` is not a permission anyone holds — so forgetting to add a
 * row costs access, never leaks it.
 *
 * Longest prefix wins, so `/mark/users/create` resolves through `users.manage`
 * rather than falling back to the `/mark` baseline.
 *
 * `User::hasPermission()` still short-circuits on the `admin` role, so an
 * administrator passes every row below — but by holding all five, not by the
 * gate skipping the check.
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
     * INTERNAL one: routes, the table below and stored links are written in
     * it. Keeping the default as a constant (rather than retyping the literal
     * in each method here and at each call site) is what lets the ownership
     * gate count the `/mark` literals the tree still carries.
     */
    private const string DEFAULT_PREFIX = '/mark';

    /**
     * Path prefix => permission. NOT sorted: `permits()` picks the longest
     * matching prefix, so order here carries no meaning.
     *
     * @var array<string, string>
     */
    private const array TABLE = [
        '/mark'                  => Permissions::MARK_ACCESS,

        // The inbox is the reader's own: opening it is neither a content act
        // nor an access-management one, so it stays under the panel's own gate
        // and every user who may enter `/mark` may read their own rows. Who
        // those rows belong to is enforced by the query, not by this table.
        '/mark/notifications'    => Permissions::MARK_ACCESS,

        // Content. Articles, pages, comments, media and forms are all "the
        // things a writer works with" — one permission keeps the author role
        // readable, and the audit log still says exactly which action ran.
        '/mark/articles'         => Permissions::CONTENT,
        '/mark/pages'            => Permissions::CONTENT,
        '/mark/categories'       => Permissions::CONTENT,
        '/mark/tags'             => Permissions::CONTENT,
        '/mark/comments'         => Permissions::CONTENT,
        '/mark/questions'        => Permissions::CONTENT,
        '/mark/media'            => Permissions::CONTENT,
        '/mark/forms'            => Permissions::CONTENT,
        '/mark/outbox'           => Permissions::ROLES,

        // Bulk actions: the COARSE check only. The POST body names the entity,
        // and that choice is attacker-controlled, so `permissionForEntity()`
        // applies the fine-grained one before any id is touched. This row is
        // "may reach a bulk endpoint at all"; it is not the entity's own gate.
        '/mark/bulk-publish'     => Permissions::CONTENT,
        '/mark/bulk-delete'      => Permissions::CONTENT,

        // Admin-only: these can reshape access itself, so they are never part
        // of a content role.
        '/mark/locales'          => Permissions::SETTINGS,
        '/mark/settings'         => Permissions::SETTINGS,
        '/mark/users'            => Permissions::USERS,
        '/mark/roles'            => Permissions::ROLES,
        '/mark/permissions'      => Permissions::ROLES,
        '/mark/audit'            => Permissions::ROLES,
    ];

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
     * `/mark`, which is what the route attributes, the table and every link in
     * this tree spell. `/admin/users` becomes `/mark/users`; an internal path
     * passes through unchanged, so both spellings keep working and a hardcoded
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
     * The permission this path requires, or null when it is an admin path no
     * table row classifies. Callers must treat null as DENY.
     *
     * The path is canonicalized first, so a deployment running `/admin` gets
     * the same answer as `/mark` without a second table.
     *
     * @return string|null
     */
    public static function requiredPermission(string $path): ?string
    {
        $path = self::canonicalPath($path);

        if (!self::matches($path, self::DEFAULT_PREFIX)) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach (self::TABLE as $prefix => $permission) {
            if (!self::matches($path, $prefix)) {
                continue;
            }

            $length = strlen($prefix);

            if ($length > $bestLength) {
                $best = $permission;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * May this user open this path?
     *
     * An unmapped admin path is refused, so adding a route without
     * classifying it is a 403 rather than an accidental hole.
     *
     * The user is duck-typed, not a `User`: while this lived in
     * `Modules\Users\Domain` it took the entity, and that parameter was the one
     * thing keeping the table out of the kernel. The session object already
     * answers `hasPermission()`, and `Cardboard`'s gate has typed it as `object`
     * for the same reason.
     */
    public static function permits(string $path, object $user): bool
    {
        $permission = self::requiredPermission($path);

        if (null === $permission) {
            return false;
        }

        return method_exists($user, 'hasPermission') && true === $user->hasPermission($permission);
    }

    /**
     * The classified prefixes, for the coverage guard and for documentation.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        return array_keys(self::TABLE);
    }

    /**
     * The permission a BULK operation needs, keyed by the entity it names.
     *
     * `/mark/bulk-publish` and `/mark/bulk-delete` are one route each, but the
     * POST body chooses what they operate on — and that choice is
     * attacker-controlled. Classifying the path alone was the live hole: an
     * author passed the `content.manage` row for the bulk route and then named
     * `users`, reaching user deletion through a content permission. This table
     * is checked AFTER the path and BEFORE any id is touched.
     *
     * An unknown entity returns null, so a new entity type cannot be added to
     * a bulk form without also being classified here.
     */
    public static function permissionForEntity(string $entity): ?string
    {
        return match ($entity) {
            'articles', 'pages'     => Permissions::CONTENT,
            'users'                 => Permissions::USERS,
            'roles'                 => Permissions::ROLES,
            default                 => null,
        };
    }

    /**
     * Every entity a bulk operation accepts — the coverage guard's counterpart
     * to `permissionForEntity()`.
     *
     * @return list<string>
     */
    public static function bulkEntities(): array
    {
        return ['articles', 'pages', 'users', 'roles'];
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
