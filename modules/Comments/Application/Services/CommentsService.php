<?php

declare(strict_types = 1);

namespace Modules\Comments\Application\Services;

use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Infrastructure\Persistence\Repositories\CommentRepository;
use Modules\Core\Domain\SubmitResult;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Security\Domain\LocalClient;
use Modules\Validation\Validation\Validator;

/**
 * CommentsService — submit, list and moderate article comments.
 *
 * Submission is CSRF-protected at the middleware level (CsrfMiddleware) and
 * rate-limited per IP+slug here (loopback exempt so local dev is not
 * throttled). New comments always start as 'pending'; only approved comments
 * render publicly.
 */
final readonly class CommentsService
{
    private const int MAX_BODY_LENGTH = 4000;

    private const int MAX_NAME_LENGTH = 120;

    private const int MAX_EMAIL_LENGTH = 255;

    private const int RATE_LIMIT = 10;

    private const int RATE_WINDOW = 60;

    public function __construct(
        private CommentRepository $comments,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Submit a comment (pending by default).
     *
     * The answer is a `SubmitResult`, not `array{ok, errors}`: `ok` and the
     * reason are built together, so a caller can no longer receive a success
     * carrying an error (or a refusal with nothing to show the reader).
     *
     * @return SubmitResult<array{}>
     */
    public function submit(
        string $slug,
        ?string $userId,
        ?string $userName,
        ?string $guestName,
        ?string $guestEmail,
        string $body,
        string $ip,
    ): SubmitResult {
        $slug = trim($slug);
        $body = trim($body);
        $guestName = null === $guestName ? null : trim($guestName);
        $guestEmail = null === $guestEmail ? null : trim($guestEmail);

        // Rate limit first — invalid payloads must not bypass throttling.
        if (!$this->isAllowed($slug, $ip)) {
            return SubmitResult::failure('Too many comments — try again in a minute.');
        }

        $errors = $this->validate($slug, $userId, $guestName, $guestEmail, $body);

        if ([] !== $errors) {
            return SubmitResult::failure($errors);
        }

        // Logged-in users carry their account name as the display name.
        $displayName = null !== $userId && null !== $userName ? $userName : $guestName;

        $this->comments->insert(Comment::create(
            $slug,
            $userId,
            $displayName,
            null !== $userId ? null : $guestEmail,
            $body,
            $this->loopback($ip) ? null : $ip,
        ));

        return SubmitResult::success();
    }

    /**
     * Approved comments for an article, oldest first.
     *
     * @return list<Comment>
     */
    public function approved(string $slug): array
    {
        return $this->comments->findByArticle($slug);
    }

    /**
     * Pending comments (admin moderation queue).
     *
     * @return list<Comment>
     */
    public function pending(): array
    {
        return $this->comments->findByStatus(Comment::STATUS_PENDING);
    }

    /**
     * All comments, pending first (admin moderation).
     *
     * @return list<Comment>
     */
    public function all(): array
    {
        return $this->comments->all();
    }

    public function approve(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->setStatus($id, Comment::STATUS_APPROVED);

        return true;
    }

    public function reject(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->setStatus($id, Comment::STATUS_REJECTED);

        return true;
    }

    public function delete(string $id): bool
    {
        if (null === $this->comments->findById($id)) {
            return false;
        }

        $this->comments->delete($id);

        return true;
    }

    /**
     * @return list<string>
     */
    private function validate(string $slug, ?string $userId, ?string $guestName, ?string $guestEmail, string $body): array
    {
        // Inputs arrive trimmed by submit(), so `required` matches the old
        // `=== ''`/`=== null` checks exactly. One deliberate edge: an
        // invalid AND over-long guest email reports both lines (the old
        // elseif reported only the validity one) — the validator has no
        // bail, and an invalid 255+ char email is not a real input.
        $rules = [
            'slug' => 'required|regex:/^[a-z0-9][a-z0-9_-]*$/i',
            'body' => 'required|max:' . self::MAX_BODY_LENGTH,
        ];

        if (null === $userId) {
            // Guests must provide a name + valid email.
            $rules['guest_name'] = 'required|max:' . self::MAX_NAME_LENGTH;
            $rules['guest_email'] = 'required|email|max:' . self::MAX_EMAIL_LENGTH;
        }

        return (new Validator())->validate(
            ['slug' => $slug, 'body' => $body, 'guest_name' => $guestName, 'guest_email' => $guestEmail],
            $rules,
            [
                'slug.required'       => 'Invalid article.',
                'slug.regex'          => 'Invalid article.',
                'body.required'       => 'Comment body is required.',
                'body.max'            => 'Comment is too long (max ' . self::MAX_BODY_LENGTH . ' characters).',
                'guest_name.required' => 'Name is required.',
                'guest_name.max'      => 'Name is too long (max ' . self::MAX_NAME_LENGTH . ' characters).',
                'guest_email.required' => 'A valid email address is required.',
                'guest_email.email'    => 'A valid email address is required.',
                'guest_email.max'      => 'Email is too long (max ' . self::MAX_EMAIL_LENGTH . ' characters).',
            ],
        )->all();
    }

    /**
     * Rate limit per IP+slug (loopback exempt).
     */
    private function isAllowed(string $slug, string $ip): bool
    {
        if ($this->loopback($ip)) {
            return true;
        }

        return $this->limiter->isAllowed(
            'comment:' . $slug . ':' . $ip,
            self::RATE_LIMIT,
            self::RATE_WINDOW,
        );
    }

    /**
     * The one owner of "the local developer is not a spammer": the same class
     * the registration and reset-request limits ask, so the tree cannot end up
     * with three lists that disagree about what "local" means.
     */
    private function loopback(string $ip): bool
    {
        return LocalClient::isLoopback($ip);
    }
}
