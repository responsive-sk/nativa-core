<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Notifications\Application\Services\NotificationInbox;
use Modules\Users\Domain\Entities\User;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * AdminNotificationHandler — the consumer behind "tell the operator".
 *
 * One handler, not one per module: the payload carries which KIND of event it
 * is, so Comments and Questions push a job name they do not own and the wording
 * of the mail lives in exactly one place. A third event is a `match` arm, not a
 * second handler and a second registration.
 *
 * **Two channels, one wording.** The handler writes a durable inbox row for
 * every user who can open the panel and THEN sends the mail. The order is
 * deliberate — the mail is best-effort (no configured address, no transport, a
 * bounce nobody reads), while the inbox row is the record that survives all
 * three. An inbox write that fails is logged and never stops the mail, because
 * the operator losing the notification itself is the worse of the two failures.
 *
 * Recipients are this module's question, not Notifications': it owns users,
 * roles and the permission vocabulary, so it asks "who can open the panel"
 * (`User::canAccessPanel()`) and hands the ids over. That is also why
 * Notifications is allowed as a dependency here while it must not reach back:
 * Notifications imports Core and Database only, so this edge and Cardboard's
 * (which renders the inbox page) form a DAG.
 *
 * **It deliberately does not import Queue.** The natural shape — a static
 * `register(HandlerRegistry)` like `Blog\CoverRenderHandler` — would make Users
 * depend on Queue, and Queue already reaches Users through Audit
 * (`QueueAdminAction` writes an audit row; `Audit\AuditServices` authenticates
 * through this module). That is a cycle, and `ModuleCatalogueShapeTest` reports
 * it as "these modules can never be installed". Registration therefore lives
 * where every other queue pairing lives: `wire_queue_handlers()` in
 * `src/composition.php`, which names both sides inline and is the one list every
 * DELIVERY process wires.
 *
 * The handler returns a boolean; the worker discards it. That is deliberate: a
 * notification that could not be delivered is NOT retried, because a duplicate
 * email to an operator is worse than a missing one that is parked in
 * `failed_jobs` with its error. The producers push with `maxAttempts: 1` for
 * the same reason.
 */
final readonly class AdminNotificationHandler
{
    /**
     * The name producers push and the registry looks up.
     */
    public const string HANDLER = 'notify.admin';

    /**
     * Characters of user text in the message. Long enough to recognise the
     * comment, short enough that a pasted code block does not turn the mail
     * into the page it was copied from — the panel link is the full text.
     */
    private const int EXCERPT_LIMIT = 400;

    /**
     * The inbox is optional so a tree without the Notifications slice keeps
     * sending mail exactly as before, and so the mail tests can construct this
     * object without a database.
     */
    public function __construct(
        private AdminNotifier $notifier,
        private ?NotificationInbox $inbox = null,
    ) {
    }

    /**
     * @param array<string, mixed> $args the payload `NotificationPayload` built
     *
     * @return bool true when the transport accepted the message
     */
    public function handle(array $args): bool
    {
        $kind = is_string($args['kind'] ?? null) ? $args['kind'] : '';
        $author = $this->oneLine((string) ($args['author'] ?? ''), 'a reader');
        $target = $this->oneLine((string) ($args['target'] ?? ''), 'unknown');
        $excerpt = $this->excerpt((string) ($args['excerpt'] ?? ''));

        // The panel path travels in the payload instead of being spelled here.
        // A producer already owns its own moderation surface — Questions has
        // `QuestionsBadge::NAV_HREF` — and a second spelling in this class would
        // be a second owner for the same route, invisible to the module that
        // changes it.
        $panel = is_string($args['panel'] ?? null) ? $args['panel'] : '';
        $moderate = '' === $panel ? '' : "\n\n" . 'Moderate: ' . $this->notifier->link($panel);

        [$subject, $body] = match ($kind) {
            'comment' => [
                'New comment awaiting moderation',
                "A reader posted a comment that is waiting for you.\n\n"
                . "Article: {$target}\n"
                . "From: {$author}\n\n"
                . $excerpt,
            ],
            'question' => [
                'New question awaiting moderation',
                "A reader asked a question that is waiting for you.\n\n"
                . "About: {$target}\n"
                . "From: {$author}\n\n"
                . $excerpt,
            ],
            default => [null, null],
        };

        if (null === $subject) {
            return $this->unknown($kind);
        }

        // Before the mail: the row is the durable half of this notification.
        $this->record($kind, $subject, $body, $panel);

        return $this->notifier->send($subject, $body . $moderate);
    }

    /**
     * Write one inbox row per panel user, and never fail the mail over it.
     *
     * The wording handed over is the MAIL's wording minus the absolute
     * "Moderate:" link: an inbox row carries the root-relative panel path it
     * was given (`href`), so the panel builds the link at render time and a
     * deployment that moves `APP_URL` does not leave stale absolute URLs in
     * every inbox.
     */
    private function record(string $kind, string $subject, string $body, string $panel): void
    {
        if (null === $this->inbox) {
            return;
        }

        $recipients = self::panelUserIds();

        if ([] === $recipients) {
            return;
        }

        try {
            $this->inbox->push($recipients, $kind, $subject, $body, $panel);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[notifications] could not write the inbox row for "%s": %s',
                $subject,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Ids of the accounts that can open the panel.
     *
     * A missing or unmigrated users table answers `[]` — nobody to address, in a
     * tree where the panel does not exist either. That is logged rather than
     * thrown for the same reason as above: it must not cost the mail.
     *
     * @return list<string>
     */
    private static function panelUserIds(): array
    {
        try {
            $users = new UserRepository(
                DatabaseConnectionManager::instance()->connect('users'),
            );

            $ids = [];

            foreach ($users->all() as $user) {
                if (!$user->isActive() || !$user->canAccessPanel()) {
                    continue;
                }

                $ids[] = $user->id();
            }

            return $ids;
        } catch (\Throwable $e) {
            error_log('[notifications] could not resolve the panel recipients: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * A payload naming a kind this version does not know is a producer and a
     * consumer from different deployments — logged by name, never a fatal, and
     * never an email with a half-built body.
     */
    private function unknown(string $kind): bool
    {
        error_log(sprintf(
            '[notifications] unknown notification kind "%s" — nothing was sent (is the consumer older than its producer?)',
            '' === $kind ? '(empty)' : $kind,
        ));

        return false;
    }

    /**
     * User text arrives with the line breaks a textarea produced; the mail is a
     * paragraph, so runs of whitespace collapse to one space.
     */
    private function oneLine(string $value, string $fallback): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $collapsed ? $fallback : $collapsed;
    }

    private function excerpt(string $text): string
    {
        $collapsed = $this->oneLine($text, '(no text)');

        if (mb_strlen($collapsed) <= self::EXCERPT_LIMIT) {
            return $collapsed;
        }

        return mb_substr($collapsed, 0, self::EXCERPT_LIMIT - 1) . '…';
    }
}
