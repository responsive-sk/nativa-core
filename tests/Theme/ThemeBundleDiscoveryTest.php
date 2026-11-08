<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ThemeBundleDiscoveryTest (Boot 22) — the door the `erik` defect came through,
 * closed for the next theme.
 *
 * Wiring a theme touches four places: `ThemeResolver` (the whitelist),
 * `ThemeKernel` (the module table), the Vite factory (the build port) and the
 * guards beside this file. `AssetManager` used to hold a FIFTH copy of that
 * knowledge — a literal bundle list naming the kernel's own themes — and a
 * theme outside it silently resolved the kernel bundle, found no
 * `public/dist/kernel/manifest.json`, and inlined its critical subset instead
 * of its build. Nothing threw, nothing logged, and the page looked styled.
 *
 * The list is derived from the runtime theme set now. This test keeps it
 * derived: it walks the SAME discovery `ThemeResolver` uses and fails when a
 * discovered theme's bundle is not something `AssetManager` can resolve — and
 * when a discovered theme's rendered document does not carry its own build.
 *
 * Deliberately NOT asserted here: that every known bundle has a package under
 * `Frontend/packages/`. In this tree `kernel` (hand-written, no build) and a
 * discovered theme whose package is not vendored are legitimately in the list
 * without one, and `AssetManager` degrades to critical CSS for both. The
 * direction that bit is the one asserted.
 *
 * Kernel-only here, like the rest of `tests/`: nothing in this file names a
 * module's class, so a tree with no theme installed still analyses and runs it
 * — the discovered set is simply empty and only the kernel's own bundles are
 * checked.
 */
final class ThemeBundleDiscoveryTest extends TestCase
{
    #[Test]
    public function everyDiscoveredThemesBundleIsAKnownAssetBundle(): void
    {
        $known = AssetManager::knownBundles();

        // The kernel's own set is present whatever is installed — a module
        // cannot remove the fallback by being absent.
        foreach ([ThemeResolver::ADMIN_THEME, ...ThemeResolver::PUBLIC_WHITELIST, 'minimal'] as $bundle) {
            $this->assertContains($bundle, $known, 'The kernel\'s own bundles are always resolvable.');
        }

        $unknown = [];

        foreach (array_keys(ThemeRegistry::all()) as $name) {
            $theme = ThemeRegistry::get($name);

            if (null === $theme) {
                continue; // an unconstructable theme is skipped by the registry, with a log line
            }

            if (!in_array($theme->bundle(), $known, true)) {
                $unknown[] = sprintf('%s (bundle `%s`)', $name, $theme->bundle());
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'A discovered theme declares a bundle AssetManager does not know. It'
            . ' resolves to THE KERNEL BUNDLE instead — a bundle this tree has no'
            . ' build for — so the layout inlines the critical subset where the'
            . ' theme\'s stylesheet should be, and nothing fails: ' . implode(', ', $unknown),
        );
    }

    /**
     * The rendered half, for every discovered theme that has a build: the
     * document's `<style>` block IS the theme's own stylesheet.
     */
    #[Test]
    public function everyDiscoveredThemeWithABuildRendersItsOwnStylesheet(): void
    {
        $checked = [];

        foreach (array_keys(ThemeRegistry::all()) as $name) {
            $theme = ThemeRegistry::get($name);

            if (null === $theme || !is_dir(AppPaths::instance()->join('Frontend', 'packages', $theme->bundle()))) {
                continue; // no package, no build to compare against
            }

            // Guard on the manifest ON DISK, never on
            // `AssetManager::isAvailable()`: the unknown-bundle defect is
            // exactly what makes that call false, so guarding on it would turn
            // this test into a skip at the moment it has something to say.
            if (!is_file(AppPaths::instance()->public('dist/' . $theme->bundle() . '/manifest.json'))) {
                continue; // the package exists, the build has not been run
            }

            $assets = new AssetManager($theme->bundle());

            $this->assertTrue(
                $assets->isAvailable(),
                sprintf(
                    'Bundle `%s` has a manifest on disk that AssetManager did not resolve — the bundle is not known to it.',
                    $theme->bundle(),
                ),
            );

            $url = $assets->cssUrls($theme->jsKey())[0] ?? null;

            if (null === $url) {
                continue; // the entry ships JS only
            }

            $build = (string) file_get_contents(AppPaths::instance()->public(ltrim($url, '/')));
            $html = FrontendLayout::render('Test', '<p>x</p>', theme: $name);

            $this->assertStringContainsString(
                '<body class="' . $name . '-shell">',
                $html,
                sprintf('The document rendered for `%s` does not wear its own shell class.', $name),
            );

            $this->assertStringContainsString(
                $build,
                $html,
                sprintf(
                    'The document rendered for the discovered theme `%s` does not inline bundle `%s` —'
                    . ' the layout resolved another bundle and fell back to critical CSS.',
                    $name,
                    $theme->bundle(),
                ),
            );

            $checked[] = $name;
        }

        if ([] === $checked) {
            $this->markTestSkipped('No discovered theme has a build under public/dist — run `make assets`.');
        }
    }
}
