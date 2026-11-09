<?php

declare(strict_types = 1);

namespace Shell;

/**
 * ShellNavItem — one navigation entry the shell chrome renders.
 *
 * Kernel-owned on purpose. The shell cannot carry a module's DTO, because a
 * DTO is a module's type: holding `Modules\Settings\Application\DTOs\MenuItemDTO`
 * inside the kernel would mean the kernel imports the module it should not know
 * about — the exact edge this port exists to cut. A module that owns a menu
 * maps its own rows into these.
 *
 * `children` is the same shape one level down (a module owns how deep its menu
 * goes); the kernel only carries the tree and never validates it.
 */
final readonly class ShellNavItem
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $label,
        public string $href,
        public bool $active = false,
        public bool $external = false,
        public array $children = [],
    ) {
    }

    public function hasChildren(): bool
    {
        return [] !== $this->children;
    }
}
