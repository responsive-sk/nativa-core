<?php

declare(strict_types = 1);

namespace Modules\RestApi\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * RestApi's entry in the Mark nav — registering a remote service is
 * configuration, so it holds the settings row the policy already gives it.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'REST', href: '/mark/rest', permission: Permissions::SETTINGS, order: 45, icon: 'link')]
final class RestApiNavItems
{
}
