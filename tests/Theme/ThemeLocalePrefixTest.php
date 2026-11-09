<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ThemeLocalePrefixTest — a theme asks the URL-prefix owner, never spells it.
 *
 * Task 3.1/3.2, measured 2026-10-17: every theme's locale switcher fell back
 * to `'/' . $code . $currentPath`, which renders `/sk_SK/blog` for the code
 * `sk_SK`. The owner, `ContentLocaleMapper::urlPrefix()`, answers `/blog` for
 * the default locale and `/en/blog` for the other one — the shape the docs
 * links and the kernel middleware already use. Both forms happened to work,
 * so nothing failed; the tree simply kept two spellings of one URL.
 *
 * The scan is textual over the installed theme files, kernel-only like the
 * rest of tests/: it names no module class, and an empty tree skips rather
 * than passing on nothing. The detector proves itself on a fixture in both
 * directions, so a scan stuck on "clean" cannot green this gate.
 */
final class ThemeLocalePrefixTest extends TestCase
{
    #[Test]
    public function noThemeSpellsALocalePrefixFromTheRawCode(): void
    {
        // Both trees are scanned: the installed slice is what runs, the
        // catalogue in modules/ is what gets reinstalled into it. A theme
        // that regresses only in the catalogue would otherwise be invisible
        // until the next install, which is exactly how drift hides.
        $roots = array_values(array_filter(
            [AppPaths::instance()->src('Modules'), APP_BASE_PATH . '/modules'],
            static fn (string $dir): bool => is_dir($dir),
        ));

        if ([] === $roots) {
            $this->markTestSkipped('No module tree — nothing to scan.');
        }

        $offenders = [];
        $scanned = 0;
        $switchers = 0;

        foreach ($roots as $dir) {
            foreach ($this->themeFiles($dir) as $file) {
                $scanned++;
                $content = file_get_contents($file);

                if (!is_string($content) || !str_contains($content, 'localeLinks')) {
                    continue;
                }

                $switchers++;
                array_push($offenders, ...self::offendersIn($file));
            }
        }

        $this->assertGreaterThan(0, $scanned, 'the scan found no theme files — the path filter stopped working');
        $this->assertGreaterThan(0, $switchers, 'the scan found no locale switcher — the marker filter stopped working');

        $this->assertSame(
            [],
            $offenders,
            "A theme spells the locale URL prefix itself instead of asking its owner:\n"
            . implode("\n", $offenders)
            . "\nUse ContentLocaleServices::mapper()->urlPrefix(\$code) . \$currentPath; the default locale owns the bare path.",
        );
    }

    /**
     * The detector proves itself on a fixture, both ways.
     */
    #[Test]
    public function theDetectorFindsRawCodeLinksAndAbsence(): void
    {
        $dir = sys_get_temp_dir() . '/nativa-theme-prefix-' . bin2hex(random_bytes(6));
        mkdir($dir . '/Theme', 0o775, true);

        try {
            $file = $dir . '/Theme/ProbeTheme.php';

            file_put_contents(
                $file,
                "<?php\nif (null !== \$context->localeLinks) {\n    echo '/' . \$language . \$context->currentPath;\n}\n",
            );

            $this->assertNotEmpty(
                self::offendersIn($file),
                'a raw-code fallback must be reported.',
            );

            file_put_contents(
                $file,
                "<?php\nif (null !== \$context->localeLinks) {\n    echo ContentLocaleServices::mapper()->urlPrefix(\$code) . \$context->currentPath;\n}\n",
            );

            $this->assertSame(
                [],
                self::offendersIn($file),
                'the owner-backed form must pass.',
            );
        } finally {
            foreach (glob($dir . '/Theme/*') ?: [] as $created) {
                @unlink($created);
            }

            @rmdir($dir . '/Theme');
            @rmdir($dir);
        }
    }

    /**
     * @return list<string>
     */
    private function themeFiles(string $dir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();

            if (!str_contains($path, DIRECTORY_SEPARATOR . 'Theme' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * The fallback the switchers carried before 3.1 — a method, not a const:
     * the ownership ratchet counts declarations, and a test fixture is not
     * exempt from "no new constants".
     *
     * @return list<string>
     */
    private static function rawCodePatterns(): array
    {
        return [
            "'/' . \$language .",
            "'/' . \$code .",
            "'/' . self::language(",
        ];
    }

    /**
     * @return list<string>
     */
    private static function offendersIn(string $file): array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'localeLinks')) {
            return [];
        }

        $offenders = [];

        foreach (self::rawCodePatterns() as $pattern) {
            if (str_contains($content, $pattern)) {
                $offenders[] = $file . ' — ' . $pattern;
            }
        }

        if (!str_contains($content, 'urlPrefix(')) {
            $offenders[] = $file . ' — builds locale links without calling urlPrefix()';
        }

        return $offenders;
    }
}
