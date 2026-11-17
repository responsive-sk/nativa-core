<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Media's entry in the admin nav — the upload library editors work from.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Media', href: '/mark/media', permission: Permissions::CONTENT, order: 60, icon: 'image')]
final class MediaNavItems
{
}
