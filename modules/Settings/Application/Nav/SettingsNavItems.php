<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Settings' entry in the admin nav — the stored site choices.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Settings', href: '/mark/settings', permission: Permissions::SETTINGS, order: 40, icon: 'settings')]
final class SettingsNavItems
{
}
