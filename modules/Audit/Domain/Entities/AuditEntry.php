<?php

declare(strict_types = 1);

namespace Modules\Audit\Domain\Entities;

/**
 * AuditEntry Entity.
 *
 * One immutable record of an admin write action: who (actor user id + name,
 * denormalized at write time), what (action), on what (target reference),
 * detail, from where (IP) and when. Plain data entity, zero dependencies
 * (pure Domain).
 */
final class AuditEntry
{
    private string $id;

    private string $actorUserId;

    private string $actorName;

    private string $action;

    private string $target;

    private string $detail;

    private ?string $ipAddress = null;

    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public static function create(
        string $actorUserId,
        string $actorName,
        string $action,
        string $target = '',
        string $detail = '',
        ?string $ipAddress = null,
    ): self {
        $instance = new self();
        $instance->id = bin2hex(random_bytes(16));
        $instance->actorUserId = $actorUserId;
        $instance->actorName = $actorName;
        $instance->action = $action;
        $instance->target = $target;
        $instance->detail = $detail;
        $instance->ipAddress = $ipAddress;
        $instance->createdAt = new \DateTimeImmutable();

        return $instance;
    }

    /**
     * Create an entry with a caller-supplied id.
     *
     * Needed by the outbox security handler, whose idempotency rests on the
     * audit row's primary key BEING the outbox message id: a replayed message
     * then collides and is discarded, instead of the handler having to guess
     * whether two events are "the same one".
     *
     * @internal
     */
    public static function createWithId(
        string $id,
        string $actorUserId,
        string $actorName,
        string $action,
        string $target = '',
        string $detail = '',
        ?string $ipAddress = null,
    ): self {
        $instance = self::create($actorUserId, $actorName, $action, $target, $detail, $ipAddress);
        $instance->id = $id;

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
        $instance->actorUserId = self::s($data['actor_user_id'] ?? '');
        $instance->actorName = self::s($data['actor_name'] ?? '');
        $instance->action = self::s($data['action'] ?? '');
        $instance->target = self::s($data['target'] ?? '');
        $instance->detail = self::s($data['detail'] ?? '');
        $instance->ipAddress = self::nullable($data['ip_address'] ?? null);
        $instance->createdAt = new \DateTimeImmutable(self::s($data['created_at'] ?? 'now'));

        return $instance;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorUserId(): string
    {
        return $this->actorUserId;
    }

    public function actorName(): string
    {
        return $this->actorName;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'actor_user_id' => $this->actorUserId,
            'actor_name'    => $this->actorName,
            'action'        => $this->action,
            'target'        => $this->target,
            'detail'        => $this->detail,
            'ip_address'    => $this->ipAddress,
            'created_at'    => $this->createdAt->format('Y-m-d H:i:s'),
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
