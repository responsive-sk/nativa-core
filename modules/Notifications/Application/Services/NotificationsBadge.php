<?php

declare(strict_types = 1);

namespace Modules\Notifications\Application\Services;

use Modules\Core\Domain\Contracts\NavBadgeContract;
use Modules\Core\Infrastructure\Attributes\AsNavBadge;
use Modules\Notifications\Domain\SignedInUser;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;

/**
 * NotificationsBadge — the unread count on the admin Notifications item.
 *
 * The inbox exists so an operator finds out that a reader is waiting without
 * opening every moderation screen; a number on the sidebar is the half that
 * makes that true. It counts THIS user's unread rows and nobody else's — the
 * read state is per recipient, so two moderators see their own depth even though
 * the event was one comment.
 *
 * Like `QuestionsBadge` the count is asked only for an item the sidebar already
 * decided to render, so the permission filter has run first: a session without
 * `mark.access` never sees the item and is never asked for its rows.
 *
 * Unread rows in another user's inbox are invisible here by construction, not by
 * filtering: `unreadCount()` takes the notifiable as an argument.
 *
 * The database may not exist yet — the sidebar renders on every admin page,
 * including one opened before this module has migrated — so a failure answers
 * zero. A missing badge is correct; a blanked navigation is not.
 */
#[AsNavBadge(priority: 95)]
final readonly class NotificationsBadge implements NavBadgeContract
{
    /**
     * The admin surface this badge belongs to — the same string the seeded nav
     * row carries and the same string `AdminRoutePolicy` gates. Deliberately the
     * HREF and not the nav item's `id` column, which is a generated hash: a
     * provider keyed on `_notifications` matches nothing in a real install while
     * looking right against the hardcoded fallback list.
     */
    public function navHref(): string
    {
        return '/mark/notifications';
    }

    public function count(): int
    {
        $userId = SignedInUser::id();

        if (null === $userId) {
            return 0;
        }

        try {
            return NotificationsServices::inbox()->unreadCount($userId);
        } catch (\Throwable) {
            return 0;
        }
    }
}
