<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Services;

use Modules\Core\Domain\Contracts\NavBadgeContract;
use Modules\Core\Infrastructure\Attributes\AsNavBadge;
use Modules\Forms\Application\FormsServices;

/**
 * FormsBadge — the submission queue depth on the admin Forms item.
 *
 * The nav item, the permission and the seeded nav row all already point at
 * `/mark/forms`; this provider is what puts a number beside them.
 *
 * It counts `status = 'new'` and nothing else, so it is the depth of a work
 * queue: marking a submission handled is what clears it, not opening the
 * inbox. A per-admin "seen" flag would be a second table and a second
 * question, and none of it answers "is anything waiting".
 *
 * A missing database answers 0 instead of throwing: the sidebar renders on
 * every admin page, including one opened before the module has migrated.
 */
#[AsNavBadge(priority: 85)]
final readonly class FormsBadge implements NavBadgeContract
{
    public const string NAV_HREF = '/mark/forms';

    public function navHref(): string
    {
        return self::NAV_HREF;
    }

    public function count(): int
    {
        try {
            return FormsServices::submissions()->countNew();
        } catch (\Throwable) {
            return 0;
        }
    }
}
