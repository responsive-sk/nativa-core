<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Services;

use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Infrastructure\Persistence\Repositories\SqliteOutboxRepository;

/**
 * OutboxProcessor — processes pending outbox messages.
 *
 * Picks up pending messages from the outbox, dispatches them to handlers,
 * and updates their status. Designed to be run as a background job or
 * CLI command.
 *
 * Usage:
 *   $processor = new OutboxProcessor($repository);
 *   $processed = $processor->process(100); // process up to 100 messages
 */
final class OutboxProcessor
{
    /** @var array<string, callable(OutboxMessage): void> */
    private array $handlers = [];

    public function __construct(
        private readonly SqliteOutboxRepository $repository,
    ) {
    }

    /**
     * Register a handler for an event type.
     *
     * @param string                        $eventType The event type to handle
     * @param callable(OutboxMessage): void $handler   The handler callback
     */
    public function registerHandler(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /**
     * Process pending messages.
     *
     * @param int $limit Maximum number of messages to process
     *
     * @return array{processed: int, failed: int}
     */
    public function process(int $limit = 100): array
    {
        $pending = $this->repository->findPending($limit);
        $processed = 0;
        $failed = 0;

        foreach ($pending as $message) {
            try {
                $this->processMessage($message);
                $processed++;
            } catch (\Throwable $e) {
                $this->handleError($message, $e);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * Process a single message.
     */
    private function processMessage(OutboxMessage $message): void
    {
        // Mark as processing
        $processing = $message->withProcessing();
        $this->repository->update($processing);

        // Find and execute handler
        $handler = $this->handlers[$message->eventType()] ?? null;

        if (null === $handler) {
            throw new \RuntimeException(
                sprintf('No handler registered for event type: %s', $message->eventType()),
            );
        }

        $handler($processing);

        // Mark as processed
        $this->repository->update($processing->withProcessed());
    }

    /**
     * Handle a failed message.
     */
    private function handleError(OutboxMessage $message, \Throwable $error): void
    {
        $failed = $message->withFailed($error->getMessage());
        $this->repository->update($failed);

        error_log(sprintf(
            '[OUTBOX] Failed to process %s (attempt %d/%d): %s',
            $message->eventType(),
            $failed->attempts(),
            $failed->maxAttempts(),
            $error->getMessage(),
        ));
    }

    /**
     * Get registered handler event types.
     *
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        return array_keys($this->handlers);
    }
}
