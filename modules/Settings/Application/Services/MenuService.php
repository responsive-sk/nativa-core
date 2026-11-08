<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\Services;

use Modules\Settings\Application\DTOs\MenuItemDTO;
use Modules\Settings\Infrastructure\Persistence\Repositories\MenuRepository;

/**
 * MenuService — builds nested navigation trees consumed by layouts.
 */
final readonly class MenuService
{
    public function __construct(private MenuRepository $repository)
    {
    }

    /**
     * Top-level items for a location (with nested children).
     *
     * @return list<MenuItemDTO>
     */
    public function menu(string $location, string $currentPath = '/'): array
    {
        $items = $this->repository->findByLocation($location);

        $byParent = [];
        foreach ($items as $item) {
            $parent = is_string($item['parent_item_id']) && '' !== $item['parent_item_id']
                ? (string) $item['parent_item_id']
                : '';
            $byParent[$parent][] = $item;
        }

        $build = function (string $parentId, int $depth) use (&$build, $byParent, $currentPath): array {
            $result = [];

            foreach ($byParent[$parentId] ?? [] as $row) {
                /** @var array{id: string, label: string, href: string, parent_item_id: ?string, sort_order: string, location: string, external: string, target: ?string, is_active: string, created_at: string, updated_at: string} $row */
                $label = $row['label'];
                $href = $row['href'];
                $id = $row['id'];
                $external = '1' === $row['external'];

                $result[] = new MenuItemDTO(
                    label: $label,
                    href: $href,
                    active: $href === $currentPath || ('/' !== $href && str_starts_with($currentPath, $href)),
                    children: $depth < 3 ? $build($id, $depth + 1) : [],
                    external: $external,
                );
            }

            return $result;
        };

        return $build('', 1);
    }
}
