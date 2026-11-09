<?php

declare(strict_types = 1);

namespace Themes;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * ThemeRegistry — discovers theme modules carried by installed modules.
 *
 * A theme arrives with the module that ships it: any installed class
 * implementing `Themes\ThemeModule` (found by scanning `src/Modules` the way
 * `MiddlewareRegistry` scans for middleware) extends the theme set — no entry
 * in a static table the kernel would have to keep, and a removed module takes
 * its theme with it. The kernel's own theme stays hardcoded in `ThemeKernel`:
 * it is the fallback, not a discovery result.
 *
 * A theme that cannot be constructed or refuses to name itself is SKIPPED and
 * logged, never fatal: the answer to a broken theme is the kernel theme, not
 * a 500 on every surface (the same degradation `ThemeKernel` already documents
 * for an unknown name). The skip is logged so a silently unstyled site is
 * still visible in the log.
 */
final class ThemeRegistry
{
    /** @var array<string, class-string<ThemeModule>>|null theme name → class */
    private static ?array $themes = null;

    /** @var list<string>|null memoized `bundles()` — it is read per AssetManager */
    private static ?array $bundles = null;

    /**
     * Every module-carried theme, by name.
     *
     * @return array<string, class-string<ThemeModule>>
     */
    public static function all(): array
    {
        if (null !== self::$themes) {
            return self::$themes;
        }

        $found = [];
        $base = AppPaths::instance()->src('Modules');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $fqcn = self::loadThemeClass($file->getPathname());

            if (null !== $fqcn) {
                $found[$fqcn[1]] = $fqcn[0];
            }
        }

        ksort($found);
        self::$themes = $found;

        return self::$themes;
    }

    /** Reset the cache (test isolation, module add/remove). */
    public static function reset(): void
    {
        self::$themes = null;
        self::$bundles = null;
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /**
     * The asset bundle each discovered theme declares — what `AssetManager`
     * needs to find its manifest.
     *
     * Asked of the theme, not derived from its name: the name and the bundle
     * are separate declarations in `ThemeModule` (`bundle()` exists precisely
     * because a theme may rename one without the other), so a list built from
     * `all()`'s keys would be right only by accident.
     *
     * A theme that cannot be constructed is SKIPPED, never guessed — `get()`
     * logs the reason, and a bundle nobody can name is a bundle whose manifest
     * must not be looked up. Measured why this exists: with the bundle list
     * hardcoded to kernel/moonshine/minimal, the `erik` theme resolved its
     * assets as bundle `kernel`, looked for `public/dist/kernel/manifest.json`
     * (absent), and rendered with the 1.1 KB critical subset inlined instead of
     * its own 35 KB built stylesheet — a page that looks styled and is not.
     *
     * @return list<string>
     */
    public static function bundles(): array
    {
        if (null !== self::$bundles) {
            return self::$bundles;
        }

        $bundles = [];

        foreach (array_keys(self::all()) as $name) {
            $theme = self::get($name);

            if (null !== $theme) {
                $bundles[] = $theme->bundle();
            }
        }

        return self::$bundles = array_values(array_unique($bundles));
    }

    /**
     * A fresh instance of the named module-carried theme, or null.
     */
    public static function get(string $name): ?ThemeModule
    {
        $class = self::all()[$name] ?? null;

        if (null === $class) {
            return null;
        }

        try {
            $theme = new $class();
        } catch (\Throwable $e) {
            error_log(sprintf('[ThemeRegistry] theme %s failed to construct: %s', $class, $e->getMessage()));

            return null;
        }

        return $theme;
    }

    /**
     * Text-first, like every other registry here: a file that does not even
     * mention ThemeModule is never loaded.
     *
     * @return array{class-string<ThemeModule>, string}|null [class, name]
     */
    private static function loadThemeClass(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content)
            || !str_contains($content, 'ThemeModule')
            || 1 !== preg_match('/\bimplements\b[^{]*\bThemeModule\b/', $content)
        ) {
            return null;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return null;
        }

        try {
            if (!class_exists($fqcn)) {
                require_once $file;
            }

            if (!class_exists($fqcn)) {
                return null;
            }

            $reflection = new \ReflectionClass($fqcn);

            if (!$reflection->implementsInterface(ThemeModule::class)
                || $reflection->isAbstract()
                || $reflection->getConstructor()?->getNumberOfRequiredParameters() > 0
            ) {
                return null;
            }

            /** @var ThemeModule $instance */
            $instance = $reflection->newInstance();
            $name = $instance->name();

            if ('' === $name) {
                return null;
            }

            /** @var class-string<ThemeModule> $fqcn */
            return [$fqcn, $name];
        } catch (\Throwable $e) {
            error_log(sprintf('[ThemeRegistry] skipping %s: %s', $file, $e->getMessage()));

            return null;
        }
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else.
     *
     * A declaration starts a LINE (after optional whitespace and the modifiers
     * PHP allows on a type) or a STATEMENT (after a `;`), so a compact
     * `namespace Demo; class Thing {}` file still resolves — the failure mode
     * here is silence, not an error, and a heuristic that is too strict trades
     * one silent skip for another.
     *
     * What cannot be a declaration is PROSE. This used to take the FIRST mention
     * of the keyword anywhere in the file, so a docblock sentence like "This
     * class says the one thing..." resolved a type named `says`: the class did
     * not exist, the file was skipped with no log line, and the theme silently
     * disappeared — measured on the Settings shell provider, whose nav menu
     * vanished exactly that way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all four
     * registries that share this shape.
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

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }
}
