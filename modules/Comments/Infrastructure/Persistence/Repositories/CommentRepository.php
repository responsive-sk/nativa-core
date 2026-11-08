<?php

declare(strict_types = 1);

namespace Modules\Comments\Infrastructure\Persistence\Repositories;

use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Infrastructure\Persistence\Statement;

/**
 * CommentRepository — SQLite persistence for comments.
 *
 * Concrete class (no interface — C-pattern: an internal single-implementation
 * interface is speculative abstraction; call the class directly).
 */
final readonly class CommentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(string $id): ?Comment
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM comments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return is_array($row) ? Comment::fromArray($row) : null;
    }

    /**
     * Approved comments for an article, oldest first.
     *
     * @return list<Comment>
     */
    public function findByArticle(string $slug): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM comments WHERE article_slug = :slug AND status = :status ORDER BY created_at ASC',
        );
        $stmt->execute(['slug' => $slug, 'status' => Comment::STATUS_APPROVED]);

        return $this->hydrateAll($stmt);
    }

    /**
     * Comments by status, newest first.
     *
     * @return list<Comment>
     */
    public function findByStatus(string $status): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM comments WHERE status = :status ORDER BY created_at DESC',
        );
        $stmt->execute(['status' => $status]);

        return $this->hydrateAll($stmt);
    }

    /**
     * All comments, pending first then newest.
     *
     * @return list<Comment>
     */
    public function all(): array
    {
        $stmt = Statement::query($this->pdo,
            'SELECT * FROM comments ORDER BY CASE status WHEN \'pending\' THEN 0 ELSE 1 END, created_at DESC',
        );

        return $this->hydrateAll($stmt);
    }

    public function insert(Comment $comment): void
    {
        $data = $comment->toArray();
        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO comments (id, article_slug, user_id, guest_name, guest_email, body, status, ip_address, created_at)
             VALUES (:id, :slug, :user_id, :guest_name, :guest_email, :body, :status, :ip, :created)',
        );
        $stmt->execute([
            'id'          => $data['id'],
            'slug'        => $data['article_slug'],
            'user_id'     => $data['user_id'],
            'guest_name'  => $data['guest_name'],
            'guest_email' => $data['guest_email'],
            'body'        => $data['body'],
            'status'      => $data['status'],
            'ip'          => $data['ip_address'],
            'created'     => $data['created_at'],
        ]);
    }

    /**
     * Transition a comment to a new status (approve/reject).
     */
    public function setStatus(string $id, string $status): void
    {
        $stmt = Statement::prepare($this->pdo, 'UPDATE comments SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function delete(string $id): void
    {
        $stmt = Statement::prepare($this->pdo, 'DELETE FROM comments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param \PDOStatement $stmt
     *
     * @return list<Comment>
     */
    private function hydrateAll(\PDOStatement $stmt): array
    {
        $comments = [];

        foreach ($stmt->fetchAll() as $row) {
            $comments[] = Comment::fromArray($row);
        }

        return $comments;
    }
}
