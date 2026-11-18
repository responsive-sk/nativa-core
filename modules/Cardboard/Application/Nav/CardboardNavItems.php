<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Cardboard's own entry in the admin nav — the panel is a module too.
 *
 * The attribute is spelled fully-qualified rather than imported: a nav
 * declaration names no behaviour, and `CardboardNavRegistry` reads it off disk, so
 * the file must load whether or not the reader has the attribute class in hand.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Dashboard', href: '/mark', permission: Permissions::MARK_ACCESS, order: 10, icon: 'dashboard')]
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Permissions', href: '/mark/permissions', permission: Permissions::ROLES, order: 35, icon: 'key')]
final class CardboardNavItems
{
}
