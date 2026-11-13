<?php

declare(strict_types = 1);

namespace Modules\Audit\Application\Services;

use Modules\Audit\Domain\Entities\AuditEntry;
use Modules\Audit\Infrastructure\Persistence\Repositories\AuditLogRepository;

/**
 * AuditService — append + read the immutable admin audit log.
 *
 * The actor (user id + name) is resolved by the caller from the session
 * (AuditServices::auth()); this service only persists what it is given, so
 * it stays a thin, DB-only wrapper over the append-only repository.
 */
final readonly class AuditService
{
    public function __construct(private AuditLogRepository $entries)
    {
    }

    /**
     * Record one admin write action.
     */
    public function log(
        string $action,
        string $target,
        string $detail,
        string $actorUserId,
        string $actorName,
        ?string $ipAddress = null,
    ): void {
        $this->entries->insert(AuditEntry::create(
            $actorUserId,
            $actorName,
            $action,
            $target,
            $detail,
            $ipAddress,
        ));
    }

    /**
     * Record one entry under a caller-supplied id.
     *
     * The outbox security handler uses the outbox message id as the audit row's
     * primary key, so a redelivered message collides and is discarded. That is
     * what makes at-least-once delivery safe here \u2014 see `SecurityEventHandler`.
     *
     * `$actorName` is a separate argument on purpose: this method used to pass
     * the actor id into `AuditEntry::createWithId()` twice, so the viewer's
     * "Admin" column showed a 32-character id instead of the name the column
     * asks for. An audit row needs BOTH the immutable id and the readable name;
     * one cannot stand in for the other.
     *
     * @internal
     */
    public function logWithId(
        string $id,
        string $action,
        string $detail,
        string $actorUserId,
        string $actorName,
        string $target = '',
        ?string $ipAddress = null,
    ): void {
        $this->entries->insert(AuditEntry::createWithId(
            $id,
            $actorUserId,
            $actorName,
            $action,
            $target,
            $detail,
            $ipAddress,
        ));
    }

    /**
     * Newest entries first.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit = 100): array
    {
        return $this->entries->recent($limit);
    }

    public function count(): int
    {
        return $this->entries->count();
    }
}
