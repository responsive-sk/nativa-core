<?php

declare(strict_types = 1);

namespace Modules\Questions\Interfaces\HTTP\Actions;

use Modules\Blog\Interfaces\HTTP\Actions\BlogArticleAction;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Queue\Interfaces\HTTP\QueueServices;
use Modules\Users\Application\Services\AdminNotificationHandler;
use Modules\Users\Application\Services\NotificationPayload;
use Modules\Pages\Interfaces\HTTP\Actions\PageViewAction;
use Modules\Questions\Application\QuestionsServices;
use Modules\Questions\Application\Services\QuestionsBadge;
use Modules\Questions\Domain\Entities\Question;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Validation\Validation\Validator;

/**
 * QuestionSubmitAction — POST /questions/{subjectType}/{subjectSlug}.
 *
 * CSRF is enforced globally by CsrfMiddleware (the form carries its field).
 * Per-IP rate limiting is applied before any DB write via RateLimiter.
 * A honeypot field (question_website) silently discards bot submissions.
 *
 * Security posture:
 *  - Every question starts `pending` — nothing is public until moderated.
 *  - Email is never stored; only a display name.
 *  - Signed-in users are attributed via author_user_id; guests use display_name.
 *  - `status` is NEVER read from the request: the entity decides it, and the
 *    entity decides `pending`. Of the POST body, only the two text fields and
 *    the display name are data.
 *
 * PRG flow:
 *  - Success → redirect to the subject with the `question-sent` marker, which
 *    the block paints as a confirmation (a redirect nobody renders is a
 *    silent no-op) and which `PageCacheService::BYPASS_QUERY_PARAMS` keeps
 *    out of the shared cache, because that HTML is session-scoped.
 *  - Refusals (rate-limited, unknown subject) carry NO Location header —
 *    `Response::send()` rewrites ANY 302 carrying one, so a refusal that
 *    also redirects leaves the machine as a redirect with its body discarded.
 *
 * The subjectType/slug are path params and therefore untrusted, so the pair is
 * re-resolved against real, PUBLISHED content (`SubjectResolver`) before
 * anything is written: a forged pair would file a question against an article
 * that does not exist, or against a draft no reader can see.
 */
#[Route(path: '/questions/{subjectType}/{subjectSlug}', method: 'POST', name: 'questions.submit')]
final class QuestionSubmitAction
{
    /** Maximum questions per IP per window. */
    private const int RATE_LIMIT = 5;

    /** Rate-limit window in seconds (5 minutes). */
    private const int RATE_WINDOW = 300;

