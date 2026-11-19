<?php

declare(strict_types = 1);

namespace Modules\Site\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Site\Application\SiteCopy;
use Modules\Site\Interfaces\HTTP\SiteHomepageSurface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\HomepageSurfaceRegistry;

/**
 * SiteHomepageTest — `/` answers the site's own page, in the visitor's language.
 *
 * Measured before this module existed (2026-11-19, the responsive.sk artifact):
 * `/` served "Keep PHP. Drop the framework." and "Under the hood of Nativa",
 * because the landing came from the theme and its copy is partly written into
 * the theme's code. Translating rows could not reach it.
 *
 * Two things this pins that a copy test alone would not:
 *
 *   1. the ORDER of the port — the surface is discovered and used at all, not
 *      merely constructible;
 *   2. the locale — `/en` answers the English copy, which is what makes the
 *      language switcher in the shell honest on the front door.
 */
final class SiteHomepageTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SiteHomepageSurface::class)) {
            $this->markTestSkipped('Site is not installed — the landing lives in this module.');
        }

        HomepageSurfaceRegistry::reset();
    }

    protected function tearDown(): void
    {
        HomepageSurfaceRegistry::reset();
    }

    #[Test]
    public function theLandingIsTheSitesOwnPageAndNotTheThemesPitch(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString('Weby a aplikácie', $html, 'The Slovak landing copy must render at `/`.');
        self::assertStringContainsString('id="kontakt"', $html, 'The closing call is the page\'s contact target.');
        self::assertStringNotContainsString('Drop the framework', $html, 'The framework pitch is not this site\'s home page.');
        self::assertStringNotContainsString('Under the hood of Nativa', $html);
    }

    #[Test]
    public function theHeadCarriesTheSitesTitleAndItsOwnDescription(): void
    {
        $html = $this->page('/');

        self::assertMatchesRegularExpression(
            '/<title>[^<]* ' . "\u{2014}" . ' weby a aplikácie na mieru<\/title>/',
            $html,
            'The landing title names what the site does, not only the site name.',
        );
        self::assertStringContainsString(
            '<meta name="description" content="Tvoríme weby, e-shopy a webové aplikácie na mieru',
            $html,
            'The landing description comes from the surface, not from the site-wide fallback.',
        );
    }

    #[Test]
    public function theHeadDeclaresTheLanguageAlternatesAndTheShareImage(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<link rel="alternate" hreflang="sk"',
            $html,
            'A bilingual landing with no `sk` alternate is two languages a crawler cannot pair.',
        );
        self::assertStringContainsString('<link rel="alternate" hreflang="en"', $html);
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="x-default"',
            $html,
            'x-default is what a visitor from an unlisted language is served.',
        );
        self::assertMatchesRegularExpression(
            '/<meta property="og:image" content="http:\/\/[^"]+\/og\/home\.png">/',
            $html,
            'A shared link without og:image shows no image at all.',
        );
    }

    #[Test]
    public function theShareImageTheSurfaceNamesActuallyExists(): void
    {
        self::assertFileExists(APP_BASE_PATH . '/public' . SiteCopy::shareImage(), 'The head points at a file the deployment must serve.');
    }

    #[Test]
    public function theEnglishRootRendersTheEnglishCopy(): void
    {
        $html = $this->page('/en');

        self::assertStringContainsString('Websites and apps', $html, '`/en` must answer English copy.');
        self::assertStringNotContainsString('Weby a aplikácie', $html, '`/en` must not answer the Slovak group.');
        self::assertStringContainsString(
            '<html lang="en"',
            $html,
            'English copy under `<html lang="sk">` is a Slovak page as far as a crawler knows.',
        );
    }

    private function page(string $path): string
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', $path));

        self::assertSame(200, $response->getStatusCode(), $path . ' must render.');

        return $response->getContent();
    }
}
