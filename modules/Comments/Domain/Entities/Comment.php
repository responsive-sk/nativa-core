<?php

declare(strict_types = 1);

namespace Modules\Comments\Domain\Entities;

/**
 * Comment Entity.
 *
 * A reader comment on an article slug. Guests carry guest_name + guest_email;
 * signed-in users carry user_id instead (guest fields null). Status follows
 * the lifecycle pending → approved | rejected; only approved comments render
 * publicly. Plain data entity, zero dependencies (pure Domain).
 */
final class Comment
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    private string $id;

    private string $articleSlug;

    private ?string $userId = null;

    private ?string $guestName = null;

    private ?string $guestEmail = null;

    private string $body;

    private string $status;

    private ?string $ipAddress = null;

    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    /**
     * Create a new comment (pending by default).
     */
    public static function create(
        string $articleSlug,
        ?string $userId,
        ?string $guestName,
        ?string $guestEmail,
        string $body,
        ?string $ipAddress = null,
    ): self {
        $now = new \DateTimeImmutable();
        $instance = new self();
        $instance->id = bin2hex(random_bytes(16));
        $instance->articleSlug = $articleSlug;
        $instance->userId = $userId;
        $instance->guestName = $guestName;
        $instance->guestEmail = $guestEmail;
        $instance->body = $body;
        $instance->status = self::STATUS_PENDING;
        $instance->ipAddress = $ipAddress;
        $instance->createdAt = $now;

        return $instance;
    }

    /**
     * Hydrate from a database row.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->id = self::s($data['id'] ?? '');
        $instance->articleSlug = self::s($data['article_slug'] ?? '');
        $instance->userId = self::nullable($data['user_id'] ?? null);
        $instance->guestName = self::nullable($data['guest_name'] ?? null);
        $instance->guestEmail = self::nullable($data['guest_email'] ?? null);
        $instance->body = self::s($data['body'] ?? '');
        $instance->status = self::s($data['status'] ?? self::STATUS_PENDING);
        $instance->ipAddress = self::nullable($data['ip_address'] ?? null);
        $instance->createdAt = new \DateTimeImmutable(self::s($data['created_at'] ?? 'now'));

        return $instance;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function articleSlug(): string
    {
        return $this->articleSlug;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function guestName(): ?string
    {
        return $this->guestName;
    }

    public function guestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Display name — the name captured at submit time (the signed-in user's
     * account name or the guest's display name). Never the email.
     */
    public function displayName(): string
    {
        $name = $this->guestName;

        return null !== $name && '' !== $name ? $name : $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'article_slug' => $this->articleSlug,
            'user_id'      => $this->userId,
            'guest_name'   => $this->guestName,
            'guest_email'  => $this->guestEmail,
            'body'         => $this->body,
            'status'       => $this->status,
            'ip_address'   => $this->ipAddress,
            'created_at'   => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function s(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function nullable(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $string = self::s($value);

        return '' === $string ? null : $string;
    }
}
