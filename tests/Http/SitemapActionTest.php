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
    public function aThrowingContributorStillLeavesTheKernelRows(): void
    {
        $broken = new class () implements SitemapContributor {
            public function entries(): array
            {
                throw new \RuntimeException('fixture failure');
            }
        };

        $body = self::render(SitemapContributorRegistry::fromContributors([$broken]));

        self::assertStringContainsString('<loc>http://localhost/</loc>', $body);
        self::assertSame(200, (new SitemapAction(SitemapContributorRegistry::fromContributors([$broken])))
            ->handle(Request::create('GET', '/sitemap.xml'))
            ->getStatusCode());
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
    public function aContributorCanAttachImagesToItsUrl(): void
    {
        $body = self::render(SitemapContributorRegistry::fromContributors([
            self::contributor([new SitemapEntry(
                '/blog/probe',
                images: ['/storage/blog-images/probe.svg?v=abc', 'https://cdn.example.test/probe.png'],
            )]),
        ]));

        self::assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $body);
        self::assertStringContainsString(
            '<image:loc>http://localhost/storage/blog-images/probe.svg?v=abc</image:loc>',
            $body,
        );
        self::assertStringContainsString(
            '<image:loc>https://cdn.example.test/probe.png</image:loc>',
            $body,
        );
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
