<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Layout\ThemeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ThemeAssetCompletenessTest — every theme has the assets it claims, and
 * every gap in syntax highlighting is named.
 *
 * Two halves, deliberately different in strictness:
 *
 * 1. Existence is strict and universal: every criticalCss() path of every
 *    discovered theme resolves to a file on disk. This forces no uniformity
 *    — a theme may ship one file or ten — it only catches moved files and
 *    typos, the defects that render as silently unstyled pages.
 *
 * 2. Highlight is fail-closed, not uniform: a theme passes when its Frontend
 *    package bundles code-highlight OR its own module assets carry the
 *    capability (tok-* styles, a highlight loader). Otherwise it must be
 *    named in WITHOUT_HIGHLIGHT with the reason. `kernel` is listed there
 *    with its reason (no JS by design, pinned by KernelThemeTest); `minimal`
 *    is not a theme module and never appears. A NEW theme without highlight fails
 *    until its author lists it with a reason — the gap Mono had, found by
 *    eye instead of by a test.
 *
 * `ThemeRegistry` is the catalogue's `Layout` slice: the dev autoloader
 * resolves it by name, but nothing installs it in a kernel-only checkout — the
 * discovered set is empty there, and every loop below skips. Themes arrive as
 * objects; everything else is text over file bodies.
 */
final class ThemeAssetCompletenessTest extends TestCase
{
    /**
     * Discovered themes without client-side syntax highlighting, with why.
     *
     * Mono used to be listed (vendored, no build); mo-code-highlight.js
     * closed it, and the stale-exemption assertion below keeps the list
     * honest in that direction too.
     *
     * @var array<string, string> theme name => reason
     */
    private const array WITHOUT_HIGHLIGHT = [
        'kernel' => 'no JS by design: one hand-written CSS file, no build (KernelThemeTest pins it)',
        'nativa' => 'the house theme bundles one behaviour (the TOC scrollspy) and imports no highlighter, so code blocks render in one colour',
    ];

    #[Test]
    public function everyDiscoveredThemesCriticalCssExistsOnDisk(): void
    {
        $checked = [];

        foreach (ThemeRegistry::all() as $name => $class) {
            $theme = ThemeRegistry::get($name);

            if (null === $theme) {
                continue; // an unconstructable theme is skipped by the registry, with a log line
            }

            foreach ($theme->criticalCss() as $path) {
                // The declared path names the module, not its layout: a theme
                // may be installed under a group, and `moduleFile()` is the
                // resolver that knows (the same one `InlineCss` uses).
                $this->assertFileExists(
                    AppPaths::instance()->moduleFile($path),
                    sprintf('Theme `%s` declares critical CSS `%s` that is not on disk.', $name, $path),
                );
            }

            $checked[] = $name;
        }

        if ([] === $checked) {
            $this->markTestSkipped('No theme installed — nothing to check.');
        }
    }

    #[Test]
    public function everyDiscoveredThemeShipsHighlightOrDeclaresWhyNot(): void
    {
        $without = self::WITHOUT_HIGHLIGHT;

        $checked = [];
        $undeclared = [];

        foreach (ThemeRegistry::all() as $name => $class) {
            $theme = ThemeRegistry::get($name);

            if (null === $theme) {
                continue;
            }

            $checked[] = $name;

            $has = self::packageShipsHighlight($theme->bundle())
                || self::moduleShipsHighlight($theme);
            $reason = $without[$name] ?? null;

            $this->assertFalse(
                $has && null !== $reason,
                sprintf('Theme `%s` ships highlighting but is still exempted (%s) — drop the stale entry.', $name, (string) $reason),
            );

            if (!$has && null === $reason) {
                $undeclared[] = $name;
            }
        }

        if ([] === $checked) {
            $this->markTestSkipped('No theme installed — nothing to check.');
        }

        $this->assertSame(
            [],
            $undeclared,
            'Theme(s) without syntax highlighting and without a declared reason: ' . implode(', ', $undeclared)
            . ' — list them in WITHOUT_HIGHLIGHT with why, or ship the capability.',
        );
    }

    /**
     * The detector proves itself on a fixture, in both directions: markers
     * found, and absence reported. Without this, a detector stuck on true
     * would green the wiring test above while measuring nothing.
     */
    #[Test]
    public function theHighlightDetectorFindsMarkersAndAbsence(): void
    {
        $dir = sys_get_temp_dir() . '/nativa-hl-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o775, true);

        try {
            file_put_contents($dir . '/plain.css', '.a{color:red}');

            $this->assertFalse(
                self::dirShipsHighlightMarkers($dir),
                'A directory without markers must report absence.',
            );

            file_put_contents($dir . '/tokens.css', 'main pre .tok-keyword{color:#c084fc}');

            $this->assertTrue(
                self::dirShipsHighlightMarkers($dir),
                'A directory carrying .tok-* styles must report the capability.',
            );
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }
    }

    /**
     * The theme's Frontend package bundles the shared highlighter when any
     * of its TypeScript sources imports it. Source, never dist: the package
     * may exist while the build has never run here.
     */
    private static function packageShipsHighlight(string $bundle): bool
    {
        $src = AppPaths::instance()->join('Frontend', 'packages', $bundle, 'src');

        if (!is_dir($src)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'ts' !== $file->getExtension()) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (is_string($content) && str_contains($content, 'code-highlight')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The theme module carries the capability itself when its shipped assets
     * (never its tests) name token styles or a highlight loader.
     *
     * The directory comes from the CLASS NAMESPACE, not from the first path
     * segment of its file. Themes live in a group (`src/Modules/Themes/Griffe`),
     * so reading one segment answers `Themes` — the whole group — and a theme
     * with no styles of its own inherits whichever sibling happens to ship
     * `tok-*`. Measured on `nativa`, which was credited with Griffe's markers
     * and failed the stale-exemption assertion for a capability it does not
     * have. `Modules\Themes\Griffe\Theme\GriffeTheme` drops the trailing
     * `Theme` segment and leaves exactly the module's own directory.
     */
    private static function moduleShipsHighlight(object $theme): bool
    {
        $segments = explode('\\', (new \ReflectionClass($theme))->getNamespaceName());

        if ('Modules' !== array_shift($segments) || [] === $segments) {
            return false; // a kernel theme: out of scope, owned elsewhere
        }

        array_pop($segments); // the class's own `Theme` namespaced directory

        if ([] === $segments) {
            return false;
        }

        return self::dirShipsHighlightMarkers(
            AppPaths::instance()->src('Modules' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments)),
        );
    }

    /**
     * Shipped CSS/JS carrying token styles or a highlight loader reference.
     * Tests beside the code are excluded — they may name the capability
     * without shipping it.
     */
    private static function dirShipsHighlightMarkers(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (!in_array($file->getExtension(), ['css', 'js'], true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (!is_string($content)) {
                continue;
            }

            if (str_contains($content, 'tok-') || str_contains($content, 'Highlight')) {
                return true;
            }
        }

        return false;
    }
}
