<?php

declare(strict_types = 1);

namespace Modules\Navigation\Domain\Contracts;

use Modules\Navigation\Infrastructure\Attributes\AsNavBadge;
use Modules\Navigation\Infrastructure\Registry\NavBadgeRegistry;

/**
 * NavBadgeContract — a module's count for one admin navigation item.
 *
 * The admin sidebar is shared chrome, so it cannot read
 * `QuestionsServices` (nor `CommentsServices`, nor anything else) without
 * becoming a second place that has to be edited when a module arrives. This
 * contract is the seam instead: a module declares WHICH nav item it speaks for
 * and HOW MANY, and the sidebar asks the registry — the same shape
 * `MetricContract` gives the dashboard, and the same auto-discovery. The
 * contract lives with its registry in `Navigation`; the admin layout is the
 * consumer, not the owner.
 *
 * Two rules the sidebar relies on:
 *
 *  1. **The href is the contract, NOT the item id.** A nav item's `id` column
 *     is a generated hash (`bin2hex(random_bytes(16))`), so a provider keyed
 *     on `_questions` matches nothing in a real install while looking correct
 *     against the hardcoded fallback list. The `href` is what
 *     `AdminRoutePolicy::requiredPermission()` already keys on, what the nav
 *     filter gates, and what a route actually declares — so the badge is
 *     keyed on the same string the permission is.
 *  2. **Zero or less is silence.** A count `<= 0` renders nothing at all: an
 *     empty badge is noise on every admin page, and "no work waiting" is the
 *     normal state most of the time.
 *
 * A provider MUST have no required constructor parameters (same constraint as
 * the other discovery ports); dependencies come from the owning module's lazy
 * factory.
 *
 * What a badge is NOT: an unread marker. It is the depth of a work queue, so
 * it clears when the work is done (a question is moderated), not when somebody
 * looked. A per-user "seen" flag is a different feature with a different table.
 *
 * @see AsNavBadge
 * @see NavBadgeRegistry
 */
interface NavBadgeContract
{
    /**
     * The admin surface this provider speaks for (`/mark/questions`).
     */
    public function navHref(): string;

    /**
     * How many — `<= 0` means render no badge.
     *
     * Implementations MUST NOT throw for a missing database: the sidebar
     * renders on every admin page, including one opened before a module's
     * database has been migrated.
     */
    public function count(): int;
}
