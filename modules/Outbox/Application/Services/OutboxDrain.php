<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Services;

use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;

/**
 * OutboxDrain — delivers recorded events after the response is built.
 *
 * The kit ships no worker, no queue daemon and no cron, so a synchronous
 * end-of-request drain is the delivery path. `DisposalRegistry` is the right
 * host for it: `public/index.php` already drains that registry after the
 * response is dispatched, and it runs every callback even when one throws —
 * so a failing handler cannot corrupt an already-sent response.
 *
 * Registering the drain is idempotent. Several publishes in one request (a
 * bulk action, an HTMX sweep) each call `defer()` and only one drain runs,
 * which is also the cheaper shape: one pass over the queue per request
 * instead of one per write.
 */
final class OutboxDrain
{
    private const string DISPOSAL_ID = 'outbox.drain';

    private static bool $deferred = false;

    /**
     * Queue a drain for the end of this request, unless one is already queued.
     */
    public static function defer(): void
    {
        if (self::$deferred) {
            return;
        }

        self::$deferred = true;

        DisposalRegistry::register(self::DISPOSAL_ID, static function (): void {
            self::drain();
        });
    }

    /**
     * Deliver every pending message now. Returns the processor's counts.
     *
     * @return array{processed: int, failed: int}
     */
    public static function drain(int $limit = 100): array
    {
        $processor = OutboxServices::processor();
        // Only the handler this module can see. The security handler belongs
        // to the Audit module — its output is an audit row — and is registered
        // by the composition root, so `drain()` stays callable from tests and
        // the CLI without either reaching into another module's tables.
        ArticlePublishHandler::register($processor);

        return $processor->process($limit);
    }

    /**
     * Forget a pending drain (test isolation).
     */
    public static function reset(): void
    {
        self::$deferred = false;
    }
}
