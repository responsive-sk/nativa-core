<?php

declare(strict_types = 1);

namespace Modules\Questions\Infrastructure\Persistence\Repositories;

use Modules\Questions\Domain\Entities\Question;
use Modules\Questions\Infrastructure\Persistence\Statement;

/**
 * QuestionRepository — SQLite persistence for questions.
 *
 * Concrete class (no interface — C-pattern: an internal single-implementation
 * interface is speculative abstraction; call the class directly).
 *
 * All queries use prepared statements — no string-concatenated SQL.
 */
final readonly class QuestionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(string $id): ?Question
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return is_array($row) ? Question::fromArray($row) : null;
    }

    /**
     * Questions for a subject (discriminated pair + locale), grouped by status,
     * newest first within each status.
     *
     * @param string $subjectType 'article' or 'page'
     * @param string $subjectSlug the slug, unique only within subjectType
     * @param string $locale      the subject's locale
     *
     * @return list<Question>
     */
    public function findBySubject(string $subjectType, string $subjectSlug, string $locale): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM questions WHERE subject_type = :type AND subject_slug = :slug AND locale = :locale ORDER BY created_at DESC',
        );
        $stmt->execute([
            'type'  => $subjectType,
            'slug'  => $subjectSlug,
            'locale' => $locale,
        ]);

        return $this->hydrateAll($stmt);
    }

    /**
     * Pending questions (the default moderation queue), newest first.
     *
     * @return list<Question>
     */
    public function findPending(): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM questions WHERE status = :status ORDER BY created_at DESC',
        );
        $stmt->execute(['status' => Question::STATUS_PENDING]);

        return $this->hydrateAll($stmt);
    }

    /**
     * How many questions are WAITING for a moderator — one number, for the
     * admin nav badge that renders on every `/mark` page.
     *
     * A `COUNT(*)` and not `count(findPending())`: the badge runs on every
     * admin page render, and hydrating hundreds of rows to count them is a
     * waste that only shows up once the table is real. `idx_questions_status`
     * covers the predicate, so this is an index-only scan.
     */
    public function countPending(): int
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT COUNT(*) FROM questions WHERE status = :status',
        );
        $stmt->execute(['status' => Question::STATUS_PENDING]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * All questions, pending first then newest — the order the inbox groups
     * them in. `all()` rather than three status queries: the inbox needs every
     * row to count a group, and the row count of a reader-questions table is
     * measured in hundreds, not millions.
     *
     * @return list<Question>
     */
    public function all(): array
    {
        $stmt = Statement::query($this->pdo,
            'SELECT * FROM questions ORDER BY CASE status WHEN \'pending\' THEN 0 ELSE 1 END, created_at DESC',
        );

        return $this->hydrateAll($stmt);
    }

    public function insert(Question $question): void
    {
        $data = $question->toArray();
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO questions (id, subject_type, subject_slug, locale, text, attempted_answer, display_name, author_user_id, status, ip_address, created_at)
             VALUES (:id, :type, :slug, :locale, :text, :answer, :name, :author, :status, :ip, :created)',
        );
        $stmt->execute([
            'id'      => $data['id'],
            'type'    => $data['subject_type'],
            'slug'    => $data['subject_slug'],
            'locale'  => $data['locale'],
            'text'    => $data['text'],
            'answer'  => $data['attempted_answer'],
            'name'    => $data['display_name'],
            'author'  => $data['author_user_id'],
            'status'  => $data['status'],
            'ip'      => $data['ip_address'],
            'created' => $data['created_at'],
        ]);
    }

    /**
     * Transition a question to a new status (approve/reject).
     */
    public function setStatus(string $id, string $status): void
    {
        $stmt = Statement::prepare($this->pdo, 'UPDATE questions SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function delete(string $id): void
    {
        $stmt = Statement::prepare($this->pdo, 'DELETE FROM questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param \PDOStatement $stmt
     *
     * @return list<Question>
     */
    private function hydrateAll(\PDOStatement $stmt): array
    {
        $questions = [];

        foreach ($stmt->fetchAll() as $row) {
            $questions[] = Question::fromArray($row);
        }

        return $questions;
    }
}
