<?php

declare(strict_types = 1);

namespace Modules\Audit\Interfaces\HTTP;

use Modules\Audit\Application\Services\AuditService;
use Modules\Audit\Infrastructure\Persistence\Repositories\AuditLogRepository;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * AuditServices — lazy cached service factories for the Audit module.
 *
 * Same pattern as LocalesServices/CommentsServices: actions resolve shared
 * services through these factories. auth() exposes the current admin user so
 * write actions can attribute audit entries.
 */
final class AuditServices
{
    private static ?AuditService $audit = null;

    private static ?AuthService $auth = null;

    public static function audit(): AuditService
    {
        return self::$audit ??= new AuditService(
            new AuditLogRepository(
                DatabaseConnectionManager::instance()->connect('audit'),
            ),
        );
    }

    /**
     * AuthService over users.db — current admin attribution for audit rows.
     */
    public static function auth(): AuthService
    {
        if (null === self::$auth) {
            // One routed connection for the whole graph, so a write any of
            // these three makes is visible to the others' next read.
            $db = DatabaseConnectionManager::instance()->connect('users');

            self::$auth = new AuthService(
                $db,
                new UserRepository($db),
                new PasswordHasher(),
                new RateLimiter($db),
            );
        }

        return self::$auth;
    }

    /**
     * Convenience: record an admin write action with the current admin as the
     * actor (falls back to a placeholder when no session user is present,
     * e.g. CLI/seed contexts).
     */
    public static function log(string $action, string $target, string $detail, ?string $ipAddress = null): void
    {
        $user = self::auth()->currentUser();

        self::audit()->log(
            $action,
            $target,
            $detail,
            null === $user ? 'system' : $user->id(),
            null === $user ? 'system' : $user->name(),
            $ipAddress,
        );
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$audit = null;
        self::$auth = null;
    }
}
