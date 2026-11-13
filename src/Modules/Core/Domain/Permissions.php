<?php

declare(strict_types = 1);

namespace Modules\Core\Domain;

/**
 * Permissions — the vocabulary of what a user may do in `/mark`.
 *
 * One owner for these strings, because the alternative is the permission name
 * appearing as a literal in an action, a migration and a seed, drifting apart
 * silently: a typo in one place is not a compile error, it is a role that
 * quietly grants nothing.
 *
 * The five names match the rows the `permissions` table is seeded with; the
 * seed reads them from here so the two cannot fall apart.
 *
 * ## Why this lives in the kernel and not in `Modules\Users`
 *
 * Measured 2026-10-17, while auditing who owned a name. This was
 * `Modules\Core\Domain\Permissions`, and three consumers could not reach it:
 * `Cardboard` kept a **local copy** of the admin permission table to stay a leaf
 * for the install-closure scanner, `Outbox` named it in a docblock only, and
 * the kernel's own `ThemeResolver` duplicated `AdminRoutePolicy::isAdminPath()`
 * because Core may not import a feature module.
 *
 * A vocabulary that every layer needs is not one module's business. It is data
 * with no behaviour — five constants and two list builders — and moving it to
 * Core costs nothing except the import that the consumers already had. The
 * seed in `Users\DatabaseMigrator` still reads from here, so the rows and the
 * names remain one thing.
 *
 * @internal
 *
 * @coversNothing
 */
final class Permissions
{
    /** Baseline for the admin area at all — nothing under `/mark` opens without it. */
    public const string MARK_ACCESS = 'mark.access';

    /** Blog articles, pages, categories, tags, comments, media, forms. */
    public const string CONTENT = 'content.manage';

    /** Site settings and translations. */
    public const string SETTINGS = 'settings.manage';

    /** User accounts. */
    public const string USERS = 'users.manage';

    /** Roles and the permission graph itself. */
    public const string ROLES = 'roles.manage';

    /**
     * Admin-only permissions — the ones that can grant or reshape access.
     *
     * A user holding one of these can hand themselves any other permission, so
     * they are never what a content role is built from: `Users`, `Roles` and
     * `Settings` stay behind `roles.manage` policy.
     *
     * @return list<string>
     */
    public static function adminOnly(): array
    {
        return [self::SETTINGS, self::USERS, self::ROLES];
    }

    /**
     * Every permission, in the order the seed writes them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MARK_ACCESS, self::CONTENT, self::SETTINGS, self::USERS, self::ROLES];
    }
}
