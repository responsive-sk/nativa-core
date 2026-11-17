<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Users' entries in the admin nav — accounts and the permission graph.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Users', href: '/mark/users', permission: Permissions::USERS, order: 20, icon: 'users')]
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Roles', href: '/mark/roles', permission: Permissions::ROLES, order: 30, icon: 'shield')]
final class UsersNavItems
{
}