    public function handle(Request $request): Response
    {
        $subjectType = (string) ($request->routeParam('subjectType') ?? '');
        $subjectSlug = (string) ($request->routeParam('subjectSlug') ?? '');

        // Untrusted path params: the subject type is matched against the two
        // known constants, never interpolated into a query.
        if (!\in_array($subjectType, [Question::SUBJECT_ARTICLE, Question::SUBJECT_PAGE], true)) {
            return $this->badRequest('Invalid subject type.');
        }

        if (!self::validSlug($subjectSlug)) {
            return $this->badRequest('Invalid subject slug.');
        }

        // Resolved early — every redirect below must be locale-prefixed.
        $locale = $this->locale($request);

        // Honeypot: a filled-in question_website is a bot, silently discarded.
        // Checked BEFORE the subject lookup so a bot cannot probe which slugs
        // exist by timing the difference between a 404 and an error page.
        if (null !== $this->stringOrNull($request->body('question_website'))) {
            return $this->redirectToSubject($subjectType, $subjectSlug, $locale);
        }

        if (!QuestionsServices::subjects()->isPublished($subjectType, $subjectSlug, $locale)) {
            // The same answer the public surfaces give for the same subject,
            // so asking cannot tell an author which articles exist.
            return $this->refusal('Not found', 'There is nothing published at that address.', 404);
        }

        $ip = $request->getClientIp();

        if (!QuestionsServices::limiter()->isAllowed('question:' . $ip, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return $this->refusal('Too many questions', 'Please wait a few minutes before asking again.', 429);
        }

        $errors = $this->errors($request);

        if ([] !== $errors) {
            return $this->reRenderWithErrors($request, $subjectType, $subjectSlug, $locale, $errors);
        }

        $this->store($request, $subjectType, $subjectSlug, $locale, $ip);
        $this->notifyAdmin($request, $subjectType, $subjectSlug);

        return $this->redirectToSubject($subjectType, $subjectSlug, $locale);
    }

    /**
     * The validation errors for this POST, in the order the fields appear in
     * the form. An empty list means the submission may be stored.
     *
     * @return list<string>
     */
    private function errors(Request $request): array
    {
        // stringOrNull() trims and maps blanks to null, so `required` matches
        // the old null checks exactly — same messages, same field order.
        return (new Validator())->validate(
            [
                'display_name'     => $this->stringOrNull($request->body('display_name')),
                'text'             => $this->stringOrNull($request->body('text')),
                'attempted_answer' => $this->stringOrNull($request->body('attempted_answer')),
            ],
            [
                'display_name'     => 'required',
                'text'             => 'required',
                'attempted_answer' => 'required',
            ],
            [
                'display_name.required'     => 'Please enter your name.',
                'text.required'             => 'Please enter your question.',
                'attempted_answer.required' => 'Please describe what you tried.',
            ],
        )->all();
    }

    /**
     * Persist the submission as `pending`.
     *
     * The text is stored raw and escaped at render time, exactly like a
     * comment body: escaping on the way in would mangle a question that
     * legitimately contains an apostrophe, and storing it escaped is what
     * makes a second escape a double-escape on the page.
     */
    private function store(Request $request, string $subjectType, string $subjectSlug, string $locale, string $ip): void
    {
        $user = AuthServices::auth()->currentUser();

        QuestionsServices::repository()->insert(Question::create(
            $subjectType,
            $subjectSlug,
            $locale,
            (string) $this->stringOrNull($request->body('text')),
            (string) $this->stringOrNull($request->body('attempted_answer')),
            (string) $this->stringOrNull($request->body('display_name')),
            null === $user ? null : $user->id(),
            $ip,
        ));
    }

    /**
     * Queue the operator notification for a question that was just stored.
     *
     * Queued, not sent here: the reader gets the confirmation page without
     * waiting for a mail transport, and the panel drains the queue on demand.
     * Contained on purpose — a stored question must not become an error page
     * because the notification could not be queued.
     */
    private function notifyAdmin(Request $request, string $subjectType, string $subjectSlug): void
    {
        try {
            QueueServices::queue()->push(
                AdminNotificationHandler::HANDLER,
                NotificationPayload::question(
                    $subjectType,
                    $subjectSlug,
                    $this->stringOrNull($request->body('display_name')),
                    (string) $this->stringOrNull($request->body('text')),
                    QuestionsBadge::NAV_HREF,
                ),
                // One attempt: a retried notification is a second email for a
                // question the admin may already have answered.
                maxAttempts: 1,
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[questions] could not queue the admin notification for "%s/%s": %s',
                $subjectType,
                $subjectSlug,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Redirect to the subject with the question-sent marker.
     *
     * For articles: {localePrefix}/blog/{slug}?question-sent=1#questions
     * For pages:    {localePrefix}/{slug}?question-sent=1#questions
     */
    private function redirectToSubject(string $subjectType, string $subjectSlug, string $locale): Response
    {
        $path = Question::SUBJECT_ARTICLE === $subjectType
            ? '/blog/' . rawurlencode($subjectSlug)
            : '/' . rawurlencode($subjectSlug);

        return Response::redirect(
            ContentServices::mapper()->urlPrefix($locale) . $path . '?question-sent=1#questions',
        );
    }

    /**
     * On validation failure, re-render the subject page with the errors and
     * the old input, so the reader's own words survive the round trip.
     *
     * This RE-RENDERS rather than redirects — it dispatches to the subject's
     * own action, the same hand-off `CommentSubmitAction` makes, so there is
     * one rendering of an article and one of a page and the block lands in the
     * right place for the active theme.
     *
     * @param list<string> $errors
     */
    private function reRenderWithErrors(
        Request $request,
        string $subjectType,
        string $subjectSlug,
        string $locale,
        array $errors,
    ): Response {
        $request->setAttribute('question_errors', $errors);
        $request->setAttribute('question_old', [
            'display_name'     => $this->stringOrNull($request->body('display_name')) ?? '',
            'text'             => $this->stringOrNull($request->body('text')) ?? '',
            'attempted_answer' => $this->stringOrNull($request->body('attempted_answer')) ?? '',
        ]);

        // The destination action reads routeParam('slug') and getPathInfo();
        // this route provides 'subjectSlug', so both are set here. The path is
        // locale-stripped — the destination rebuilds the canonical URL, which
        // carries the locale prefix.
        $request->setRouteParams(['slug' => $subjectSlug]);
        $request->setPathInfo(Question::SUBJECT_ARTICLE === $subjectType
            ? '/blog/' . rawurlencode($subjectSlug)
            : '/' . rawurlencode($subjectSlug));

        return Question::SUBJECT_ARTICLE === $subjectType
            ? (new BlogArticleAction())->handle($request)
            : (new PageViewAction())->handle($request);
    }

    /**
     * A bare refusal page — no Location header, deliberately: a refusal that
     * also redirects passes every status assertion and leaves the browser on
     * the unchanged page with the refusal discarded.
     */
    private function refusal(string $title, string $message, int $status): Response
    {
        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>' . $title . '</title></head>'
            . '<body><h1>' . $title . '</h1><p>' . $message . '</p></body></html>',
            $status,
        );
    }

    private function badRequest(string $message): Response
    {
        return $this->refusal('Bad request', $message, 400);
    }

    /**
     * The canonical locale code (`sk_SK`) the subject lives in — the request
     * locale resolved through the mapper, never a hardcoded language.
     */
    private function locale(Request $request): string
    {
        $locale = $request->getAttribute('locale');

        return ContentServices::mapper()->code(is_string($locale) ? $locale : '');
    }

    private static function validSlug(string $slug): bool
    {
        return '' !== $slug && 1 === preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug);
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
