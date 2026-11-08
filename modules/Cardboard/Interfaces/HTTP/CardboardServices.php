<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP;

use Modules\Cardboard\Application\Services\AdminNavService;
use Modules\Core\Application\Services\PageCacheService;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Settings\Infrastructure\Persistence\Repositories\MenuRepository;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;

/**
 * CardboardServices — lazy cached service factories for admin actions.
 *
 * The Kernel instantiates action classes without dependency injection, so
 * actions resolve shared services through these factories (same pattern as
 * AuthServices). Connections are cached by DatabaseConnectionManager.
 *
 * NOTE (single-admin-module variant): Cardboard is the only admin module, so
 * it must not declare `use Modules\<Feature>\...` for any feature module
 * that renders through its AdminLayout (Audit, Blog, Comments, Locales,
 * Media, Pages, Questions, Users) — the catalog derives install closures
 * from `use` lines, and such a line would close an install-order cycle.
 * Those references are fully-qualified inline (identical runtime behaviour
 * when the full set is installed, invisible to the closure scanner).
 * Security/Settings/Outbox never import Cardboard, so their `use` lines
 * are safe and stay.
 *
 * The price of that invisibility: the catalog cannot refuse
 * `module:remove Users` while Cardboard is installed, so an amputated admin
 * fails here instead of at the CLI. Every factory below that resolves a
 * cycle-hidden module therefore fails LOUD via need() — naming the missing
 * module and the `module:add` that restores it — instead of a bare
 * `Class not found` fatal.
 */
final class CardboardServices
{
    private static ?\Modules\Users\Infrastructure\Persistence\Repositories\UserRepository $users = null;

    private static ?\Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository $roles = null;

    private static ?SettingsRepository $settings = null;

    private static ?MenuRepository $menu = null;

    private static ?\Modules\Users\Application\Services\AuthService $auth = null;

    private static ?AdminNavService $adminNav = null;

    private static ?PageCacheService $pageCache = null;

    private static ?\Modules\Pages\Application\Services\PageCrudService $pageCrud = null;

    private static ?\Modules\Blog\Application\Services\BlogCrudService $blogCrud = null;

    public static function users(): \Modules\Users\Infrastructure\Persistence\Repositories\UserRepository
    {
        self::need('Modules\\Users\\Infrastructure\\Persistence\\Repositories\\UserRepository', 'Users');

        return self::$users ??= new \Modules\Users\Infrastructure\Persistence\Repositories\UserRepository(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }

    public static function roles(): \Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository
    {
        self::need('Modules\\Users\\Infrastructure\\Persistence\\Repositories\\RoleRepository', 'Users');

        return self::$roles ??= new \Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }

    public static function settings(): SettingsRepository
    {
        return self::$settings ??= new SettingsRepository(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function menu(): MenuRepository
    {
        return self::$menu ??= new MenuRepository(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function auth(): \Modules\Users\Application\Services\AuthService
    {
        self::need('Modules\\Users\\Application\\Services\\AuthService', 'Users');

        return self::$auth ??= new \Modules\Users\Application\Services\AuthService(
            DatabaseConnectionManager::instance()->connection('users'),
            self::users(),
            new PasswordHasher(),
            new RateLimiter(
                DatabaseConnectionManager::instance()->connection('users'),
            ),
        );
    }

    public static function adminNav(): AdminNavService
    {
        return self::$adminNav ??= new AdminNavService(
            DatabaseConnectionManager::instance()->connection('config'),
        );
    }

    public static function pageCache(): PageCacheService
    {
        return self::$pageCache ??= new PageCacheService(
            APP_BASE_PATH . '/' . PageCacheService::DEFAULT_DIR,
        );
    }

    public static function pageCrud(): \Modules\Pages\Application\Services\PageCrudService
    {
        self::need('Modules\\Pages\\Application\\Services\\PageCrudService', 'Pages');

        return self::$pageCrud ??= new \Modules\Pages\Application\Services\PageCrudService();
    }

    public static function blogCrud(): \Modules\Blog\Application\Services\BlogCrudService
    {
        self::need('Modules\\Blog\\Application\\Services\\BlogCrudService', 'Blog');

        return self::$blogCrud ??= new \Modules\Blog\Application\Services\BlogCrudService();
    }

    /**
     * Fail loud when a cycle-hidden module is absent.
     *
     * The class name arrives as a plain string (never a `::class` constant
     * on a `use`-imported name) so this guard stays invisible to the closure
     * scanner — the same reason the factories above are fully-qualified.
     *
     * @throws \RuntimeException naming the missing module and its restore
     */
    private static function need(string $class, string $module): void
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(
                sprintf("Cardboard requires the '%s' module (bin/cms module:add %s).", $module, $module),
            );
        }
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$users = null;
        self::$roles = null;
        self::$settings = null;
        self::$menu = null;
        self::$auth = null;
        self::$adminNav = null;
        self::$pageCache = null;
        self::$pageCrud = null;
        self::$blogCrud = null;
    }
}
