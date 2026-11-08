<?php

declare(strict_types = 1);

namespace Modules\Questions\Application\Services;

use Modules\Core\Domain\Contracts\NavBadgeContract;
use Modules\Core\Infrastructure\Attributes\AsNavBadge;
use Modules\Questions\Application\QuestionsServices;

/**
 * QuestionsBadge — the moderation queue depth on the admin Questions item.
 *
 * The author otherwise learns a reader was stuck only by opening
 * `/mark/questions`, which is the one screen whose whole job is to be
 * surprising. This kit sends no e-mail and calls no webhook, so the number has
 * to live where the author already is: the sidebar, on every `/mark` page.
 *
 * It counts **pending** questions and nothing else. That makes it the depth
 * of a work queue: it clears when the work is done, not when somebody looked.
 * A per-user "unread" flag is deliberately not here — that is a second table,
 * a "mark as seen" action and a question about whether two moderators share
 * one read state, and none of it is needed to answer "is anything waiting".
 *
 * The count is only ever asked for an item the sidebar ALREADY decided to
 * render, which means the permission filter runs first: a session without
 * `content.manage` never sees the Questions item and is therefore never asked
 * for its count. See `AdminLayout::navLinkHtml()`.
 */
#[AsNavBadge(priority: 90)]
final readonly class QuestionsBadge implements NavBadgeContract
{
    /**
     * The admin surface this badge belongs to — the same string
     * `AdminRoutePolicy` gates behind `content.manage` and the same string a
     * seeded nav row carries. NOT the nav item's `id` column, which is a
     * generated hash: a provider keyed on `_questions` matches nothing in a
     * real install while looking right against the hardcoded fallback list.
     */
    public const string NAV_HREF = '/mark/questions';

    public function navHref(): string
    {
        return self::NAV_HREF;
    }

    public function count(): int
    {
        // The database may not exist yet — the sidebar renders on every admin
        // page, including one opened before this module has migrated. A
        // missing badge is correct; a blanked navigation is not.
        try {
            return QuestionsServices::repository()->countPending();
        } catch (\Throwable) {
            return 0;
        }
    }
}
