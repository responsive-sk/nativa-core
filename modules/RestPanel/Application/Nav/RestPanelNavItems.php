<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * RestAdmin's entry in the admin nav — registering a remote service is
 * configuration, so it holds the settings row the policy already gives it.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsAdminNavItem(label: 'REST', href: '/mark/rest', permission: Permissions::SETTINGS, order: 45, icon: 'link')]
final class RestPanelNavItems
{
}
