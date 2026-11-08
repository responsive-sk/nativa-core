<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Registry;

use Modules\Core\Domain\Contracts\NavBadgeContract;
use Modules\Core\Infrastructure\Attributes\AsNavBadge;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * NavBadgeRegistry — auto-discovers `#[AsNavBadge]` classes and answers
 * "how many?" for one admin nav item.
 *
 * The discovery pass is deliberately the shape the sibling registries use
 * (directory scan + FQCN extraction + a zero-arg constructor check) rather
 * than another variant: a registry that discovers differently from its
 * siblings is a registry whose failure nobody can predict.
 *
 * A provider that THROWS is skipped, not propagated. The sidebar renders on
 * every admin page, so one module's missing database must not blank the whole
 * navigation — and silence is the correct rendering for a badge nobody could
 * compute.
 *
 * @see AsNavBadge
 * @see NavBadgeContract
 */
final class NavBadgeRegistry
{
    /** @var list<NavBadgeContract>|null */
    private static ?array $providers = null;

    /**
     * Every discovered provider, sorted by priority (descending).
     *
     * @return list<NavBadgeContract>
     */
    public static function all(): array
    {
        if (null !== self::$providers) {
            return self::$providers;
        }

        $found = [];
        $base = AppPaths::instance()->src('Modules');
        $dirs = glob($base . '/*/Application/Services');
        if (!is_array($dirs)) {
            return self::$providers = [];
        }

        foreach ($dirs as $dir) {
            $files = glob($dir . '/*Badge.php');
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                self::scanFile($file, $found);
            }
        }

        usort($found, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return self::$providers = array_map(
            static fn (array $entry): NavBadgeContract => $entry[1],
            $found,
        );
    }

    /**
     * The badge for one nav href, or null when nothing should be rendered.
     *
     * Null covers all three "render nothing" cases in one answer: no provider
     * speaks for the surface, the provider said zero, or the provider threw.
     */
    public static function for(string $navHref): ?int
    {
        foreach (self::all() as $provider) {
            if ($provider->navHref() === $navHref) {
                return self::badgeFrom($provider);
            }
        }

        return null;
    }

    /**
     * What ONE provider says, as a rendering decision.
     *
     * Split out from `for()` so the rule is testable against a stub: a
     * provider that throws is a branch no shipped provider can reach (they
     * are all required not to throw), and an untested branch in the code that
     * renders every admin page is the sort of thing that quietly stops
     * working.
     */
    public static function badgeFrom(NavBadgeContract $provider): ?int
    {
        try {
            $count = $provider->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? $count : null;
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * @param array<string, array{int, NavBadgeContract}> $found
     */
    private static function scanFile(string $file, array &$found): void
    {
        $content = file_get_contents($file);
        if (!is_string($content) || !str_contains($content, 'AsNavBadge')) {
            return;
        }

        $fqcn = self::resolveFqcn($content);
        if (null === $fqcn) {
            return;
        }

        $entry = self::loadProvider($fqcn, $file);
        if (null !== $entry) {
            $found[$entry[0] . '_' . $fqcn] = $entry;
        }
    }

    /**
     * Extract the FQCN from a file body (namespace + class declaration).
     */
    private static function resolveFqcn(string $content): ?string
    {
        if (!preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)) {
            return null;
        }
        if (!preg_match('/class\s+(\w+)/', $content, $cnMatch)) {
            return null;
        }

        return $nsMatch[1] . '\\' . $cnMatch[1];
    }

    /**
     * Load the class and validate the attribute contract (implementor +
     * attribute + zero-arg constructor).
     *
     * @return array{int, NavBadgeContract}|null the priority and the instance
     */
    private static function loadProvider(string $fqcn, string $file): ?array
    {
        if (!interface_exists($fqcn) && !class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(NavBadgeContract::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsNavBadge::class);
        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        /** @var AsNavBadge $attr */
        $attr = $attributes[0]->newInstance();

        /** @var NavBadgeContract $badge */
        $badge = $reflection->newInstance();

        return [$attr->priority, $badge];
    }
}
