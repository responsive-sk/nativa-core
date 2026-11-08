<?php

declare(strict_types = 1);

namespace Modules\Audit\Infrastructure\Persistence\Repositories;

use Modules\Audit\Domain\Entities\AuditEntry;
use Modules\Audit\Infrastructure\Persistence\Statement;

/**
 * AuditLogRepository — append-only persistence for audit_log.
 *
 * Deliberately exposes NO update/delete methods: the application layer can
 * only insert and read. The database layer enforces the same contract with
 * UPDATE/DELETE-aborting triggers (AuditDatabaseMigrator) — a tamperproof
 * audit trail. Concrete class (C-pattern: no speculative interface).
 */
final readonly class AuditLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Append an entry — the only write path.
     */
    public function insert(AuditEntry $entry): void
    {
        $data = $entry->toArray();
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO audit_log (id, actor_user_id, actor_name, action, target, detail, ip_address, created_at)
             VALUES (:id, :actor_user_id, :actor_name, :action, :target, :detail, :ip_address, :created_at)',
        );
        $stmt->execute([
            'id'            => $data['id'],
            'actor_user_id' => $data['actor_user_id'],
            'actor_name'    => $data['actor_name'],
            'action'        => $data['action'],
            'target'        => $data['target'],
            'detail'        => $data['detail'],
            'ip_address'    => $data['ip_address'],
            'created_at'    => $data['created_at'],
        ]);
    }

    /**
     * Newest entries first.
     *
     * created_at has second granularity, so rowid (monotonic insert order) is
     * the tiebreaker — two entries logged in the same second keep their real
     * order instead of a random id order.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM audit_log ORDER BY created_at DESC, rowid DESC LIMIT :limit',
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];

        foreach ($stmt->fetchAll() as $row) {
            $entries[] = AuditEntry::fromArray($row);
        }

        return $entries;
    }

    /**
     * Total number of logged actions.
     */
    public function count(): int
    {
        $stmt = Statement::query($this->pdo, 'SELECT COUNT(*) FROM audit_log');

        return (int) $stmt->fetchColumn();
    }
}
