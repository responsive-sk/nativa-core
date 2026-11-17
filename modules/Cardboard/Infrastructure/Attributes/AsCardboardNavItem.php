<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Infrastructure\Attributes;

/**
 * Marks a class as declaring panel navigation entries for auto-discovery.
 *
 * Repeatable on purpose: a module that owns a group and its children declares
 * all of them on one class under `Application/Nav/`. A nav entry has no
 * behaviour — it is a label, a path and the permission that path already
 * requires — so one declaration file per module beats one class per item.
 * `CardboardNavRegistry` discovers these in installed modules only.
 *
 * `$href` is the PATH, not a route name: the nav renders links, and the route
 * table answers reachability by path (`Router::hasPath()`), so a name would add
 * a lookup nothing else in the panel needs. `$parent` is the parent's LABEL —
 * the attribute describes an item, not a `menu_items` row, and stored rows are
 * paired with these declarations by href.
 *
 * `$permission` must be one of `Modules\Core\Domain\Permissions`. It is the gate
 * only where `AdminRoutePolicy` does not classify the path; where the policy
 * does answer, `AdminNavContractTest` holds the declaration to the policy's own
 * answer, so a nav item can never offer a link the middleware refuses.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsCardboardNavItem
{
    public function __construct(
        public string $label,
        public string $href,
        public string $permission,
        public int $order,
        public string $icon = '',
        public ?string $parent = null,
    ) {
    }
}
