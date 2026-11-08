<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FontPreloadTest — a webfont the first viewport needs is preloaded, exactly
 * once, before the stylesheet that declares it.
 *
 * A theme that ships its own fonts declares them in its critical CSS, which
 * the layout inlines. The browser therefore reaches the @font-face rules only
 * after parsing up to them, so the font request sits at the end of a chain
 * `HTML → inline CSS → fetch` — what the Lighthouse "network dependency tree"
 * audit flags. The preload takes that fetch out of the chain.
 *
 * The assertions are about the *relation* between the two blocks, not about a
 * theme's font list: whatever the inlined CSS declares must be preloaded, in
 * declaration order, one tag per URL, and nothing else may be. A theme whose
 * CSS declares no webfont at all (kernel) must emit no font preload, so a
 * regex that "found" a placeholder URL fails here rather than in production.
 *
 * Kernel-only by construction: themes arrive through ThemeRegistry, and the
 * CSS is read from disk — no module class is named, and an empty registry
 * skips.
 */
final class FontPreloadTest extends TestCase
{
    #[Test]
    public function everyWebfontTheInlineStylesheetDeclaresIsPreloadedOnce(): void
    {
        $checked = 0;

        foreach (ThemeRegistry::all() as $name => $class) {
            $html = FrontendLayout::render('T', '<p>x</p>', theme: $name);

            $declared = self::declaredFontUrls(self::inlineCss($html));
            $preloaded = self::preloadedUrls($html);

            $this->assertSame(
                $declared,
                $preloaded,
                sprintf('Theme `%s` must preload each declared webfont once, in declaration order.', $name),
            );

            // A preload that 404s is worse than no preload: it spends a
            // connection the first paint needed, on purpose, and logs an
            // error. The declared path is public-relative by contract.
            foreach ($preloaded as $url) {
                $this->assertFileExists(
                    AppPaths::instance()->public(ltrim($url, '/')),
                    sprintf('Theme `%s` preloads `%s`, which is not on disk.', $name, $url),
                );
            }

            $checked++;
        }

        if (0 === $checked) {
            $this->markTestSkipped('No theme installed — nothing to check.');
        }
    }

    #[Test]
    public function preloadTagsCarryTheAttributesAFontFetchNeeds(): void
    {
        $tags = [];

        foreach (ThemeRegistry::all() as $name => $class) {
            preg_match_all('/<link[^>]*rel="preload"[^>]*>/', FrontendLayout::render('T', '<p>x</p>', theme: $name), $matches);
            foreach ($matches[0] as $tag) {
                $tags[$name][] = $tag;
            }
        }

        if ([] === $tags) {
            $this->markTestSkipped('No theme installed — nothing to check.');
        }

        $seen = 0;

        foreach ($tags as $name => $themeTags) {
            foreach ($themeTags as $tag) {
                // `crossorigin` is not decoration: fonts are fetched in CORS
                // mode, so a preload without it is a second, wasted download.
                $this->assertStringContainsString('as="font"', $tag, sprintf('Theme `%s`: preload must declare as="font".', $name));
                $this->assertStringContainsString('type="font/woff2"', $tag, sprintf('Theme `%s`: preload must declare its type.', $name));
                $this->assertStringContainsString('crossorigin', $tag, sprintf('Theme `%s`: font preloads require crossorigin.', $name));
                $this->assertMatchesRegularExpression(
                    '#href="/[^"]+\.woff2"#',
                    $tag,
                    sprintf('Theme `%s`: only same-origin font paths are preloaded.', $name),
                );
                $seen++;
            }
        }

        $this->assertGreaterThan(0, $seen, 'A tree with fonts must actually preload one.');
    }

    #[Test]
    public function preloadsComeBeforeTheStylesheetThatDeclaresThem(): void
    {
        $checked = 0;

        foreach (ThemeRegistry::all() as $name => $class) {
            $html = FrontendLayout::render('T', '<p>x</p>', theme: $name);

            $firstPreload = strpos($html, 'rel="preload"');
            $style = strpos($html, '<style ');

            if (false === $firstPreload) {
                continue;
            }

            $this->assertNotFalse($style, sprintf('Theme `%s` rendered a preload without a stylesheet.', $name));
            $this->assertLessThan(
                $style,
                $firstPreload,
                sprintf('Theme `%s`: a preload after the stylesheet cannot shorten the chain.', $name),
            );

            $checked++;
        }

        if (0 === $checked) {
            $this->markTestSkipped('No installed theme declares a webfont.');
        }
    }

    #[Test]
    public function aThemeWithoutWebfontsPreloadsNothing(): void
    {
        $html = FrontendLayout::render('T', '<p>x</p>', theme: ThemeResolver::KERNEL_THEME);

        if (!str_contains($html, '<style ')) {
            $this->markTestSkipped('The kernel theme did not render in this tree.');
        }

        if ('' !== self::inlineCss($html) && str_contains(self::inlineCss($html), '@font-face')) {
            $this->markTestSkipped('The kernel theme ships webfonts in this tree — nothing to prove here.');
        }

        $this->assertStringNotContainsString('rel="preload"', $html);
    }

    /** The inline <style> body the layout emitted. */
    private static function inlineCss(string $html): string
    {
        preg_match('#<style[^>]*>(.*?)</style>#s', $html, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Same-origin woff2 URLs the inline CSS declares, in declaration order and
     * without repetition (a family may declare two weights over one file).
     *
     * @return list<string>
     */
    private static function declaredFontUrls(string $css): array
    {
        preg_match_all('#@font-face\s*\{[^}]*?url\(\s*[\'"]?(/[^\'")]+\.woff2)[\'"]?\s*\)#i', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Font preload hrefs in the rendered head, in document order.
     *
     * @return list<string>
     */
    private static function preloadedUrls(string $html): array
    {
        preg_match_all('#<link[^>]*rel="preload"[^>]*href="([^"]+)"[^>]*>#', $html, $matches);

        return $matches[1];
    }
}
