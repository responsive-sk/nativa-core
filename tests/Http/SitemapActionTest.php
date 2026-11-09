<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\SitemapAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Seo\SitemapContributor;
use Seo\SitemapContributorRegistry;
use Seo\SitemapEntry;

final class SitemapActionTest extends TestCase
{
    #[Test]
    public function anEmptyContributorRegistryStillServesTheKernelSitemap(): void
    {
        $body = self::render(SitemapContributorRegistry::fromContributors([]));

        self::assertStringContainsString('<loc>http://localhost/</loc>', $body);
        self::assertStringNotContainsString('/blog', $body);
        self::assertStringNotContainsString('/about', $body);
    }

    #[Test]
    public function aContributorAddsItsOwnPath(): void
    {
        $body = self::render(SitemapContributorRegistry::fromContributors([
            self::contributor([new SitemapEntry('/blog/probe', 'weekly', '0.8')]),
        ]));

        self::assertStringContainsString('<loc>http://localhost/blog/probe</loc>', $body);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $body);
        self::assertStringContainsString('<priority>0.8</priority>', $body);
    }

    #[Test]
    public function theKernelHomeWinsADuplicatePath(): void
    {
        $body = self::render(SitemapContributorRegistry::fromContributors([
            self::contributor([new SitemapEntry('/'), new SitemapEntry('/blog/probe')]),
        ]));

        self::assertSame(1, substr_count($body, '<loc>http://localhost/</loc>'));
        self::assertSame(1, substr_count($body, '<loc>http://localhost/blog/probe</loc>'));
    }

    #[Test]
    public function aPathIsXmlEscaped(): void
    {
        $body = self::render(SitemapContributorRegistry::fromContributors([
            self::contributor([new SitemapEntry('/blog/a&b')]),
        ]));

        self::assertStringContainsString('<loc>http://localhost/blog/a&amp;b</loc>', $body);
    }

    private static function render(SitemapContributorRegistry $registry): string
    {
        return (new SitemapAction($registry))
            ->handle(Request::create('GET', '/sitemap.xml'))
            ->getContent();
    }

    /**
     * @param list<SitemapEntry> $entries
     */
    private static function contributor(array $entries): SitemapContributor
    {
        return new class ($entries) implements SitemapContributor {
            /**
             * @param list<SitemapEntry> $entries
             */
            public function __construct(private readonly array $entries)
            {
            }

            public function entries(): array
            {
                return $this->entries;
            }
        };
    }
}
