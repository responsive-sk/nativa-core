<?php

declare(strict_types = 1);

namespace Modules\Questions\Domain\Entities;

/**
 * Question Entity.
 *
 * A reader-submitted comprehension-gap signal: the reader was stuck somewhere
 * in the text and is asking the author about it, along with their own attempted
 * answer. Questions attach to either an article or a page (discriminated by
 * subject_type + subject_slug) in a specific locale.
 *
 * Every question lands `pending` by default; nothing a guest writes is ever
 * rendered publicly until moderated. Guests carry a display name; signed-in
 * users carry author_user_id instead (guest_email is never stored).
 */
// One question row is one entity: splitting the columns into a second object
// would move the moderation state away from the text it moderates.
// @mago-expect lint:too-many-properties
final class Question
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string SUBJECT_ARTICLE = 'article';

    public const string SUBJECT_PAGE = 'page';

    private string $id;

    private string $subjectType;

    private string $subjectSlug;

    private string $locale;

    private string $text;

    private string $attemptedAnswer;

    private string $displayName;

    private ?string $authorUserId = null;

    private string $status;

    private ?string $ipAddress = null;

    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    /**
     * Create a new question (pending by default).
     *
     * @param string      $subjectType     'article' or 'page'
     * @param string      $subjectSlug     the slug, unique only within subjectType
     * @param string      $locale          the subject's locale
     * @param string      $text            the question text
     * @param string      $attemptedAnswer the reader's own attempted answer
     * @param string      $displayName     never an email — a name only
     * @param string|null $authorUserId    null for guests
     * @param string|null $ipAddress       captured for rate limiting, may be null for loopback
     */
    public static function create(
        string $subjectType,
        string $subjectSlug,
        string $locale,
        string $text,
        string $attemptedAnswer,
        string $displayName,
        ?string $authorUserId = null,
        ?string $ipAddress = null,
    ): self {
        $now = new \DateTimeImmutable();
        $instance = new self();
        $instance->id = bin2hex(random_bytes(16));
        $instance->subjectType = $subjectType;
        $instance->subjectSlug = $subjectSlug;
        $instance->locale = $locale;
        $instance->text = $text;
        $instance->attemptedAnswer = $attemptedAnswer;
        $instance->displayName = $displayName;
        $instance->authorUserId = $authorUserId;
        $instance->status = self::STATUS_PENDING;
        $instance->ipAddress = $ipAddress;
        $instance->createdAt = $now;

        return $instance;
    }

    /**
     * Hydrate from a database row.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->id = self::s($data['id'] ?? '');
        $instance->subjectType = self::s($data['subject_type'] ?? '');
        $instance->subjectSlug = self::s($data['subject_slug'] ?? '');
        $instance->locale = self::s($data['locale'] ?? '');
        $instance->text = self::s($data['text'] ?? '');
        $instance->attemptedAnswer = self::s($data['attempted_answer'] ?? '');
        $instance->displayName = self::s($data['display_name'] ?? '');
        $instance->authorUserId = self::nullable($data['author_user_id'] ?? null);
        $instance->status = self::s($data['status'] ?? self::STATUS_PENDING);
        $instance->ipAddress = self::nullable($data['ip_address'] ?? null);
        $instance->createdAt = new \DateTimeImmutable(self::s($data['created_at'] ?? 'now'));

        return $instance;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectSlug(): string
    {
        return $this->subjectSlug;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function attemptedAnswer(): string
    {
        return $this->attemptedAnswer;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function authorUserId(): ?string
    {
        return $this->authorUserId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The discriminated pair — the compound key that identifies the subject
     * this question attaches to. A slug alone is not an identity: it is unique
     * only within subject_type + locale.
     *
     * @return array{type: string, slug: string, locale: string}
     */
    public function subjectKey(): array
    {
        return [
            'type' => $this->subjectType,
            'slug' => $this->subjectSlug,
            'locale' => $this->locale,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'subject_type'     => $this->subjectType,
            'subject_slug'     => $this->subjectSlug,
            'locale'           => $this->locale,
            'text'             => $this->text,
            'attempted_answer' => $this->attemptedAnswer,
            'display_name'     => $this->displayName,
            'author_user_id'   => $this->authorUserId,
            'status'           => $this->status,
            'ip_address'       => $this->ipAddress,
            'created_at'       => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function s(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function nullable(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $string = self::s($value);

        return '' === $string ? null : $string;
    }
}
