<?php

declare(strict_types = 1);

namespace Modules\Locales\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Locales' entry in the admin nav — translations, a settings-screen job.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Locales', href: '/mark/locales', permission: Permissions::SETTINGS, order: 50, icon: 'globe')]
final class LocalesNavItems
{
}
