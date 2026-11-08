<?php

declare(strict_types = 1);

namespace Modules\Questions\Application;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Questions\Application\Services\SubjectResolver;
use Modules\Questions\Infrastructure\Persistence\Repositories\QuestionRepository;
use Modules\Security\Application\Services\RateLimiter;

/**
 * QuestionsServices — lazy cached service factories for the Questions module.
 *
 * Same pattern as CommentsServices/FormsServices: the Kernel instantiates
 * action classes without dependency injection, so shared services are resolved
 * through this factory.
 *
 * The repository lives on questions.db (the module's own database); the rate
 * limiter lives on users.db (that is where the rate_limits table is). SQLite
 * cannot join across databases, so the two connections are kept separate and
 * each service is pointed at the connection that holds its table.
 */
final class QuestionsServices
{
    private static ?QuestionRepository $repository = null;

    private static ?RateLimiter $limiter = null;

    private static ?SubjectResolver $subjects = null;

    /**
     * The question repository, backed by questions.db.
     */
    public static function repository(): QuestionRepository
    {
        return self::$repository ??= new QuestionRepository(
            DatabaseConnectionManager::instance()->connection('questions'),
        );
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
     * Re-resolves a subject pair against real, published content.
     */
    public static function subjects(): SubjectResolver
    {
        return self::$subjects ??= new SubjectResolver();
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$repository = null;
        self::$limiter = null;
        self::$subjects = null;
    }
}
