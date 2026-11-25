<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Themes\FrontendLayout;
use Themes\PageSurface;
use Themes\ThemeModule;
use Themes\ThemeRegistry;
use Themes\ThemeResolver;

/**
 * PageAssetBudgetTest — a page carries its own surface's assets, and no more.
 *
 * The expectation is read off the BUILD, not off the renderer: the test reads
 * `public/dist/<bundle>/manifest.json` and the CSS files it names, joins them
 * in cascade order, and compares that string with the bytes inside the
 * document's `<style>` block. A test that asked `FrontendLayout` what it was
 * about to inline would agree with any bug the layout had.
 *
 * Which themes narrow is a declaration, and the declaration lives in
 * `config/page-budget.json`: a surface row equal to the theme's `page` row says
 * "this surface stays in the base sheet", a lower row says "this surface has
 * its own entry". The manifest then has to agree with the budget, so a theme
 * cannot claim a split it did not build, and a split cannot arrive without a
 * ceiling.
 *
 * Kernel-only, like the rest of `tests/`: a theme is discovered through
 * `ThemeRegistry`, and an environment with no built theme skips rather than
 * fails. The split tests measure only themes whose build exists, but at least
 * one built theme must narrow each surface, or a green suite would mean nobody
 * was measured. The fallback test needs no build: it compares two renders of a
 * theme whose manifest carries no surface entry, and the kernel theme always
 * supplies that subject without one.
 */
final class PageAssetBudgetTest extends TestCase
{
    /** @var list<string>|null installed theme names, resolved once */
    private static ?array $themes = null;

    protected function setUp(): void
    {
        if ([] === self::themes()) {
            $this->markTestSkipped('No theme module is installed — run `php bin/cms module:add Themes` and the theme slice.');
        }

        // The kernel theme is always discovered (it is the fallback), but it ships
        // no bundle. A tree whose only theme has no build has nothing to measure:
        // the kernel-only export is exactly that tree, and its suite must skip
        // here rather than demand a split the exported catalogue is not
        // installed to produce. The per-surface guards below still fail when a
        // build exists but no theme narrows that surface.
        foreach (self::themes() as $name) {
            if ($this->hasBuild($name)) {
                return;
            }
        }

        $this->markTestSkipped('No installed theme has a build under public/dist — install a theme and run `make assets` before the surface budget can be measured.');
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even after a skipped setUp(); keep it a no-op
        // so the skip is not followed by a second failure.
    }

    #[Test]
    public function anArticlePageCarriesOnlyTheArticleAssets(): void
    {
        $narrowed = $this->themesWithEntry(PageSurface::ARTICLE);

        $this->assertNotSame(
            [],
            $narrowed,
            'No built theme emits an `-article` entry, so every article page still carries its hub\'s whole sheet. '
            . 'Build one theme\'s article split before the suite can call this narrowed.',
        );

        foreach ($narrowed as $name) {
            $html = FrontendLayout::render('Test', '<p>x</p>', theme: $name, surface: PageSurface::ARTICLE);
            $expected = $this->expectedCss($name, PageSurface::ARTICLE);

            $this->assertSame(
                $expected,
                $this->inlinedCss($html),
                sprintf(
                    '`%s` article page does not inline the base sheet followed by `%s`: the page carries other bytes than its surface names.',
                    $name,
                    PageSurface::ARTICLE,
                ),
            );

            $base = $this->jsUrls($name, null);
            $article = $this->jsUrls($name, PageSurface::ARTICLE);

            $this->assertSame(
                array_values(array_unique([...$base, ...$article])),
                $this->taggedScripts($html),
                sprintf('`%s` article page does not tag the base script plus its article script.', $name),
            );
        }
    }

