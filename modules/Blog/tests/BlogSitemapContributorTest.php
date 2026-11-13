<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogSitemapContributor;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Seo\SitemapEntry;

final class BlogSitemapContributorTest extends TestCase
{
    /** @var list<string> article fixtures this test created */
    private array $created = [];

    private ?string $createdDir = null;

    protected function setUp(): void
    {
        if (!class_exists(BlogServices::class)) {
            $this->markTestSkipped('Blog is not installed — the contributor ships with this module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (null !== $this->createdDir) {
            @rmdir($this->createdDir);
        }
    }

    #[Test]
    public function itListsTheBlogIndexAndPublishedArticlesForTheDefaultLocale(): void
    {
        $slug = 'sitemap-published-' . bin2hex(random_bytes(4));
        $this->writeArticle($slug, true);

        $paths = self::paths();
        $prefix = ContentLocaleServices::mapper()->urlPrefix(ContentLocaleServices::mapper()->defaultLocale());

        self::assertContains($prefix . '/blog', $paths);
        self::assertContains($prefix . '/blog/' . $slug, $paths);
        self::assertContains('/en/blog', $paths, 'The empty English index is still a served 200 surface.');
    }

    #[Test]
    public function itDoesNotListAFeedOrATaxonomySurface(): void
    {
        $paths = self::paths();

        foreach ($paths as $path) {
            self::assertStringNotContainsString('/feed', $path);
            self::assertStringNotContainsString('/tags/', $path);
            self::assertStringNotContainsString('/categories/', $path);
        }
    }

    #[Test]
    public function aDraftArticleIsAbsent(): void
    {
        $slug = 'sitemap-draft-' . bin2hex(random_bytes(4));
        $this->writeArticle($slug, false);

        self::assertNotContains('/blog/' . $slug, self::paths());
    }

    private function writeArticle(string $slug, bool $published): void
    {
        $dir = AppPaths::instance()->storage('blog');

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
            $this->createdDir = $dir;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $slug . '.md';

        file_put_contents(
            $path,
            "---\ntitle: \"Sitemap fixture\"\ndate: 2026-01-01\npublished: " . ($published ? 'true' : 'false') . "\n---\n\nBody.\n",
        );

        $this->created[] = $path;
    }

    /**
     * @return list<string>
     */
    private static function paths(): array
    {
        return array_map(
            static fn (SitemapEntry $entry): string => $entry->path,
            (new BlogSitemapContributor())->entries(),
        );
    }
}
