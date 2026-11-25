<?php

declare(strict_types = 1);

namespace Shell;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * HomepageSurfaceRegistry — the module that owns the site's landing page.
 *
 * Discovery is the same text-first directory scan the other registries use
 * (`ShellContextProviderRegistry`, `ThemeRegistry`, `MiddlewareRegistry`): a
 * file whose name ends in `HomepageSurface.php` under an installed module's
 * `Interfaces/HTTP/` directory, marked with `#[AsHomepageSurface]`. A module
 * joins by existing and leaves by being removed; there is no list to edit.
 *
 * ONE answer, not a merge: two modules composing one landing page is two pages
 * glued together, the same argument `navigation` makes in the shell port. The
 * lowest priority wins; a tie is broken by class name, because discovery order
 * is `glob()` order and a page whose owner depends on directory iteration is
 * the coin flip this port exists to avoid.
 *
 * Nothing installed means null, and null means the kernel renders its own
 * landing — a thinner page, not an error.
 */
final class HomepageSurfaceRegistry
{
    /** @var list<array{int, HomepageSurface}>|null */
    private static ?array $surfaces = null;

    /** The surface that owns `/`, or null when no module ships one. */
    public static function first(): ?HomepageSurface
    {
        $found = self::allWithPriority();

        return [] === $found ? null : $found[0][1];
    }

    /**
     * Every installed surface with its priority, in priority order.
     *
     * @return list<array{int, HomepageSurface}>
     */
    public static function allWithPriority(): array
    {
        if (null !== self::$surfaces) {
            return self::$surfaces;
        }

        $found = [];

        foreach (self::files() as $file) {
            $surface = self::load($file);

            if (null !== $surface) {
                $found[] = $surface;
            }
        }

        // Priority first, class name second: two surfaces that claim the same
        // priority must still answer the same on every filesystem.
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1]::class <=> $b[1]::class);

        return self::$surfaces = $found;
    }

    /** Reset the cached surfaces (test isolation). */
    public static function reset(): void
    {
        self::$surfaces = null;
    }

    /**
     * Candidate surface files, one glob — a file whose name does not say
     * `HomepageSurface` is not one, and this is what keeps the scan from loading
     * the whole tree.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Interfaces/HTTP/*HomepageSurface.php');

        return is_array($files) ? $files : [];
    }

    /**
     * @return array{int, HomepageSurface}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsHomepageSurface')) {
            return null;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return null;
        }

        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(HomepageSurface::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsHomepageSurface::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var HomepageSurface $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else — the same rule the other registries follow
     * (a declaration starts a LINE, after optional whitespace and the modifiers
     * PHP allows on a type. Prose is not a declaration). `TextFirstFqcnTest`
     * pins this shape for every registry that carries a copy of it.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $className) {
            return null;
        }

        return '' !== $namespace ? $namespace . '\\' . $className : $className;
    }
}
