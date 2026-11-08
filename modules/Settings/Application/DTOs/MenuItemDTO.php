<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\DTOs;

/**
 * MenuItemDTO — Data Transfer Object for navigation items.
 *
 * Supports nested menu items for dropdowns (max 3 levels).
 */
final readonly class MenuItemDTO
{
    /**
     * @param list<MenuItemDTO> $children
     */
    public function __construct(
        private string $label,
        private string $href,
        private bool $active = false,
        private array $children = [],
        private bool $external = false,
    ) {
        $this->validate();
    }

    public function label(): string
    {
        return $this->label;
    }

    public function href(): string
    {
        return $this->href;
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * @return list<MenuItemDTO>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function external(): bool
    {
        return $this->external;
    }

    public function hasChildren(): bool
    {
        return [] !== $this->children;
    }


    /**
     * @return array{label: string, href: string, active: bool, external: bool, children: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'label'    => $this->label,
            'href'     => $this->href,
            'active'   => $this->active,
            'external' => $this->external,
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    private function validate(): void
    {
        if ('' === $this->label) {
            throw new \InvalidArgumentException('Menu item label is required');
        }

        if ('' === $this->href) {
            throw new \InvalidArgumentException('Menu item href is required');
        }

        if (!self::isValidHref($this->href)) {
            throw new \InvalidArgumentException('Menu item href must be a valid URL or path');
        }

        if ($this->hasDeepNesting($this->children, 1)) {
            throw new \InvalidArgumentException('Menu nesting must not exceed 3 levels');
        }
    }

    /**
     * Single validation rule for navigation hrefs — shared by the admin save
     * path (SettingsAction::saveNav) and the public render path (validate).
     *
     * Accepts absolute URLs (filter_var), relative paths, anchors and query
     * strings; rejects unsafe schemes such as javascript: / data: / vbscript:.
     */
    public static function isValidHref(string $href): bool
    {
        if (false !== filter_var($href, FILTER_VALIDATE_URL)) {
            return true;
        }

        return str_starts_with($href, '/') || str_starts_with($href, '#') || str_starts_with($href, '?');
    }

    /**
     * @param list<MenuItemDTO> $children
     */
    private function hasDeepNesting(array $children, int $currentLevel): bool
    {
        if ($currentLevel >= 3) {
            return true;
        }

        return array_any($children, fn ($child) => $child->hasChildren() && $this->hasDeepNesting($child->children(), $currentLevel + 1));
    }
}
