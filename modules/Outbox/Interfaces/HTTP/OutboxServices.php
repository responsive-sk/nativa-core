<?php

declare(strict_types = 1);

namespace Modules\Outbox\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Application\Services\OutboxProcessor;
use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Infrastructure\Persistence\Repositories\SqliteOutboxRepository;

/**
 * OutboxServices — lazy cached service factories for the Outbox module.
 *
 * Publishes domain events recorded after the business write. The delivery
 * guarantee is documented on the class below and in the spec: this kit
 * cannot offer a transactional outbox (articles are markdown files, and
 * outbox.db is a separate SQLite database from every module's own), so the
 * event is written after the write succeeds and a crash between the two
 * loses the event, never the write.
 */
final class OutboxServices
{
    /** Published or unpublished an article — the only shipped event types. */
    public const string ARTICLE_PUBLISHED = 'article.published';

    public const string ARTICLE_UNPUBLISHED = 'article.unpublished';

    private static ?SqliteOutboxRepository $repository = null;

    private static ?OutboxProcessor $processor = null;

    public static function repository(): SqliteOutboxRepository
    {
        return self::$repository ??= self::newRepository();
    }

    public static function processor(): OutboxProcessor
    {
        return self::$processor ??= new OutboxProcessor(
            self::repository(),
        );
    }

    /**
     * Record an article publish/unpublish event.
     *
     * @param string $type  self::ARTICLE_PUBLISHED or self::ARTICLE_UNPUBLISHED
     * @param string $actor Who caused the change, carried as metadata
     *
     * @return bool false when the event could not be stored — the caller
     *              MUST NOT treat that as a failed publish
     */
    public static function articleEvent(string $type, string $slug, string $locale, string $actor): bool
    {
        try {
            self::repository()->save(OutboxMessage::create(
                $type,
                ['slug' => $slug, 'locale' => $locale],
                ['actor' => $actor],
            ));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[OUTBOX] Could not record %s for %s: %s',
                $type,
                $slug,
                $e->getMessage(),
            ));

            return false;
        }

        return true;
    }

    /**
     * Record a security event.
     *
     * Same contract as `articleEvent()`, and for the same reason: the write
     * this describes has already happened, so failing to record it must not
     * surface as a failed write. Returns false and logs instead of throwing.
     *
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $metadata
     *
     * @return bool false when the event could not be stored
     */
    public static function securityEvent(
        string $type,
        array $payload,
        ?array $metadata = null,
    ): bool {
        try {
            self::$repository ??= self::newRepository();

            self::$repository->save(OutboxMessage::create($type, $payload, $metadata));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[outbox] Could not record %s: %s',
                $type,
                $e->getMessage(),
            ));

            return false;
        }

        OutboxDrain::defer();

        return true;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$repository = null;
        self::$processor = null;
    }

    private static function newRepository(): SqliteOutboxRepository
    {
        return new SqliteOutboxRepository(
            DatabaseConnectionManager::instance()->connect('outbox'),
        );
    }
}
