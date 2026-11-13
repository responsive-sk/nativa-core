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
    private ?string $draft = null;

    protected function setUp(): void
    {
        if (!class_exists(BlogServices::class)) {
            $this->markTestSkipped('Blog is not installed — the contributor ships with this module.');
        }
    }

    protected function tearDown(): void
    {
        if (null !== $this->draft && is_file($this->draft)) {
            unlink($this->draft);
        }
    }

    #[Test]
    public function itListsTheBlogIndexAndPublishedArticlesForTheDefaultLocale(): void
    {
        $paths = self::paths();
        $prefix = ContentLocaleServices::mapper()->urlPrefix(ContentLocaleServices::mapper()->defaultLocale());

        self::assertContains($prefix . '/blog', $paths);
        self::assertContains($prefix . '/blog/nativa-php-core', $paths);
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
        $this->draft = AppPaths::instance()->storage('blog/' . $slug . '.md');

        file_put_contents($this->draft, "---\ntitle: \"Draft\"\npublished: false\n---\n\nDraft body.\n");

        self::assertNotContains('/blog/' . $slug, self::paths());
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
