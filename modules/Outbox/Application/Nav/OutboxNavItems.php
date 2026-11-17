<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Outbox's entry in the admin nav — delivery is an access-management concern,
 * which is the row the policy already gives this path.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Outbox', href: '/mark/outbox', permission: Permissions::ROLES, order: 108, icon: 'inbox')]
final class OutboxNavItems
{
}
