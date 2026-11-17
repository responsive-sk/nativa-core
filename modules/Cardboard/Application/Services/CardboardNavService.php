<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\Cardboard\Infrastructure\Persistence\Statement;
use Modules\Cardboard\Infrastructure\Registry\CardboardNavRegistry;
use Modules\Core\Domain\MarkRoutePolicy;
use Modules\Database\Contracts\ConnectionInterface;

/**
 * CardboardNavService — the admin panel's navigation: discovered, then overridden.
 *
 * ## Where an item comes from now
 *
 * Every installed module annotates one class with `#[AsCardboardNavItem]`, and
 * `CardboardNavRegistry` collects them from `src/Modules/<Name>/Application/Nav`.
 * That is the default source: a fresh install renders the sidebar with no seed
 * rows and no hardcoded list, and removing a module removes its entry without a
 * code edit anywhere else.
 *
 * A stored `menu_items` row with the same href is the OPERATOR's override and
 * wins outright — label, sort_order, icon and is_active. A row cannot name an
 * attribute, so the two sides are paired by href in declared order, and a row
 * that finds no partner is kept as an operator-added link.
 *
 * ## Why this used to carry two more lists
 *
 * `FALLBACK` held 18 entries and the Settings seed held the same 18 — a second
 * and a third copy of this nav. Both named `Pages`, `Comments`, `Questions` and
 * `Forms`, none of which has a catalogue slice in this tree, so a fresh install
 * carried links to 404s and `withoutUnreachable()` hid them again at render.
 * The nav now has one declaration site per module instead of three copies in
 * two modules.
 *
 * `permissionTable()` — a copy of `MarkRoutePolicy`'s prefix table, kept
 * because Cardboard "could not import Users" — is gone for the same reason the
 * copy existed: the policy moved to Core on 2026-10-17, and Cardboard imports
 * `Modules\Core\Domain\MarkRoutePolicy` exactly as it already imported
 * `Permissions`.
 *
 * Reads `config.db.menu_items` (location = 'mark') through its own `Statement`
 * to avoid importing another module's Infrastructure layer.
 *
 * @phpstan-type NavItem array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, permission: ?string}
 * @phpstan-type NavTreeItem array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, permission: ?string, children: list<NavItem>, is_expanded: bool}
 */
