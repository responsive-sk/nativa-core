<?php

declare(strict_types = 1);

namespace Modules\Outbox\Domain\Model;

/**
 * OutboxMessage — domain event waiting to be published.
 *
 * Immutable after creation. State changes via with*() methods that return
 * new instances. The outbox pattern ensures atomicity: events are stored
 * in the same transaction as the business operation, then processed async.
 *
 * Status flow: pending → processing → processed | failed
 */
final readonly class OutboxMessage
{
    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $metadata
     */
    private function __construct(
        private string $id,
        private string $eventType,
        private array $payload,
        private ?array $metadata,
        private string $status,
        private int $attempts,
        private int $maxAttempts,
        private ?string $lastAttemptAt,
        private ?string $processedAt,
        private ?string $errorMessage,
        private string $createdAt,
    ) {
    }

    /**
     * Create a new outbox message.
     *
     * @param array<string, mixed>      $payload  Event payload data
     * @param array<string, mixed>|null $metadata Optional metadata
     */
    public static function create(
        string $eventType,
        array $payload,
        ?array $metadata = null,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            eventType: $eventType,
            payload: $payload,
            metadata: $metadata,
            status: 'pending',
            attempts: 0,
            maxAttempts: 5,
            lastAttemptAt: null,
            processedAt: null,
            errorMessage: null,
            createdAt: date('Y-m-d H:i:s'),
        );
    }

    /**
     * Hydrate from database array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $id */
        $id = $data['id'] ?? '';
        /** @var string $eventType */
        $eventType = $data['event_type'] ?? '';
        /** @var array<string, mixed> $payload */
        $payload = is_string($data['event_payload'] ?? null)
            ? json_decode($data['event_payload'], true, 512, JSON_THROW_ON_ERROR)
            : [];
        /** @var array<string, mixed>|null $metadata */
        $metadata = is_string($data['metadata'] ?? null)
            ? json_decode($data['metadata'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        /** @var string $status */
        $status = $data['status'] ?? 'pending';
        $attempts = is_numeric($data['attempts'] ?? null) ? (int) $data['attempts'] : 0;
        $maxAttempts = is_numeric($data['max_attempts'] ?? null) ? (int) $data['max_attempts'] : 5;
        /** @var string|null $lastAttemptAt */
        $lastAttemptAt = $data['last_attempt_at'] ?? null;
        /** @var string|null $processedAt */
        $processedAt = $data['processed_at'] ?? null;
        /** @var string|null $errorMessage */
        $errorMessage = $data['error_message'] ?? null;
        /** @var string $createdAt */
        $createdAt = $data['created_at'] ?? '';

        return new self(
            id: $id,
            eventType: $eventType,
            payload: $payload,
            metadata: $metadata,
            status: $status,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
            lastAttemptAt: $lastAttemptAt,
            processedAt: $processedAt,
            errorMessage: $errorMessage,
            createdAt: $createdAt,
        );
    }

    /**
     * Convert to database array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'event_payload' => json_encode($this->payload, JSON_THROW_ON_ERROR),
            'metadata' => $this->metadata ? json_encode($this->metadata, JSON_THROW_ON_ERROR) : null,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'last_attempt_at' => $this->lastAttemptAt,
            'processed_at' => $this->processedAt,
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt,
        ];
    }

    // -- State transitions (immutable) --

    /**
     * Mark message as being processed.
     */
    public function withProcessing(): self
    {
        return new self(
            id: $this->id,
            eventType: $this->eventType,
            payload: $this->payload,
            metadata: $this->metadata,
            status: 'processing',
            attempts: $this->attempts + 1,
            maxAttempts: $this->maxAttempts,
            lastAttemptAt: date('Y-m-d H:i:s'),
            processedAt: $this->processedAt,
            errorMessage: $this->errorMessage,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Mark message as successfully processed.
     */
    public function withProcessed(): self
    {
        return new self(
            id: $this->id,
            eventType: $this->eventType,
            payload: $this->payload,
            metadata: $this->metadata,
            status: 'processed',
            attempts: $this->attempts,
            maxAttempts: $this->maxAttempts,
            lastAttemptAt: $this->lastAttemptAt,
            processedAt: date('Y-m-d H:i:s'),
            errorMessage: null,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Mark message as failed.
     */
    public function withFailed(string $error): self
    {
        return new self(
            id: $this->id,
            eventType: $this->eventType,
            payload: $this->payload,
            metadata: $this->metadata,
            status: 'failed',
            attempts: $this->attempts,
            maxAttempts: $this->maxAttempts,
            lastAttemptAt: $this->lastAttemptAt,
            processedAt: $this->processedAt,
            errorMessage: $error,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Mark message for retry (reset to pending if under max attempts).
     */
    public function withRetry(): self
    {
        return new self(
            id: $this->id,
            eventType: $this->eventType,
            payload: $this->payload,
            metadata: $this->metadata,
            status: 'pending',
            attempts: $this->attempts,
            maxAttempts: $this->maxAttempts,
            lastAttemptAt: $this->lastAttemptAt,
            processedAt: $this->processedAt,
            errorMessage: null,
            createdAt: $this->createdAt,
        );
    }

    // -- Getters --

    public function id(): string
    {
        return $this->id;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function lastAttemptAt(): ?string
    {
        return $this->lastAttemptAt;
    }

    public function processedAt(): ?string
    {
        return $this->processedAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    // -- Status checks --

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isProcessed(): bool
    {
        return 'processed' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }
}
