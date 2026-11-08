<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\Cardboard\Infrastructure\Persistence\Statement;
use Modules\Core\Domain\Permissions;

/**
 * AdminNavService — resolves admin panel navigation items from DB.
 *
 * Falls back to hardcoded defaults when the DB has no admin nav items
 * (e.g. fresh install before migration seed runs).
 *
 * Reads directly from config.db.menu_items (location = 'admin') to avoid
 * importing another module's Infrastructure layer.
 *
 * @phpstan-type NavItem array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}
 * @phpstan-type NavTreeItem array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, children: list<NavItem>, is_expanded: bool}
 */
final readonly class AdminNavService
{
    /**
     * Hardcoded fallback — mirrors the original CompactLayout/AdminLayout nav.
     *
     * @var list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}>
     */
    private const array FALLBACK = [
        ['id' => '_dashboard', 'label' => 'Dashboard', 'href' => '/mark', 'sort_order' => 10, 'is_active' => true, 'icon' => 'dashboard', 'parent_id' => null],
        ['id' => '_notifications', 'label' => 'Notifications', 'href' => '/mark/notifications', 'sort_order' => 15, 'is_active' => true, 'icon' => 'bell', 'parent_id' => null],
        ['id' => '_users',     'label' => 'Users',     'href' => '/mark/users', 'sort_order' => 20, 'is_active' => true, 'icon' => 'users', 'parent_id' => null],
        ['id' => '_roles',     'label' => 'Roles',     'href' => '/mark/roles', 'sort_order' => 30, 'is_active' => true, 'icon' => 'shield', 'parent_id' => null],
        ['id' => '_settings',  'label' => 'Settings',  'href' => '/mark/settings', 'sort_order' => 40, 'is_active' => true, 'icon' => 'settings', 'parent_id' => null],
        ['id' => '_locales',   'label' => 'Locales',   'href' => '/mark/locales', 'sort_order' => 50, 'is_active' => true, 'icon' => 'globe', 'parent_id' => null],
        ['id' => '_media',     'label' => 'Media',     'href' => '/mark/media', 'sort_order' => 60, 'is_active' => true, 'icon' => 'image', 'parent_id' => null],
        ['id' => '_blog',      'label' => 'Blog',      'href' => '/mark/articles', 'sort_order' => 70, 'is_active' => true, 'icon' => 'rss', 'parent_id' => null],
        ['id' => '_articles',  'label' => 'Articles',  'href' => '/mark/articles', 'sort_order' => 71, 'is_active' => true, 'icon' => 'file-text', 'parent_id' => '_blog'],
        ['id' => '_categories','label' => 'Categories','href' => '/mark/categories', 'sort_order' => 72, 'is_active' => true, 'icon' => 'folder', 'parent_id' => '_blog'],
        ['id' => '_tags',      'label' => 'Tags',      'href' => '/mark/tags', 'sort_order' => 73, 'is_active' => true, 'icon' => 'tag', 'parent_id' => '_blog'],
        ['id' => '_pages',     'label' => 'Pages',     'href' => '/mark/pages', 'sort_order' => 80, 'is_active' => true, 'icon' => 'file', 'parent_id' => null],
        ['id' => '_comments',  'label' => 'Comments',  'href' => '/mark/comments', 'sort_order' => 100, 'is_active' => true, 'icon' => 'message', 'parent_id' => null],
        ['id' => '_questions', 'label' => 'Questions', 'href' => '/mark/questions', 'sort_order' => 102, 'is_active' => true, 'icon' => 'help-circle', 'parent_id' => null],
        ['id' => '_forms',     'label' => 'Forms',     'href' => '/mark/forms', 'sort_order' => 105, 'is_active' => true, 'icon' => 'inbox', 'parent_id' => null],
        ['id' => '_outbox',    'label' => 'Outbox',    'href' => '/mark/outbox', 'sort_order' => 108, 'is_active' => true, 'icon' => 'inbox', 'parent_id' => null],
        ['id' => '_audit',     'label' => 'Audit',     'href' => '/mark/audit', 'sort_order' => 110, 'is_active' => true, 'icon' => 'clipboard', 'parent_id' => null],
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Active admin nav items as a hierarchical tree.
     *
     * Items with children are grouped. Each group item gets a `children` array.
     * The `is_expanded` flag is true when the active section is a child of this group.
     *
     * `$granted` is the caller's permission names. Passing one hides every item
     * whose href the policy gates behind a permission the caller lacks, so an
     * author is never shown a link that 403s — a nav that leads to refusals is
     * worse than no nav, because it teaches the author the panel is broken.
     *
     * Null means "no filtering": callers with no user in hand (the settings
     * screen that configures the nav itself) render the full tree.
     *
     * @param list<string>|null $granted
     *
     * @return list<NavTreeItem>
     */
    public function hierarchicalItems(string $activeSection = '', ?array $granted = null): array
    {
        $index = $this->indexItems(null === $granted ? $this->activeItems() : $this->permittedItems($granted));

        $activeId = $this->resolveActiveId($index, $activeSection);
        $expandedGroups = $this->expandedGroups($index, $activeId);
        $tree = $this->buildTree($index['byId']);

        // Set is_expanded on group items.
        foreach ($tree as &$group) {
            $group['is_expanded'] = isset($expandedGroups[$group['id']]);
        }
        unset($group);

        return $tree;
    }

    /**
     * Index flat items by id (each with empty `children` + `is_expanded` slots)
     * and map lowercase labels to ids for activeSection resolution.
     *
     * @param list<NavItem> $flat
     *
     * @return array{byId: array<string, NavTreeItem>, byLabel: array<string, string>}
     */
    private function indexItems(array $flat): array
    {
        $byId = [];
        $byLabel = [];
        foreach ($flat as $item) {
            $byId[$item['id']] = $item + ['children' => [], 'is_expanded' => false];
            $byLabel[strtolower($item['label'])] = $item['id'];
        }

        return ['byId' => $byId, 'byLabel' => $byLabel];
    }

    /**
     * Resolve the active item id from an activeSection hint.
     *
     * Actions pass lowercase labels (e.g. 'tags'), but $byId is indexed by
     * hex DB IDs — resolve ids directly, falling back to the label map.
     *
     * @param array{byId: array<string, NavTreeItem>, byLabel: array<string, string>} $index
     */
    private function resolveActiveId(array $index, string $activeSection): string
    {
        if ('' === $activeSection) {
            return '';
        }

        if (isset($index['byId'][$activeSection])) {
            return $activeSection;
        }

        return $index['byLabel'][$activeSection] ?? '';
    }

    /**
     * Groups that should render expanded: the parent of the active child,
     * plus the active item itself when it is a group (has active children).
     *
     * @param array{byId: array<string, NavTreeItem>, byLabel: array<string, string>} $index
     *
     * @return array<string, true>
     */
    private function expandedGroups(array $index, string $activeId): array
    {
        $expanded = [];
        if ('' === $activeId || !isset($index['byId'][$activeId])) {
            return $expanded;
        }

        $activeItem = $index['byId'][$activeId];
        if (null !== $activeItem['parent_id'] && isset($index['byId'][$activeItem['parent_id']])) {
            $expanded[$activeItem['parent_id']] = true;
        }

        // Also expand the item itself if it's a group (has children in DB).
        if ($this->hasChildren($activeItem['id'])) {
            $expanded[$activeId] = true;
        }

        return $expanded;
    }

    /**
     * Attach children to parents, then collect top-level items in flat order.
     *
     * Two-pass because PHP arrays are value types — collecting top-level items
     * before children are populated would capture a stale copy.
     *
     * @param array<string, NavTreeItem> $byId
     *
     * @return list<NavTreeItem>
     */
    private function buildTree(array $byId): array
    {
        foreach ($byId as $item) {
            if (null !== $item['parent_id'] && isset($byId[$item['parent_id']])) {
                $byId[$item['parent_id']]['children'][] = $byId[$item['id']];
            }
        }

        $tree = [];
        foreach ($byId as $item) {
            if (null === $item['parent_id'] || !isset($byId[$item['parent_id']])) {
                $tree[] = $item;
            }
        }

        return $tree;
    }

    /**
     * Active admin nav items, sorted by sort_order (flat).
     *
     * @return list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}>
     */
    public function activeItems(): array
    {
        return array_values(array_filter(
            $this->allItems(),
            static fn (array $item): bool => $item['is_active'],
        ));
    }

    /**
     * Active items this caller may actually open.
     *
     * The href decides, through the same `AdminRoutePolicy` the middleware
     * enforces — so the nav can never drift from the gate and offer a link the
     * server will refuse. An item whose href the policy does not classify is
     * KEPT: the policy denying unmapped paths is a security default for the
     * gate, but a nav entry configured in /mark/settings/admin-nav is explicit
     * admin intent, and hiding it would silently swallow a configured menu.
     *
     * @param list<string> $granted
     *
     * @return list<NavItem>
     */
    private function permittedItems(array $granted): array
    {
        return array_values(array_filter(
            $this->activeItems(),
            static function (array $item) use ($granted): bool {
                $required = self::requiredPermission($item['href']);

                return null === $required || in_array($required, $granted, true);
            },
        ));
    }

    /**
     * Local copy of the admin-route PREFIX table (no Users import — see
     * CardboardServices note about the single admin module staying a leaf for
     * the install-closure scanner).
     *
     * It is a COPY of the mapping, not a second owner of the vocabulary: every
     * value is the constant from `Modules\Core\Domain\Permissions`, so a
     * permission renamed in its owner is renamed here by the same edit, and the
     * literal `'content.manage'` has one spelling in the tree instead of
     * eighteen. The prefixes still have to be copied, because hiding an item
     * needs to know which surface it points at — and
     * `CardboardPermissionCopyTest` asks `AdminRoutePolicy` for every prefix's
     * answer and fails when this table drifts, including a prefix the policy
     * added and this copy never got.
     *
     * Kept as a method so the guard can read the whole table without inventing
     * a public API or another permission constant.
     *
     * @return array<string, string>
     */
    private static function permissionTable(): array
    {
        return [
            '/mark' => Permissions::MARK_ACCESS,
            '/mark/notifications' => Permissions::MARK_ACCESS,
            '/mark/articles' => Permissions::CONTENT,
            '/mark/pages' => Permissions::CONTENT,
            '/mark/categories' => Permissions::CONTENT,
            '/mark/tags' => Permissions::CONTENT,
            '/mark/comments' => Permissions::CONTENT,
            '/mark/questions' => Permissions::CONTENT,
            '/mark/media' => Permissions::CONTENT,
            '/mark/forms' => Permissions::CONTENT,
            '/mark/outbox' => Permissions::ROLES,
            '/mark/bulk-publish' => Permissions::CONTENT,
            '/mark/bulk-delete' => Permissions::CONTENT,
            '/mark/locales' => Permissions::SETTINGS,
            '/mark/settings' => Permissions::SETTINGS,
            '/mark/users' => Permissions::USERS,
            '/mark/roles' => Permissions::ROLES,
            '/mark/permissions' => Permissions::ROLES,
            '/mark/audit' => Permissions::ROLES,
        ];
    }

    private static function requiredPermission(string $path): ?string
    {
        if ('/mark' !== $path && !str_starts_with($path, '/mark/')) {
            return null;
        }

        $table = self::permissionTable();
        $best = null;
        $bestLength = -1;

        foreach ($table as $prefix => $permission) {
            $matches = $path === $prefix
                || str_starts_with($path, str_ends_with($prefix, '/') ? $prefix : $prefix . '/');

            if (!$matches) {
                continue;
            }

            $length = strlen($prefix);

            if ($length > $bestLength) {
                $best = $permission;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * All admin nav items (active + inactive), sorted by sort_order.
     *
     * @return list<array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string}>
     */
    public function allItems(): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT id, label, href, sort_order, is_active, icon, parent_item_id FROM menu_items
             WHERE location = :loc ORDER BY sort_order ASC, created_at ASC',
        );
        $stmt->execute([':loc' => 'admin']);

        /** @var list<array<string, mixed>>|false $rows */
        $rows = $stmt->fetchAll();
        if (false === $rows || [] === $rows) {
            return self::FALLBACK;
        }

        $items = [];
        foreach ($rows as $row) {
            /** @var array<string, string|null> $row */
            $items[] = [
                'id'         => (string) ($row['id'] ?? ''),
                'label'      => (string) ($row['label'] ?? ''),
                'href'       => (string) ($row['href'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? '0'),
                'is_active'  => '1' === (string) ($row['is_active'] ?? '1'),
                'icon'       => (string) ($row['icon'] ?? ''),
                'parent_id'  => null === $row['parent_item_id'] ? null : (string) $row['parent_item_id'],
            ];
        }

        return $items;
    }

    /**
     * Determine the active section ID from the current request path.
     *
     * Matches the href of nav items to identify which sidebar/top-nav link
     * should be highlighted. If a child matches, returns the child's ID
     * (the caller can look up the parent for group expansion).
     */
    public function activeSection(string $currentPath): string
    {
        $items = $this->activeItems();

        foreach ($items as $item) {
            if ($item['href'] === $currentPath) {
                return $item['id'];
            }
        }

        // Fallback: match by prefix (e.g. /mark/users/123 → users)
        foreach ($items as $item) {
            $href = $item['href'];
            if ('/' !== $href && str_starts_with($currentPath, $href)) {
                return $item['id'];
            }
        }

        return '';
    }

    /**
     * Check if a given item has children in the DB.
     */
    private function hasChildren(string $itemId): bool
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT 1 FROM menu_items WHERE parent_item_id = :id AND location = :loc AND is_active = 1 LIMIT 1',
        );
        $stmt->execute([':id' => $itemId, ':loc' => 'admin']);

        return false !== $stmt->fetch();
    }
}