    #[Test]
    public function aListingPageCarriesNoContentsRail(): void
    {
        $narrowed = $this->themesWithEntry(PageSurface::LISTING);

        $this->assertNotSame(
            [],
            $narrowed,
            'No built theme emits a `-listing` entry, so a listing page still carries the article sheet its theme ships whole.',
        );

        foreach ($narrowed as $name) {
            $html = FrontendLayout::render('Test', '<p>x</p>', theme: $name, surface: PageSurface::LISTING);
            $articleBytes = $this->sheetBytes($name, PageSurface::ARTICLE);

            $this->assertSame(
                $this->expectedCss($name, PageSurface::LISTING),
                $this->inlinedCss($html),
                sprintf('`%s` listing page does not inline exactly its base sheet plus its listing sheet.', $name),
            );

            if ('' !== $articleBytes) {
                $this->assertStringNotContainsString(
                    $articleBytes,
                    $html,
                    sprintf(
                        '`%s` listing page carries the article sheet, so the reading rail and the comment rules are inlined on an index.',
                        $name,
                    ),
                );
            }
        }
    }

    #[Test]
    public function theHomepageCarriesNoArticleRules(): void
    {
        $narrowed = $this->themesWithEntry(PageSurface::HOME);

        $this->assertNotSame(
            [],
            $narrowed,
            'No built theme emits a `-home` entry, so the landing page still carries every ruleset the theme has.',
        );

        foreach ($narrowed as $name) {
            $html = FrontendLayout::render('Test', '<p>x</p>', theme: $name, surface: PageSurface::HOME);

            $this->assertSame(
                $this->expectedCss($name, PageSurface::HOME),
                $this->inlinedCss($html),
                sprintf('`%s` homepage does not inline exactly its base sheet plus its home sheet.', $name),
            );

            // The ceiling below is an upper bound, so a homepage that stopped
            // tagging its own script would still measure under it. Compare the
            // tags instead: the landing's behaviour is the reason the entry
            // exists, and losing it costs no bytes a budget would notice.
            $this->assertSame(
                array_values(array_unique([...$this->jsUrls($name, null), ...$this->jsUrls($name, PageSurface::HOME)])),
                $this->taggedScripts($html),
                sprintf('`%s` homepage does not tag the base script plus its home script.', $name),
            );

            $articleBytes = $this->sheetBytes($name, PageSurface::ARTICLE);

            if ('' !== $articleBytes) {
                $this->assertStringNotContainsString(
                    $articleBytes,
                    $html,
                    sprintf(
                        '`%s` homepage inlines the article sheet, so a landing page carries the reading rail, the comment list and the member rules.',
                        $name,
                    ),
                );
            }

            foreach ($this->taggedScripts($html) as $url) {
                $this->assertStringNotContainsString(
                    '-' . PageSurface::ARTICLE . '-',
                    $url,
                    sprintf('`%s` homepage tags an article-only script: %s', $name, $url),
                );
            }
        }
    }

    #[Test]
    public function aThemeWithoutSurfaceEntriesKeepsItsPage(): void
    {
        $checked = [];

        foreach (self::themes() as $name) {
            // An unbuilt theme other than the kernel still renders its critical
            // sheets, so its two surfaces cannot be compared from the manifest.
            if (ThemeResolver::KERNEL_THEME !== $name && !$this->hasBuild($name)) {
                continue;
            }

            if ([] !== $this->themesWithEntry(PageSurface::ARTICLE, $name)
                || [] !== $this->themesWithEntry(PageSurface::HOME, $name)
                || [] !== $this->themesWithEntry(PageSurface::LISTING, $name)) {
                continue;
            }

            $plain = FrontendLayout::render('Test', '<p>x</p>', theme: $name);
            $article = FrontendLayout::render('Test', '<p>x</p>', theme: $name, surface: PageSurface::ARTICLE);

            $this->assertSame(
                $plain,
                $article,
                sprintf(
                    '`%s` declares no surface entry, so its page must be byte-identical whatever surface it is asked for; it changed.',
                    $name,
                ),
            );

            $checked[] = $name;
        }

        $this->assertNotSame(
            [],
            $checked,
            'No theme without a surface entry was found, so nothing proves the fallback page stays whole.',
        );
    }

