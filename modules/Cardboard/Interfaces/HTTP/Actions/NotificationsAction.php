<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Pagination\Pagination\OffsetPaginator;
use Modules\Notifications\Domain\Model\Notification;
use Modules\Notifications\Domain\SignedInUser;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;

/**
 * Notifications inbox — GET /mark/notifications, POST /mark/notifications/{id}/read
 * and POST /mark/notifications/read-all.
 *
 * The page lives here rather than in `modules/Notifications` because Cardboard
 * is this kit's admin app: the Users and Roles screens already live in this
 * directory, and the alternative — Notifications importing Cardboard for its
 * layout — would close a cycle, because Cardboard owns the shell and therefore
 * already reaches Users, and Users is what writes the inbox.
 *
 * Nothing here decides WHO may read an inbox: the `/mark` gate requires
 * `mark.access` for the path (see `AdminRoutePolicy`), and every query takes the
 * signed-in user id from the session. A notification id in a URL is not an
 * authorization: `markRead()` scopes on the notifiable, so another user's row
 * answers "nothing changed" rather than clearing.
 *
 * Read state changes are deliberately NOT audited. The audit log answers "who
 * did what to the site", and reading your own inbox is not one of those things —
 * a row per page view would bury the entries that matter.
 */
#[Route(path: '/mark/notifications', method: 'GET', name: 'mark.notifications')]
#[Route(path: '/mark/notifications/{id}/read', method: 'POST', name: 'mark.notifications.read', handlerMethod: 'read')]
#[Route(path: '/mark/notifications/read-all', method: 'POST', name: 'mark.notifications.read-all', handlerMethod: 'readAll')]
final class NotificationsAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->page($request));
    }

    public function read(Request $request): Response
    {
        $userId = SignedInUser::id();
        $id = $request->routeParam('id');

        if (null !== $userId && null !== $id && '' !== $id) {
            $this->markRead($id, $userId);
        }

        return Response::redirect($this->url('mark.notifications'));
    }

    public function readAll(Request $request): Response
    {
        $userId = SignedInUser::id();

        if (null !== $userId) {
            $this->markAllRead($userId);
        }

        return Response::redirect($this->url('mark.notifications'));
    }

    /**
     * Clear one row, and never answer 500 when the database cannot be asked.
     *
     * The page already renders an unmigrated inbox as an empty state instead of
     * an error; a form submitted from a page rendered before a reset — or from
     * one whose `notifications.db` went away between render and submit — has to
     * agree with it. The redirect happens either way: the row is either read or
     * still waiting, and the next render says which.
     */
    private function markRead(string $id, string $userId): void
    {
        try {
            NotificationsServices::inbox()->markRead($id, $userId);
        } catch (\Throwable $e) {
            error_log('[notifications] could not mark a row read: ' . $e->getMessage());
        }
    }

    /**
     * Clear that user's whole inbox, with the same tolerance as one row.
     */
    private function markAllRead(string $userId): void
    {
        try {
            NotificationsServices::inbox()->markAllRead($userId);
        } catch (\Throwable $e) {
            error_log('[notifications] could not mark the inbox read: ' . $e->getMessage());
        }
    }

    private function page(Request $request): string
    {
        $userId = SignedInUser::id();

        if (null === $userId) {
            // Behind AuthMiddleware this cannot happen; rendering an empty inbox
            // beats throwing on the one page whose job is to reassure.
            return AdminLayout::render(
                'Notifications',
                AdminLayout::emptyState('Nothing to show', 'Sign in to see your inbox.'),
                'notifications',
                router: $this->router(),
            );
        }

        // Rows per page. The list is the operator's recent history, not an
        // archive: twenty is more than a working day of moderation here and
        // keeps the page a page. A local number rather than a constant, so the
        // declaration ratchet does not have to grow for a page size.
        $pageSize = 20;

        try {
            $inbox = NotificationsServices::inbox();

            // Two phases, because `?page=` is not a range: the paginator is
            // built from the TOTAL first, so `offset()` comes from the clamped
            // page and `?page=99999` reads the last page instead of an empty
            // table.
            $paginator = new OffsetPaginator([], $inbox->total($userId), $pageSize, $this->requestedPage($request));
            $page = $paginator->withItems($inbox->recent($userId, $pageSize, $paginator->offset()));
            $unread = $inbox->unreadCount($userId);
        } catch (\Throwable $e) {
            error_log('[notifications] inbox unavailable: ' . $e->getMessage());

            return AdminLayout::render(
                'Notifications',
                AdminLayout::emptyState(
                    'Inbox unavailable',
                    'The notifications database has not been created yet — run bin/cms migrate.',
                ),
                'notifications',
                router: $this->router(),
            );
        }

        /** @var list<Notification> $notifications */
        $notifications = $page->items();

        $base = $this->url('mark.notifications');
        // Page one is the bare list, every other page adds the parameter — the
        // shape the panel's own links use. The KEY is spelled here, not in the
        // paginator: it is this surface's word for the address, and an API
        // paging the same class spells its own.
        $view = $page->toArray(static fn (string $target): string => '1' === $target
            ? $base
            : $base . '?page=' . $target);

        $content = $this->summary($unread, $page->total()) . $this->listHtml($notifications);

        if ($page->lastPage() > 1) {
            $content .= AdminLayout::pager($view['meta'], $view['links']);
        }

        return AdminLayout::render(
            'Notifications',
            $content,
            'notifications',
            router: $this->router(),
        );
    }

    /**
     * The requested page number, or the first page when the query says nothing
     * usable. The paginator clamps it either way; this only refuses to hand a
     * non-number to `(int)`.
     */
    private function requestedPage(Request $request): int
    {
        $page = $request->query('page');

        return is_numeric($page) ? (int) $page : 1;
    }

    private function summary(int $unread, int $total): string
    {
        $summary = '<p class="m-muted">' . $unread . ' unread · ' . $total . ' total</p>';

        if (0 === $unread) {
            return $summary;
        }

        return '<div class="m-card"><form class="m-form" method="POST" action="'
            . AdminLayout::e($this->url('mark.notifications.read-all')) . '">'
            . AdminLayout::csrfField()
            . '<button class="m-btn m-btn-primary" type="submit">Mark all as read</button>'
            . '</form></div>' . $summary;
    }

    /**
     * @param list<Notification> $notifications
     */
    private function listHtml(array $notifications): string
    {
        if ([] === $notifications) {
            return AdminLayout::emptyState(
                'No notifications',
                'Moderation alerts land here — a comment or a question that waits for you.',
            );
        }

        $rows = '';

        foreach ($notifications as $notification) {
            $rows .= $this->row($notification);
        }

        return '<table class="m-table"><thead><tr>'
            . '<th>Notification</th><th>When</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function row(Notification $notification): string
    {
        $body = '' === $notification->body()
            ? ''
            : '<div class="m-muted">' . nl2br(AdminLayout::e($notification->body()), false) . '</div>';

        $href = $notification->href();
        $open = '' === $href
            ? ''
            : ' <a class="m-btn m-btn-sm m-btn-ghost" href="' . AdminLayout::e($href) . '">Open</a>';

        $unreadBadge = $notification->isRead()
            ? ''
            : ' <span class="m-badge m-badge-success">unread</span>';

        $markRead = $notification->isRead()
            ? ''
            : '<form class="m-inline-form" method="POST" action="'
                . AdminLayout::e($this->url('mark.notifications.read', ['id' => $notification->id()])) . '">'
                . AdminLayout::csrfField()
                . '<button class="m-btn m-btn-ghost m-btn-sm" type="submit">Mark read</button>'
                . '</form>';

        return '<tr>'
            . '<td><strong>' . AdminLayout::e($notification->title()) . '</strong>' . $unreadBadge
            . '<div class="m-mono m-muted">' . AdminLayout::e($notification->kind()) . '</div>'
            . $body . '</td>'
            . '<td class="m-mono">' . AdminLayout::relativeTime($notification->createdAt()) . '</td>'
            . '<td>' . $open . $markRead . '</td>'
            . '</tr>';
    }
}
