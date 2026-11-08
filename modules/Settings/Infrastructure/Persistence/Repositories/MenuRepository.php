<?php

declare(strict_types = 1);

namespace Modules\Settings\Infrastructure\Persistence\Repositories;

use Modules\Settings\Infrastructure\Persistence\Statement;

/**
 * MenuRepository — navigation menu items persistence.
 */
final readonly class MenuRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Menu items for a location, ordered by sort_order then created_at.
     *
     * SQLite returns every column as a string, so the row shape uses string
     * scalars (and nullable columns) rather than native types.
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     href: string,
     *     parent_item_id: ?string,
     *     sort_order: string,
     *     location: string,
     *     external: string,
     *     target: ?string,
     *     is_active: string,
     *     created_at: string,
     *     updated_at: string
     * }>
     */
    public function findByLocation(string $location): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT * FROM menu_items
             WHERE location = :location AND is_active = 1
             ORDER BY sort_order ASC, created_at ASC',
        );
        $stmt->execute([':location' => $location]);

        /** @var list<array<string, mixed>>|false $rows */
        $rows = $stmt->fetchAll();
        if (false === $rows) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            /** @var array<string, string|null> $row */
            $normalized[] = [
                'id'             => (string) ($row['id'] ?? ''),
                'label'          => (string) ($row['label'] ?? ''),
                'href'           => (string) ($row['href'] ?? ''),
                'parent_item_id' => null === $row['parent_item_id'] ? null : (string) $row['parent_item_id'],
                'sort_order'     => (string) ($row['sort_order'] ?? '0'),
                'location'       => (string) ($row['location'] ?? ''),
                'external'       => (string) ($row['external'] ?? '0'),
                'target'         => null === $row['target'] ? null : (string) $row['target'],
                'is_active'      => (string) ($row['is_active'] ?? '1'),
                'created_at'     => (string) ($row['created_at'] ?? ''),
                'updated_at'     => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * Delete a menu item (and any nested children).
     */
    public function delete(string $id): void
    {
        Statement::prepare($this->pdo, 'DELETE FROM menu_items WHERE id = :id OR parent_item_id = :id2')
            ->execute([':id' => $id, ':id2' => $id]);
    }

    /**
     * Create a menu item.
     */
    public function create(
        string $label,
        string $href,
        string $location,
        int $sortOrder = 0,
        ?string $parentItemId = null,
        bool $external = false,
        ?string $target = null,
        string $icon = '',
    ): string {
        $id = bin2hex(random_bytes(16));

        Statement::prepare($this->pdo,
            'INSERT INTO menu_items (id, label, href, parent_item_id, sort_order, location, external, target, icon, is_active, created_at, updated_at)
             VALUES (:id, :label, :href, :parent, :sort, :loc, :ext, :target, :icon, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        )->execute([
            ':id'     => $id,
            ':label'  => $label,
            ':href'   => $href,
            ':parent' => $parentItemId,
            ':sort'   => $sortOrder,
            ':loc'    => $location,
            ':ext'    => $external ? 1 : 0,
            ':target' => $target,
            ':icon'   => $icon,
        ]);

        return $id;
    }

    /**
     * Update the icon of a menu item.
     */
    public function updateIcon(string $id, string $icon): void
    {
        Statement::prepare($this->pdo,
            'UPDATE menu_items SET icon = :icon, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        )->execute([':id' => $id, ':icon' => $icon]);
    }

    /**
     * Toggle the is_active flag of a menu item.
     */
    public function toggleActive(string $id): void
    {
        Statement::prepare($this->pdo,
            'UPDATE menu_items SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        )->execute([':id' => $id]);
    }

    /**
     * Update the sort_order of a menu item.
     */
    public function updateSortOrder(string $id, int $sortOrder): void
    {
        Statement::prepare($this->pdo,
            'UPDATE menu_items SET sort_order = :sort, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        )->execute([':id' => $id, ':sort' => $sortOrder]);
    }

    /**
     * Batch update sort_order for multiple items.
     *
     * @param array<string, int> $pairs id => sort_order
     */
    public function batchUpdateSortOrder(array $pairs): void
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE menu_items SET sort_order = :sort, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );

        foreach ($pairs as $id => $sortOrder) {
            $stmt->execute([':id' => $id, ':sort' => $sortOrder]);
        }
    }
}
