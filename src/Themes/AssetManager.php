<?php

declare(strict_types = 1);

namespace Themes;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * AssetManager — asset manifest reader for PHP templates (task 10.5).
 *
 * Resolves hashed JS/CSS URLs from the Vite manifest of the requested theme
 * bundle (public/dist/<bundle>/manifest.json), built from the
 * `Frontend/packages` pnpm workspace where every theme is its own package.
 * Returns null / empty arrays when the manifest is missing so templates
 * degrade gracefully before a build has run.
 *
 * Usage:
 *   $assets = new AssetManager('kernel');
 *   $jsUrl  = $assets->jsUrl('main');      // '/dist/kernel/assets/main-xxx.js'
 *   $cssUrls = $assets->cssUrls('main');   // ['/dist/kernel/assets/main-xxx.css']
 *
 * (Both null here — the kernel ships no build. The example shows the shape,
 * not a bundle this tree has.)
 */
final class AssetManager
{
    /**
     * Known bundles — one per theme package under `Frontend/packages/`.
     * Anything outside the list falls back to `kernel` so a stale or
     * hand-written bundle name still resolves to the theme this tree has.
     *
     * The list is DERIVED from the runtime theme set, not written out here.
     * It used to be a literal, and that literal was the fourth place a theme
     * has to be registered: a theme wired into `ThemeResolver`,
     * `ThemeKernel`, the Vite factory and every parity guard still rendered
     * the DEFAULT theme's stylesheet and script, because this one missed it.
     * Nothing errored and nothing was logged — a theme whose entire CSS was
     * another theme's is indistinguishable from a theme that works, right up
     * until a reader opens the page. `ThemeBundleDiscoveryTest` now fails when
     * a discovered theme's bundle is missing from this list, and
     * `ErikThemeTest` pins the page it produces.
     *
     * `minimal` is in the set and not in the public whitelist on purpose: it
     * ships no markup and no route can name it, but its package still builds
     * and `BuildCssAuditTest` audits its token family.
     *
     * @return list<string>
     */
    public static function knownBundles(): array
    {
        return array_values(array_unique([
            ThemeResolver::ADMIN_THEME,
            ...ThemeResolver::PUBLIC_WHITELIST,
            'minimal',
            // A theme that arrives with a module brings its bundle with it, so
            // the bundles this tree has are discovered the same way its themes
            // are. Without this the list is a statement about the KERNEL's own
            // themes, and every module-carried one resolves to the kernel
            // bundle: the manifest under public/dist/kernel/ does not exist, so
            // the built stylesheet is never inlined and the page renders with
            // the critical subset only. Measured on the `erik` theme — 706
            // chars of CSS inlined where its build has 35 258.
            ...ThemeRegistry::bundles(),
        ]));
    }

    private readonly string $bundlePrefix;

    /** @var array<string, array{file?: string, css?: list<string>, imports?: list<string>}> */
    private array $manifest;

    public function __construct(?string $bundle = 'kernel')
    {
        $resolved = (null === $bundle || '' === $bundle) ? 'kernel' : $bundle;
        // Every theme package builds into its own dist directory, so the
        // bundle name maps straight onto the manifest path. Unknown names
        // fall back to the kernel: the one theme this tree always has.
        $manifestBundle = in_array($resolved, self::knownBundles(), true) ? $resolved : 'kernel';
        $this->bundlePrefix = '/dist/' . $manifestBundle . '/';

        $manifestPath = AppPaths::instance()->public('dist/' . $manifestBundle . '/manifest.json');

        /** @var array<string, array{file?: string, css?: list<string>, imports?: list<string>}> $decoded */
        $decoded = [];

        if (is_file($manifestPath)) {
            $content = file_get_contents($manifestPath);
            if (false !== $content) {
                $parsed = json_decode($content, true);
                if (is_array($parsed)) {
                    /** @var array<string, array{file?: string, css?: list<string>, imports?: list<string>}> $decoded */
                    $decoded = $parsed;
                }
            }
        }

        $this->manifest = $decoded;
    }

    /**
     * Resolve the hashed JS file URL for a manifest entry key.
     */
    public function jsUrl(string $key): ?string
    {
        $entry = $this->manifestEntry($key);
        $file = $entry['file'] ?? null;

        return is_string($file) && '' !== $file ? $this->bundlePrefix . $file : null;
    }

    /**
     * Resolve the hashed CSS file URLs for a manifest entry key.
     *
     * @return list<string>
     */
    public function cssUrls(string $key): array
    {
        $css = $this->manifestEntry($key)['css'] ?? [];

        $urls = [];
        foreach ($css as $file) {
            if ('' !== $file) {
                $urls[] = $this->bundlePrefix . $file;
            }
        }

        return $urls;
    }

    /**
     * Manifest entry for a logical entry key ('main', 'mark', 'erik', …).
     *
     * Vite 8 keys the manifest by the resolved source path
     * ('src/main.ts', 'src/magazine.ts', 'src/mark/index.css', …) rather than
     * the rollupOptions input key ('main', …). Look up both forms so the
     * templates can keep the short logical key regardless of the Vite version
     * — and whether the entry is a single file or a split section chain.
     *
     * @return array{file?: string, css?: list<string>, imports?: list<string>}
     */
    private function manifestEntry(string $key): array
    {
        $candidates = [$key, 'src/' . $key . '.ts', 'src/' . $key . '.css', 'src/' . $key . '/index.css'];

        foreach ($candidates as $candidate) {
            $entry = $this->manifest[$candidate] ?? null;

            if (is_array($entry)) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Whether the manifest for this bundle is available (build has run).
     */
    public function isAvailable(): bool
    {
        return [] !== $this->manifest;
    }
}