    #[Test]
    public function everySurfaceHasACeiling(): void
    {
        $budgets = $this->budgets();
        $missing = [];

        foreach (self::themes() as $name) {
            foreach (PageSurface::all() as $surface) {
                if (!isset($budgets[$name][$surface]['css'], $budgets[$name][$surface]['js'])) {
                    $missing[] = $name . '.' . $surface;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These theme/surface pairs have no ceiling in config/page-budget.json, so nothing would notice them growing: '
            . implode(', ', $missing),
        );
    }

    #[Test]
    public function noPageExceedsItsInlinedCeiling(): void
    {
        $budgets = $this->budgets();
        $measured = 0;
        $over = [];

        foreach (self::themes() as $name) {
            if (!$this->hasBuild($name) || !isset($budgets[$name])) {
                continue;
            }

            foreach (PageSurface::all() as $surface) {
                $html = FrontendLayout::render('Test', '<p>x</p>', theme: $name, surface: $surface);
                $css = strlen($this->inlinedCss($html));
                $js = 0;

                foreach ($this->taggedScripts($html) as $url) {
                    $path = AppPaths::instance()->public(ltrim($url, '/'));
                    $js += is_file($path) ? (int) filesize($path) : 0;
                }

                $measured++;

                $ceilingCss = $budgets[$name][$surface]['css'];
                $ceilingJs = $budgets[$name][$surface]['js'];

                if ($css > $ceilingCss) {
                    $over[] = sprintf('%s.%s CSS %d > %d', $name, $surface, $css, $ceilingCss);
                }

                if ($js > $ceilingJs) {
                    $over[] = sprintf('%s.%s JS %d > %d', $name, $surface, $js, $ceilingJs);
                }
            }
        }

        if (0 === $measured) {
            $this->markTestSkipped('No theme has a build under public/dist — run `make assets`.');
        }

        $this->assertSame(
            [],
            $over,
            'A page carries more bytes than its recorded ceiling. Move the rules to the surface that reads them, or record the growth in config/page-budget.json as a decision: '
            . implode('; ', $over),
        );
    }

    /**
     * Installed theme names, resolved once.
     *
     * @return list<string>
     */
    private static function themes(): array
    {
        self::$themes ??= array_keys(ThemeRegistry::all());

        return self::$themes;
    }

    /**
     * Theme names that the manifest says emit an entry for one surface, limited
     * to the themes named in `$only` when one is given.
     *
     * @return list<string>
     */
    private function themesWithEntry(string $surface, ?string $only = null): array
    {
        $found = [];

        foreach (self::themes() as $name) {
            if (null !== $only && $name !== $only) {
                continue;
            }

            if (!$this->hasBuild($name)) {
                continue;
            }

            if (null !== $this->entry($name, $surface)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * One theme's surface manifest entry, read from disk.
     *
     * @return array{file?: string, css?: list<string>}|null
     */
    private function entry(string $name, string $surface): ?array
    {
        $key = $this->jsKey($name);

        if (null === $key) {
            return null;
        }

        foreach ([$key . '-' . $surface, 'src/' . $key . '-' . $surface . '.ts', 'src/' . $key . '-' . $surface . '.css'] as $candidate) {
            $entry = $this->manifest($name)[$candidate] ?? null;

            if (is_array($entry)) {
                /** @var array{file?: string, css?: list<string>} $entry */
                return $entry;
            }
        }

        return null;
    }

    /**
     * The inlined CSS a page of one surface must carry: the base entry's files,
     * then the surface entry's, each followed by a newline — the same join the
     * renderer performs, derived here from the manifest instead of from it.
     */
    private function expectedCss(string $name, string $surface): string
    {
        $css = $this->entryCss($name, $this->baseEntry($name));
        $css .= $this->entryCss($name, $this->entry($name, $surface));

        return $css;
    }

    /**
     * Raw bytes of one surface's own sheet, '' when the build has none.
     */
    private function sheetBytes(string $name, string $surface): string
    {
        return $this->entryCss($name, $this->entry($name, $surface));
    }

    /**
     * Concatenated file bytes of a manifest entry, each followed by a newline.
     *
     * @param array{file?: string, css?: list<string>}|null $entry
     */
    private function entryCss(string $name, ?array $entry): string
    {
        if (null === $entry) {
            return '';
        }

        $files = $entry['css'] ?? [];

        // A CSS-only entry names its sheet in `file` (measured 2026-11-24).
        if ([] === $files && isset($entry['file']) && str_ends_with($entry['file'], '.css')) {
            $files = [$entry['file']];
        }

        $css = '';
        foreach ($files as $file) {
            $path = $this->distPath($name) . '/' . $file;

            if (is_file($path)) {
                $css .= (string) file_get_contents($path) . "\n";
            }
        }

        return $css;
    }

    /**
     * The base entry of a theme, read from the manifest by its own key.
     *
     * @return array{file?: string, css?: list<string>}|null
     */
    private function baseEntry(string $name): ?array
    {
        $key = $this->jsKey($name);

        if (null === $key) {
            return null;
        }

        foreach ([$key, 'src/' . $key . '.ts', 'src/' . $key . '.css'] as $candidate) {
            $entry = $this->manifest($name)[$candidate] ?? null;

            if (is_array($entry)) {
                /** @var array{file?: string, css?: list<string>} $entry */
                return $entry;
            }
        }

        return null;
    }

    /**
     * The JS URLs a page of one surface tags, derived from the manifest.
     *
     * @return list<string>
     */
    private function jsUrls(string $name, ?string $surface): array
    {
        $key = $this->jsKey($name);

        if (null === $key) {
            return [];
        }

        $urls = [];
        $base = $this->entryFile($this->baseEntry($name));

        if (null !== $base && str_ends_with($base, '.js')) {
            $urls[] = '/dist/' . $this->bundle($name) . '/' . $base;
        }

        if (null === $surface) {
            return $urls;
        }

        $surfaceFile = $this->entryFile($this->entry($name, $surface));

        if (null !== $surfaceFile && str_ends_with($surfaceFile, '.js')) {
            $urls[] = '/dist/' . $this->bundle($name) . '/' . $surfaceFile;
        }

        return $urls;
    }

    /**
     * @param array{file?: string, css?: list<string>}|null $entry
     */
    private function entryFile(?array $entry): ?string
    {
        $file = $entry['file'] ?? null;

        return is_string($file) && '' !== $file ? $file : null;
    }

    /**
     * The `<style>` block's bytes, without the surrounding tags and rows.
     */
    private function inlinedCss(string $html): string
    {
        $matched = preg_match('#<style nonce="__CSP_NONCE_PLACEHOLDER__">\n(.*)\n</style>#s', $html, $matches);

        $this->assertSame(1, $matched, 'The rendered document has no inlined `<style>` block to measure.');

        return $matches[1];
    }

    /**
     * Deferred `<script src>` URLs, in document order.
     *
     * @return list<string>
     */
    private function taggedScripts(string $html): array
    {
        preg_match_all('#<script src="([^"]+)"#', $html, $matches);

        /** @var list<string> $urls */
        $urls = $matches[1];

        return $urls;
    }

    /**
     * `config/page-budget.json`, keyed by theme and surface.
     *
     * @return array<string, array<string, array{css: int, js: int}>>
     */
    private function budgets(): array
    {
        $path = AppPaths::instance()->base() . '/config/page-budget.json';
        $this->assertFileExists($path, '`config/page-budget.json` is the one owner of the ceilings.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        /** @var array<string, array<string, array{css: int, js: int}>> $ceilings */
        $ceilings = $decoded['ceilings'] ?? [];

        return $ceilings;
    }

    private function hasBuild(string $name): bool
    {
        return is_file($this->distPath($name) . '/manifest.json');
    }

    private function distPath(string $name): string
    {
        return AppPaths::instance()->public('dist/' . $this->bundle($name));
    }

    /**
     * The manifest of a theme's bundle, decoded.
     *
     * @return array<string, array{file?: string, css?: list<string>, isEntry?: bool}>
     */
    private function manifest(string $name): array
    {
        $path = $this->distPath($name) . '/manifest.json';

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, array{file?: string, css?: list<string>, isEntry?: bool}> $decoded */
        return $decoded;
    }

    private function bundle(string $name): string
    {
        return $this->theme($name)?->bundle() ?? $name;
    }

    private function jsKey(string $name): ?string
    {
        return $this->theme($name)?->jsKey();
    }

    private function theme(string $name): ?ThemeModule
    {
        return ThemeRegistry::get($name);
    }
}
