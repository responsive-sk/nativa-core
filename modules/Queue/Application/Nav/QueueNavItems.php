<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Queue's entry in the Mark nav — an operational console, not content.
 *
 * `roles.manage` is the permission `MarkRoutePolicy` asks for this path: the
 * page runs jobs for the whole install, and no author was ever linked to it.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Queue', href: '/mark/queue', permission: Permissions::ROLES, order: 106, icon: 'database')]
final class QueueNavItems
{
}
