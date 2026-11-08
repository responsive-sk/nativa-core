<?php

declare(strict_types = 1);

namespace Modules\Comments\Interfaces\HTTP\Actions;

use Modules\Blog\Interfaces\HTTP\Actions\BlogArticleAction;
use Modules\Comments\Interfaces\HTTP\CommentsServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Queue\Interfaces\HTTP\QueueServices;
use Modules\Users\Application\Services\AdminNotificationHandler;
use Modules\Users\Application\Services\NotificationPayload;

/**
 * Comment submit action — POST /blog/{slug}/comments.
 *
 * CSRF is enforced globally by CsrfMiddleware (the form carries its field).
 * Guest comments need guest_name + guest_email; signed-in users are
 * attributed from the session. New comments are always 'pending' — they only
 * become visible after an admin approves them in /mark/comments.
 *
 * Two bot defenses sit in front of the write, the same pair the other public
 * form in this tree (`QuestionSubmitAction`) carries:
 *
 *  - a HONEYPOT field (`comment_website`), filled by bots that complete every
 *    field they find and silently discarded here;
 *  - rate limiting, in `CommentsService` — per IP+slug, applied before
 *    validation so invalid payloads cannot bypass throttling.
 *
 * The honeypot is a cheap filter for naive crawlers, not a boundary: the
 * limiter is what bounds the moderation queue.
 *
 * PRG flow: on success redirect back to the article (#comments); on
 * validation failure re-render the article page with the form errors inline
 * (the CommentSection form keeps the old input).
 */
#[Route(path: '/blog/{slug}/comments', method: 'POST', name: 'blog.comments.submit')]
final class CommentSubmitAction
{
    /**
     * Honeypot field name — rendered hidden in `CommentSection` (`hidden`,
     * `tabindex="-1"`, no autocomplete), so a human never sees it and a bot
     * that fills every field hands itself in. Same field shape and the same
     * silent discard as `QuestionSubmitAction`, so the tree's two public forms
     * cannot drift apart.
     */
    private const string HONEYPOT_FIELD = 'comment_website';

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            return Response::redirect('/blog');
        }

        // Honeypot — checked BEFORE any lookup or write, and answered with the
        // very redirect a real submission gets: a bot cannot tell which field
        // gave it away, and nothing reaches the moderation queue. A blank (or
        // absent) field is the human case and falls through unchanged.
        if (null !== $this->stringOrNull($request->body(self::HONEYPOT_FIELD))) {
            return Response::redirect('/blog/' . $slug . '#comments');
        }

        $user = CommentsServices::auth()->currentUser();
        $userId = null === $user ? null : $user->id();
        $userName = null === $user ? null : $user->name();

        $guestName = $this->stringOrNull($request->body('guest_name'));
        $guestEmail = $this->stringOrNull($request->body('guest_email'));
        $body = $this->stringOrNull($request->body('body')) ?? '';

        $result = CommentsServices::comments()->submit(
            $slug,
            $userId,
            $userName,
            $guestName,
            $guestEmail,
            $body,
            $request->getClientIp(),
        );

        if ($result->ok) {
            $this->notifyAdmin($slug, $userId !== null ? $userName : $guestName, $body);

            return Response::redirect('/blog/' . $slug . '#comments');
        }

        // Re-render the article with errors inline — the errors and old
        // input are passed through a request attribute to BlogArticleAction.
        $request->setPathInfo('/blog/' . $slug);
        $request->setAttribute('comment_errors', $result->errors);
        $request->setAttribute('comment_old', [
            'guest_name'  => $guestName ?? '',
            'guest_email' => $guestEmail ?? '',
            'body'        => $body,
        ]);

        return (new BlogArticleAction())->handle($request);
    }

    /**
     * Queue the operator notification for a comment that was just stored.
     *
     * The comment is `pending` — moderation is the operator's next move, so
     * tell them now rather than on their next visit to /mark. Queued, not sent
     * here: a reader's submission must not wait for a mail transport, and the
     * panel runs the queue on demand (there is no cron on the deployments this
     * tree targets).
     *
     * Contained on purpose — a stored comment must not become an error page
     * because the notification could not be queued, so the log names what
     * happened and the reader sees the normal confirmation.
     */
    private function notifyAdmin(string $slug, ?string $author, string $body): void
    {
        try {
            QueueServices::queue()->push(
                AdminNotificationHandler::HANDLER,
                NotificationPayload::comment($slug, $author, $body, '/mark/comments'),
                // One attempt: a retried notification is a second email for a
                // comment the admin may already have moderated.
                maxAttempts: 1,
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[comments] could not queue the admin notification for "%s": %s',
                $slug,
                $e->getMessage(),
            ));
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
