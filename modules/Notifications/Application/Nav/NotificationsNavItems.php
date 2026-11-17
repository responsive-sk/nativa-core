<?php

declare(strict_types = 1);

namespace Modules\Notifications\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Notifications' entry in the admin nav — the reader's own inbox.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Notifications', href: '/mark/notifications', permission: Permissions::MARK_ACCESS, order: 15, icon: 'bell')]
final class NotificationsNavItems
{
}
