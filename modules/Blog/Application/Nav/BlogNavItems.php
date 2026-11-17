<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Nav;

use Modules\Core\Domain\Permissions;

/**
 * Blog's entries in the admin nav — a group with three children.
 *
 * `parent` names the parent's label, not its id: a declaration describes an
 * item, and the id is whatever wins in `CardboardNavService`'s merge (a stored row
 * keeps its own). The group and its `Articles` child share one href, which is
 * why the service pairs rows within an href in declared order.
 */
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Blog', href: '/mark/articles', permission: Permissions::CONTENT, order: 70, icon: 'rss')]
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Articles', href: '/mark/articles', permission: Permissions::CONTENT, order: 71, icon: 'file-text', parent: 'Blog')]
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Categories', href: '/mark/categories', permission: Permissions::CONTENT, order: 72, icon: 'folder', parent: 'Blog')]
#[\Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem(label: 'Tags', href: '/mark/tags', permission: Permissions::CONTENT, order: 73, icon: 'tag', parent: 'Blog')]
final class BlogNavItems
{
}
