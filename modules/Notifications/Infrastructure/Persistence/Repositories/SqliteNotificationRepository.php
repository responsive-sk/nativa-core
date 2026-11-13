<?php

declare(strict_types = 1);

namespace Modules\Notifications\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Notifications\Domain\Model\Notification;
use Modules\Notifications\Infrastructure\Persistence\Statement;

/**
 * SqliteNotificationRepository — the inbox table, read per notifiable.
 *
 * Every method that could cross users takes the notifiable as an argument, and
 * every write puts it in the WHERE clause. `markRead()` is the one that matters:
 * its id arrives in a URL, and without `AND notifiable_id = ?` any signed-in
 * moderator could clear another's row by guessing an id. Scoping in SQL rather
 * than in a caller is what makes that unskippable.
 *
 * Ordering is `created_at DESC, rowid DESC` — the rowid tiebreak exists because
 * `date('Y-m-d H:i:s')` has one-second resolution, and a burst of two
 * notifications in the same second would otherwise come back in insert order
 * only by accident.
 */
final readonly class SqliteNotificationRepository
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    public function save(Notification $notification): void
    {
        $data = $notification->toArray();

        $stmt = Statement::prepare($this->connection, '
            INSERT INTO notifications (id, notifiable_id, kind, title, body, href, read_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $data['id'],
            $data['notifiable_id'],
            $data['kind'],
            $data['title'],
            $data['body'],
            $data['href'],
            $data['read_at'],
            $data['created_at'],
        ]);
    }

    public function findById(string $id): ?Notification
    {
        $stmt = Statement::prepare($this->connection, 'SELECT * FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (false === $row || !is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return Notification::fromArray($row);
    }

    /**
     * One user's inbox, newest first.
     *
     * `$limit`/`$offset` exist from the start so the panel can page the list
     * without re-reading it — the shape `OffsetPaginator` expects.
     *
     * @return list<Notification>
     */
    public function forNotifiable(string $notifiableId, int $limit = 50, int $offset = 0): array
    {
        $stmt = Statement::prepare($this->connection, '
            SELECT * FROM notifications
            WHERE notifiable_id = ?
            ORDER BY created_at DESC, rowid DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$notifiableId, max(1, $limit), max(0, $offset)]);

        $rows = $stmt->fetchAll();

        /** @var list<Notification> $notifications */
        $notifications = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $notifications[] = Notification::fromArray($row);
        }

        return $notifications;
    }

    public function countFor(string $notifiableId): int
    {
        $stmt = Statement::prepare($this->connection, 'SELECT COUNT(*) FROM notifications WHERE notifiable_id = ?');
        $stmt->execute([$notifiableId]);

        return (int) $stmt->fetchColumn();
    }

    public function unreadCount(string $notifiableId): int
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT COUNT(*) FROM notifications WHERE notifiable_id = ? AND read_at IS NULL',
        );
        $stmt->execute([$notifiableId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark one row read, only when it belongs to that user.
     *
     * Returns false for "not yours" and for "already read" alike, which is the
     * right answer to the caller in both cases: nothing changed.
     */
    public function markRead(string $id, string $notifiableId): bool
    {
        $stmt = Statement::prepare($this->connection, '
            UPDATE notifications
            SET read_at = ?
            WHERE id = ? AND notifiable_id = ? AND read_at IS NULL
        ');

        $stmt->execute([date('Y-m-d H:i:s'), $id, $notifiableId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Clear one user's whole inbox; other users' rows are untouched.
     *
     * @return int rows marked read
     */
    public function markAllAsRead(string $notifiableId): int
    {
        $stmt = Statement::prepare($this->connection, '
            UPDATE notifications
            SET read_at = ?
            WHERE notifiable_id = ? AND read_at IS NULL
        ');

        $stmt->execute([date('Y-m-d H:i:s'), $notifiableId]);

        return $stmt->rowCount();
    }
}
