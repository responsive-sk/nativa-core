<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Infrastructure\Registry;

use Modules\Cardboard\Infrastructure\Attributes\AsCardboardNavItem;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * CardboardNavRegistry — discovers `#[AsCardboardNavItem]` declarations in installed
 * modules.
 *
 * The scan is the same one `MetricRegistry` uses, narrowed to
 * `src/Modules/<Name>/Application/Nav`: `src/Modules` holds only what is
 * installed, so a module that is not installed contributes nothing and
 * `module:remove` remains a complete removal. Nothing here imports a feature
 * module — the declarations are read off disk, and the attribute class itself
 * is Cardboard's.
 *
 * ## Ids
 *
 * A declared item has no database row, so it needs an id for the tree builder
 * and the active-link highlight. The id is derived from the label (`Dashboard`
 * → `_dashboard`), which is also what the old hardcoded fallback used, and a
 * collision gets a numeric suffix. Stored rows keep their own ids; when a row
 * overrides a declaration, `CardboardNavService` translates the children's parent
 * references to the row's id.
 *
 * ## Why a silent miss would be invisible again
 *
 * `MetricRegistry` once dropped a provider because an unanchored `/class\s+/`
 * matched the word "class" inside a docblock, and the missing card reported
 * nothing. The FQCN resolver below is anchored to a declaration line for the
 * same reason, and `CardboardNavContractTest` asserts that every
 * `#[AsCardboardNavItem]` on disk is present in `all()` rather than trusting the
 * scan to have found them.
 *
 * @phpstan-type NavItem array{id: string, label: string, href: string, sort_order: int, is_active: bool, icon: string, parent_id: ?string, permission: ?string}
 */
final class CardboardNavRegistry
{
    /**
     * @var list<NavItem>|null
     */
    private static ?array $items = null;

    /**
     * Every declared nav item, in ascending order.
     *
     * @return list<NavItem>
     */
    public static function all(): array
    {
        if (null !== self::$items) {
            return self::$items;
        }

        /** @var list<AsCardboardNavItem> $declared */
        $declared = [];

        $dirs = glob(AppPaths::instance()->src('Modules') . '/*/Application/Nav');

        if (!is_array($dirs)) {
            self::$items = [];

            return [];
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.php');

            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                self::scanFile($file, $declared);
            }
        }

        usort(
            $declared,
            static fn (AsCardboardNavItem $a, AsCardboardNavItem $b): int => [$a->order, $a->label] <=> [$b->order, $b->label],
        );

        $items = [];
        $used = [];

        foreach ($declared as $declaration) {
            $items[] = [
                'id'         => self::id($declaration->label, $used),
                'label'      => $declaration->label,
                'href'       => $declaration->href,
                'sort_order' => $declaration->order,
                'is_active'  => true,
                'icon'       => $declaration->icon,
                'parent_id'  => null === $declaration->parent ? null : '_' . self::slug($declaration->parent),
                'permission' => $declaration->permission,
            ];
        }

        self::$items = $items;

        return $items;
    }

    /**
     * Reset cached items (test isolation).
     */
    public static function reset(): void
    {
        self::$items = null;
    }

    /**
     * A stable id from the label, with a suffix when two labels collide.
     *
     * @param array<string, true> $used
     */
    private static function id(string $label, array &$used): string
    {
        $base = '_' . self::slug($label);
        $id = $base;
        $suffix = 2;

        while (isset($used[$id])) {
            $id = $base . '-' . $suffix;
            ++$suffix;
        }

        $used[$id] = true;

        return $id;
    }

    private static function slug(string $label): string
    {
        return trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower($label)), '-');
    }

    /**
     * Load one declaration file and collect its attributes.
     *
     * @param list<AsCardboardNavItem> $declared
     */
    private static function scanFile(string $file, array &$declared): void
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsCardboardNavItem')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return;
        }

        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return;
        }

        $reflection = new \ReflectionClass($fqcn);

        foreach ($reflection->getAttributes(AsCardboardNavItem::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            /** @var AsCardboardNavItem $item */
            $item = $attribute->newInstance();
            $declared[] = $item;
        }
    }

    /**
     * Extract the FQCN from a file body (namespace + class declaration).
     *
     * Anchored to a declaration line, never the bare word `class`: a docblock
     * sentence once resolved a provider's FQCN to the word after "class", and
     * the provider was dropped without a word.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (1 === preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $nsMatch)) {
            $namespace = $nsMatch[1];
        }

        if (1 === preg_match('/^\s*(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $content, $cnMatch)) {
            $className = $cnMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }
}
