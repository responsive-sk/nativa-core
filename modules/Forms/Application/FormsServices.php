<?php

declare(strict_types = 1);

namespace Modules\Forms\Application;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\Services\FormResultsService;
use Modules\Forms\Infrastructure\Persistence\Repositories\FormRepository;
use Modules\Forms\Infrastructure\Persistence\Repositories\SubmissionRepository;
use Modules\Security\Application\Services\RateLimiter;

/**
 * FormsServices — lazy cached service factories for the Forms module.
 *
 * Same shape as `QuestionsServices`: the kernel instantiates action classes
 * with no dependency injection, so the shared services are resolved through
 * this factory and nothing is constructed until a surface needs it.
 *
 * Two connections, deliberately separate: definitions and submissions live on
 * `forms`, the rate limiter lives on `users` (where `rate_limits` is). SQLite
 * cannot join across databases, and a limiter pointed at `forms` would create
 * a second, empty table nobody migrates.
 */
final class FormsServices
{
    private static ?FormRepository $forms = null;

    private static ?SubmissionRepository $submissions = null;

    private static ?FormResultsService $results = null;

    private static ?RateLimiter $limiter = null;

    public static function forms(): FormRepository
    {
        return self::$forms ??= new FormRepository(
            DatabaseConnectionManager::instance()->connection('forms'),
        );
    }

    public static function submissions(): SubmissionRepository
    {
        return self::$submissions ??= new SubmissionRepository(
            DatabaseConnectionManager::instance()->connection('forms'),
        );
    }

    public static function results(): FormResultsService
    {
        return self::$results ??= new FormResultsService();
    }

    /**
     * The rate limiter, backed by users.db (where rate_limits lives).
     */
    public static function limiter(): RateLimiter
    {
        return self::$limiter ??= new RateLimiter(
            DatabaseConnectionManager::instance()->connection('users'),
        );
    }

    /**
     * Reset cached instances and the cached PDO connection (test isolation).
     *
     * The connection is closed too: pointing `DB_FORMS` at a new file only
     * takes effect on a connection that is opened after the change, and a
     * suite that forgot this would carry the previous test's rows into the
     * next one.
     */
    public static function reset(): void
    {
        self::$forms = null;
        self::$submissions = null;
        self::$results = null;
        self::$limiter = null;

        DatabaseConnectionManager::instance()->close('forms');
    }
}
