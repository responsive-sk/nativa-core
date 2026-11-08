<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Entities;

use DateTimeImmutable;

/**
 * FormSubmission — one stored answer set, and its place in the queue.
 *
 * `payload` is the raw strings the reader submitted, keyed by field key. It is
 * stored raw and escaped at render time, the same rule comments and questions
 * follow: escaping on the way in makes a second escape a double-encode on the
 * page, and it mangles text that legitimately contains punctuation.
 *
 * `STATUS_NEW` is queue depth, not unread state: handling a submission is what
 * clears the badge, because the badge answers "is anything waiting".
 */
final readonly class FormSubmission
{
    public const string STATUS_NEW = 'new';

    public const string STATUS_HANDLED = 'handled';

    /**
     * @param array<string, string> $payload
     */
    public function __construct(
        public string $id,
        public string $formId,
        public string $formSlug,
        public string $locale,
        public array $payload,
        public string $status,
        public ?string $ipAddress,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $handledAt = null,
    ) {
    }

    /**
     * @param array<string, string> $payload
     */
    public static function create(
        string $formId,
        string $formSlug,
        string $locale,
        array $payload,
        ?string $ipAddress,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $formId,
            $formSlug,
            $locale,
            $payload,
            self::STATUS_NEW,
            $ipAddress,
            new DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $decoded = json_decode((string) ($row['payload'] ?? '{}'), true);
        $payload = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $payload[$key] = (string) $value;
            }
        }

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['form_id'] ?? ''),
            (string) ($row['form_slug'] ?? ''),
            (string) ($row['locale'] ?? ''),
            $payload,
            (string) ($row['status'] ?? self::STATUS_NEW),
            is_string($row['ip_address'] ?? null) ? $row['ip_address'] : null,
            self::date($row['created_at'] ?? null),
            self::date($row['handled_at'] ?? null),
        );
    }

    public function isNew(): bool
    {
        return self::STATUS_NEW === $this->status;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
