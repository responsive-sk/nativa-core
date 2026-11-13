<?php

declare(strict_types = 1);

namespace Modules\Notifications\Application\Services;

use Modules\Notifications\Domain\Model\Notification;
use Modules\Notifications\Infrastructure\Persistence\Repositories\SqliteNotificationRepository;

/**
 * NotificationInbox — the module's one entry point: write a notification to
 * people, read one person's inbox, clear their read state.
 *
 * ## Writes are a fan-out, on purpose
 *
 * `push()` takes the recipients and writes one row per recipient instead of one
 * shared row plus a reads table. The inbox this kit needs is small and its
 * readers are the panel's users; a row per reader makes "unread" a column, makes
 * a reader's badge a single `COUNT(*)`, and makes a new moderator start with an
 * empty inbox instead of last month's moderation backlog. The cost is one insert
 * per recipient, which is nothing at this size, and a pruning story for a later
 * change (the same one `queue:prune` tells for `jobs`).
 *
 * ## It does not decide who the recipients are
 *
 * That question is the Users module's — it owns users, roles and the permission
 * vocabulary — so this service is handed a list of ids. Keeping the policy out
 * of here is what lets the module stay a leaf: it imports Core and Database and
 * nothing else, which is why `AdminNotificationHandler` (Users) may depend on it
 * without closing a cycle through Cardboard.
 *
 * Failures are NOT swallowed here. A missing table, a locked database or a
 * broken row is a deployment defect, and the caller decides what an inbox write
 * that could not happen means for the work it just did — the queue handler logs
 * it and still delivers the mail, which is the only defensible order: a failed
 * inbox row must not cost the operator the notification itself.
 */
final readonly class NotificationInbox
{
    public function __construct(
        private SqliteNotificationRepository $repository,
    ) {
    }

    /**
     * Write one notification for each recipient.
     *
     * Blank ids and duplicates are dropped before the loop: the caller builds
     * the list from a user query, and a repeated id would otherwise produce two
     * identical rows for the same person.
     *
     * @param list<string> $notifiableIds the users whose inbox gains the row
     *
     * @return int rows written
     */
    public function push(
        array $notifiableIds,
        string $kind,
        string $title,
        string $body,
        string $href,
    ): int {
        $written = 0;

        foreach (array_unique($notifiableIds) as $notifiableId) {
            if ('' === $notifiableId) {
                continue;
            }

            $this->repository->save(Notification::create(
                $notifiableId,
                $kind,
                $title,
                $body,
                $href,
            ));

            $written++;
        }

        return $written;
    }

    /**
     * One user's inbox, newest first.
     *
     * @return list<Notification>
     */
    public function recent(string $notifiableId, int $limit = 50, int $offset = 0): array
    {
        return $this->repository->forNotifiable($notifiableId, $limit, $offset);
    }

    public function total(string $notifiableId): int
    {
        return $this->repository->countFor($notifiableId);
    }

    public function unreadCount(string $notifiableId): int
    {
        return $this->repository->unreadCount($notifiableId);
    }

    /**
     * Mark one row read, if it is that user's.
     */
    public function markRead(string $id, string $notifiableId): bool
    {
        return $this->repository->markRead($id, $notifiableId);
    }

    /**
     * Mark that user's whole inbox read.
     *
     * @return int rows marked read
     */
    public function markAllRead(string $notifiableId): int
    {
        return $this->repository->markAllAsRead($notifiableId);
    }
}