final readonly class CardboardNavService
{
    public function __construct(private ConnectionInterface $connection)
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
     * @param (\Closure(string): bool)|null $reachable answers whether a path is served
     *
     * @return list<NavTreeItem>
     */
    public function hierarchicalItems(string $activeSection = '', ?array $granted = null, ?\Closure $reachable = null): array
    {
        $index = $this->indexItems(null === $granted ? $this->activeItems() : $this->permittedItems($granted));

        $activeId = $this->resolveActiveId($index, $activeSection);
        $expandedGroups = $this->expandedGroups($index, $activeId);
        $tree = $this->withoutUnreachable($this->buildTree($index['byId']), $reachable);

        // Set is_expanded on group items.
        foreach ($tree as &$group) {
            $group['is_expanded'] = isset($expandedGroups[$group['id']]);
        }
        unset($group);

        return $tree;
    }

    /**
     * Drop admin links no route serves, and groups left with no children.
     *
     * The route table is the install manifest at request time: `Pages` is
     * absent from a tree that did not install it, so `/mark/pages` has no GET
     * route and its menu item goes with it. This is deliberately not a list of
     * module names — the seed may know about every catalogue module, while the
     * running tree knows only the routes it actually carries.
     *
     * Paths outside `/mark` are kept: they are custom links configured in the
     * panel-nav screen, and the router is not the authority for an external
     * URL. A group survives when at least one child survives.
     *
     * @param list<NavTreeItem> $tree
     * @param (\Closure(string): bool)|null $reachable
     *
     * @return list<NavTreeItem>
     */
    private function withoutUnreachable(array $tree, ?\Closure $reachable): array
    {
        if (null === $reachable) {
            return $tree;
        }

        $kept = [];

        foreach ($tree as $item) {
            $children = $this->withoutUnreachable($item['children'], $reachable);
            $served = !str_starts_with($item['href'], '/mark') || $reachable($item['href']);

            if (!$served && [] === $children) {
                continue;
            }

            $item['children'] = $children;
            $kept[] = $item;
        }

        return $kept;
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

        // Also expand the item itself if it is a group. Asked of the merged set
        // rather than of `menu_items`: a discovered item has no row to ask, and
        // `buildTree()` runs after this, so the children are not attached yet.
        foreach ($index['byId'] as $candidate) {
            if ($activeId === $candidate['parent_id']) {
                $expanded[$activeId] = true;
                break;
            }
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
     * @return list<NavItem>
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
     * The href decides, through the same `MarkRoutePolicy` the middleware
     * enforces — so the nav can never drift from the gate and offer a link the
     * server will refuse. An item whose href the policy does not classify is
     * KEPT: the policy denying unmapped paths is a security default for the
     * gate, but a nav entry is explicit admin intent, and hiding it would
     * silently swallow a configured menu.
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
                $required = self::requiredPermission($item);

                return null === $required || in_array($required, $granted, true);
            },
        ));
    }

    /**
     * The permission an item needs: the route policy's answer, or the declared
     * one where the policy abstains.
     *
     * `MarkRoutePolicy` owns "which `/mark` path needs what", and the
     * middleware refuses an admin path it does not classify — so a declaration
     * only answers where there is no policy answer to disagree with. A misspelt
     * permission is not a compile error, so `CardboardNavContractTest` holds every
     * declaration to the policy and to `Permissions::all()`.
     *
     * @param NavItem $item
     */
    private static function requiredPermission(array $item): ?string
    {
        return MarkRoutePolicy::requiredPermission($item['href']) ?? $item['permission'];
    }

    /**
     * The admin nav: what the installed modules declare, with stored rows on top.
     *
     * @return list<NavItem>
     */
    public function allItems(): array
    {
        return $this->merge(CardboardNavRegistry::all(), $this->storedItems());
    }

    /**
     * What the installed modules declare, with no stored row folded in.
     *
     * The admin-nav screen prints this beside the merged table: that table can
     * edit an item only once a row exists, so a declared item has to be visible
     * somewhere before an operator creates its row — otherwise the sidebar
     * shows a link the screen cannot explain.
     *
     * @return list<NavItem>
     */
    public function declaredItems(): array
    {
        return CardboardNavRegistry::all();
    }

    /**
     * Rows stored in `menu_items` (location = 'mark'), sorted by sort_order.
     *
     * Public because the settings screen pairs them with `declaredItems()` to
     * show which declarations a row already overrides.
     *
     * @return list<NavItem>
     */
    public function storedItems(): array
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT id, label, href, sort_order, is_active, icon, parent_item_id FROM menu_items
             WHERE location = :loc ORDER BY sort_order ASC, created_at ASC',
        );
        $stmt->execute([':loc' => 'mark']);

        /** @var list<array<string, mixed>>|false $rows */
        $rows = $stmt->fetchAll();
        if (false === $rows || [] === $rows) {
            return [];
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
                'permission' => null,
            ];
        }

        return $items;
    }

    /**
     * Merge declarations and stored rows: a row with the same href wins.
     *
     * Pairing is by href, in declared order, because neither side can name the
     * other. The `Blog` group and its `Articles` child share one href, so
     * matching by href alone would swap them — pairing within an href in
     * ascending order keeps the pairs the operator saw in the editor, and
     * renaming a row's label still overrides the declared one instead of
     * orphaning the declaration.
     *
     * A declared child's parent id is translated to the id of whatever won for
     * that parent (`_blog` → the row's hex id), or it would dangle as a
     * top-level item.
     *
     * @param list<NavItem> $declared
     * @param list<NavItem> $stored
     *
     * @return list<NavItem>
     */
    private function merge(array $declared, array $stored): array
    {
        if ([] === $stored) {
            return $declared;
        }

        /** @var array<string, list<NavItem>> $byHref */
        $byHref = [];
        foreach ($stored as $row) {
            $byHref[$row['href']][] = $row;
        }

        $merged = [];
        $resolved = [];

        foreach ($declared as $item) {
            $row = $byHref[$item['href']][0] ?? null;
            $winner = $item;

            if (null !== $row) {
                array_shift($byHref[$item['href']]);
                $winner = $row;
            }

            $merged[] = $winner;
            $resolved[$item['id']] = $winner['id'];
        }

        foreach ($byHref as $rows) {
            foreach ($rows as $row) {
                $merged[] = $row;
            }
        }

        foreach ($merged as &$item) {
            if (null !== $item['parent_id'] && isset($resolved[$item['parent_id']])) {
                $item['parent_id'] = $resolved[$item['parent_id']];
            }
        }
        unset($item);

        usort($merged, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return array_values($merged);
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
}
