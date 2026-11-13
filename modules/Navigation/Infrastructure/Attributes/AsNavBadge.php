<?php

declare(strict_types = 1);

namespace Modules\Navigation\Infrastructure\Attributes;

use Modules\Navigation\Infrastructure\Registry\NavBadgeRegistry;

/**
 * Marks a class as an admin-nav badge provider for auto-discovery.
 *
 * Classes annotated with #[AsNavBadge] are discovered by NavBadgeRegistry.
 * The priority only matters when two providers speak for the SAME nav item —
 * the higher one wins — because a module has exactly one item to badge and
 * this keeps the tie-break explicit instead of accidental.
 *
 * The class MUST implement NavBadgeContract and have no required
 * constructor parameters, like every other discovery port.
 *
 * @see NavBadgeContract
 * @see NavBadgeRegistry
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsNavBadge
{
    public function __construct(
        public int $priority = 100,
    ) {
    }
}
