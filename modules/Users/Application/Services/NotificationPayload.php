<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

/**
 * NotificationPayload — the shape of a `notify.admin` job, built by its
 * producer.
 *
 * Pure arrays, no queue, no database: the producers push through
 * `QueueServices` themselves (Comments and Questions already depend on this
 * module, and this module must not depend on Queue — see
 * `AdminNotificationHandler`'s docblock for the cycle that would create). What
 * both producers must agree on is the payload, and that is this class.
 *
 * ## The payload is a SNAPSHOT, unlike `Blog\CoverJobs`' two identifiers
 *
 * A cover is a derived file that must reflect the article as it is WHEN THE JOB
 * RUNS; a notification is about what happened at ONE MOMENT, and re-reading the
 * source later would describe a different event — or, for a comment an admin
 * already rejected in the meantime, no event at all. So the excerpt travels.
 *
 * What still does not travel is anything a queue row should never hold: no guest
 * email address (the panel link is where the admin sees it), no token, no
 * article body. The excerpt is capped here because the row is written to disk
 * and rendered at `/mark/queue/{id}` — the cap is a storage and a display
 * decision in one.
 *
 * `panel` is the producer's own moderation surface, passed in rather than
 * spelled here: Questions owns its path as `QuestionsBadge::NAV_HREF`, and this
 * class must not become a second owner of it.
 */
final class NotificationPayload
{
    /**
     * Characters of user text that travel to the job. The handler trims to its
     * own (smaller) limit for the mail; this one bounds the ROW.
     */
    private const int EXCERPT_LIMIT = 500;

    /**
     * @param string      $slug   the article the comment belongs to
     * @param string|null $author display name (account name or the guest name)
     * @param string      $body   the comment text as submitted
     * @param string      $panel  the commenter's own moderation surface
     *
     * @return array{kind: string, target: string, author: string, excerpt: string, panel: string}
     */
    public static function comment(string $slug, ?string $author, string $body, string $panel): array
    {
        return [
            'kind'    => 'comment',
            'target'  => $slug,
            'author'  => (string) $author,
            'excerpt' => self::clamp($body),
            'panel'   => $panel,
        ];
    }

    /**
     * @param string      $subjectType `article` or `page`, the question's own vocabulary
     * @param string      $subjectSlug the subject it was asked on
     * @param string|null $author      display name (the module never stores an email)
     * @param string      $text        the question text
     * @param string      $panel       the moderation surface the producer owns
     *
     * @return array{kind: string, target: string, author: string, excerpt: string, panel: string}
     */
    public static function question(
        string $subjectType,
        string $subjectSlug,
        ?string $author,
        string $text,
        string $panel,
    ): array {
        return [
            'kind'    => 'question',
            'target'  => $subjectType . '/' . $subjectSlug,
            'author'  => (string) $author,
            'excerpt' => self::clamp($text),
            'panel'   => $panel,
        ];
    }

    private static function clamp(string $text): string
    {
        return mb_strlen($text) <= self::EXCERPT_LIMIT
            ? $text
            : mb_substr($text, 0, self::EXCERPT_LIMIT);
    }
}
