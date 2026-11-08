<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Infrastructure\Mail\PasswordResetMailerFactory;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * AuthServices — lazy service factories for the HTTP layer.
 *
 * The Kernel instantiates action classes without dependency injection, so
 * actions resolve the shared services through these factories. Connections
 * are cached by DatabaseConnectionManager, so repeated construction is cheap.
 */
final class AuthServices
{
    private static ?AuthService $auth = null;

    private static ?PasswordResetService $resets = null;

    public static function auth(): AuthService
    {
        if (null === self::$auth) {
            $pdo = DatabaseConnectionManager::instance()->connection('users');

            self::$auth = new AuthService(
                $pdo,
                new UserRepository($pdo),
                new PasswordHasher(),
                new RateLimiter($pdo),
            );
        }

        return self::$auth;
    }

    public static function resets(): PasswordResetService
    {
        if (null === self::$resets) {
            $pdo = DatabaseConnectionManager::instance()->connection('users');

            // The mailer is resolved here, once: which transport carries a
            // reset link is an environment fact, and this is where the service
            // graph is composed (see PasswordResetMailerFactory).
            self::$resets = new PasswordResetService(
                $pdo,
                new UserRepository($pdo),
                new PasswordHasher(),
                PasswordResetMailerFactory::create(),
                new RateLimiter($pdo),
            );
        }

        return self::$resets;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$auth = null;
        self::$resets = null;
    }
}
