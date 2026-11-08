<?php

declare(strict_types = 1);

namespace Modules\Forms\Infrastructure\Persistence\Repositories;

use Modules\Forms\Domain\Entities\FormSubmission;
use Modules\Forms\Infrastructure\Persistence\Statement;

/**
 * SubmissionRepository — stored answers and the queue they form.
 *
 * `countNew()` is a `COUNT(*)`, not `count(listForForm(...))`: the badge runs
 * on every admin page, and hydrating the rows to count them is a waste that
 * only shows up once a form is used.
 *
 * `recentPayloads()` is bounded on purpose. The result bars are a display aid
 * inside a page render; an unbounded `GROUP BY` over a growing table is a
 * latency bug waiting for the busiest form, so the service tallies the newest
 * N rows in PHP instead.
 */
final readonly class SubmissionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(FormSubmission $submission): void
    {
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO form_submissions (id, form_id, form_slug, locale, payload, status, ip_address)'
            . ' VALUES (:id, :form_id, :form_slug, :locale, :payload, :status, :ip)',
        );
        $stmt->execute([
            'id'        => $submission->id,
            'form_id'   => $submission->formId,
            'form_slug' => $submission->formSlug,
            'locale'    => $submission->locale,
            'payload'   => (string) json_encode($submission->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'    => $submission->status,
            'ip'        => $submission->ipAddress,
        ]);
    }

    /**
     * @return list<FormSubmission>
     */
    public function listForForm(string $formId): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM form_submissions WHERE form_id = :id ORDER BY created_at DESC, rowid DESC',
        );
        $stmt->execute(['id' => $formId]);

        $submissions = [];

        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $submissions[] = FormSubmission::fromArray($row);
            }
        }

        return $submissions;
    }

    public function findById(string $id): ?FormSubmission
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM form_submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return is_array($row) ? FormSubmission::fromArray($row) : null;
    }

    /**
     * How many submissions are WAITING for a moderator. Queue depth, not an
     * unread marker: handling is what clears it.
     */
    public function countNew(): int
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT COUNT(*) FROM form_submissions WHERE status = :status',
        );
        $stmt->execute(['status' => FormSubmission::STATUS_NEW]);

        return (int) $stmt->fetchColumn();
    }

    public function countNewForForm(string $formId): int
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT COUNT(*) FROM form_submissions WHERE form_id = :id AND status = :status',
        );
        $stmt->execute(['id' => $formId, 'status' => FormSubmission::STATUS_NEW]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark one submission handled. Idempotent: a second POST changes nothing,
     * and the inbox stays consistent.
     */
    public function markHandled(string $id): void
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE form_submissions SET status = :handled, handled_at = CURRENT_TIMESTAMP'
            . ' WHERE id = :id AND status = :new',
        );
        $stmt->execute([
            'handled' => FormSubmission::STATUS_HANDLED,
            'new'     => FormSubmission::STATUS_NEW,
            'id'      => $id,
        ]);
    }

    public function delete(string $id): void
    {
        Statement::prepare($this->pdo, 'DELETE FROM form_submissions WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * The newest payloads of one form, for the result bars.
     *
     * @return list<array<string, string>>
     */
    public function recentPayloads(string $formId, int $limit): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT payload FROM form_submissions WHERE form_id = :id'
            . ' ORDER BY created_at DESC, rowid DESC LIMIT :limit',
        );
        $stmt->bindValue(':id', $formId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $payloads = [];

        foreach ($stmt->fetchAll() as $row) {
            $decoded = is_array($row) ? json_decode((string) ($row['payload'] ?? '{}'), true) : null;

            if (!is_array($decoded)) {
                continue;
            }

            $payload = [];

            foreach ($decoded as $key => $value) {
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $payload[$key] = (string) $value;
                }
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }
}
