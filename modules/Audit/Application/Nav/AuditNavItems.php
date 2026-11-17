<?php

declare(strict_types = 1);

namespace Modules\Audit\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Audit's entry in the admin nav — the log of who did what.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Audit', href: '/mark/audit', permission: Permissions::ROLES, order: 110, icon: 'clipboard')]
final class AuditNavItems
{
}
