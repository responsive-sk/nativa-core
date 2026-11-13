<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Interfaces\HTTP\Actions\BlogCategoriesAction;
use Modules\Blog\Interfaces\HTTP\Actions\BlogIndexAction;
use Modules\Markdown\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BlogStructuredDataTest — what /blog and a category listing announce.
 *
 * The article page carried Article + BreadcrumbList from the start, and the two
 * listing surfaces carried nothing: a reader who arrived on /blog gave a
 * crawler no name for the corpus and no trail back to the site.
 *
 * ## Two things this file is careful about
 *
 * - **Blog is a module.** A kernel-only tree has no index to render, so the
 *   suite skips rather than fails. It lives beside the code for that reason,
 *   not under `tests/`, which is inside PHPStan's scope. The guard sits in
 *   setUp() alone: PHPUnit still runs tearDown() after a skipped setUp(), so a
 *   tearDown() that skipped again would report the skip as a failure.
 * - **The trail is asserted by shape, not by host.** `Request::create()`
 *   answers `http://localhost`, and a test that pinned that would break the
 *   moment the harness changes host.
 */
final class BlogStructuredDataTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(BlogIndexAction::class)) {
            $this->markTestSkipped('Blog is not installed — the index under test is a Blog action.');
        }
    }

    #[Test]
    public function theIndexAnnouncesTheBlogAndItsPlaceInTheSite(): void
    {
        $nodes = self::jsonLd((new BlogIndexAction())->handle(Request::create('GET', '/blog'))->getContent());

        self::assertSame(['CollectionPage', 'BreadcrumbList'], array_column($nodes, '@type'));

        self::assertSame(BlogServices::label(), $nodes[0]['name']);
        self::assertStringEndsWith('/blog', $nodes[0]['url']);
    }

    #[Test]
    public function theTrailRunsHomeThenBlog(): void
    {
        $trail = self::jsonLd((new BlogIndexAction())->handle(Request::create('GET', '/blog'))->getContent())[1];

        self::assertSame(
            ['Home', BlogServices::label()],
            array_column($trail['itemListElement'], 'name'),
        );
        self::assertSame([1, 2], array_column($trail['itemListElement'], 'position'));
        self::assertStringEndsWith('/', $trail['itemListElement'][0]['item']);
        self::assertArrayNotHasKey(
            'item',
            $trail['itemListElement'][1],
            'The page itself is the last crumb, not a step towards it.',
        );
    }

    #[Test]
    public function aResultListAnnouncesNothingBecauseItIsNotTheIndex(): void
    {
        $html = (new BlogIndexAction())
            ->handle(Request::create('GET', '/blog', ['q' => 'linux']))
            ->getContent();

        self::assertStringNotContainsString(
            'application/ld+json',
            $html,
            'A search result list has no canonical either: one corpus answers every prefix.',
        );
    }

    #[Test]
    public function aCategoryListingAnnouncesItselfAsACollectionUnderTheBlog(): void
    {
        $category = self::firstCategory();
        $slug = MarkdownRenderer::slugify($category);

        $request = Request::create('GET', '/blog/categories/' . $slug);
        $request->setRouteParams(['category' => $slug]);

        $html = (new BlogCategoriesAction())->show($request)->getContent();
        $nodes = self::jsonLd($html);

        self::assertSame(['CollectionPage', 'BreadcrumbList'], array_column($nodes, '@type'));

        // The page calls itself by the slug it was routed with (its title and
        // heading do the same), so the markup repeats that rather than inventing
        // a prettier name the reader never sees.
        self::assertSame($slug, $nodes[0]['name']);
        self::assertStringEndsWith('/blog/categories/' . $slug, $nodes[0]['url']);

        self::assertSame(
            [BlogServices::label(), $slug],
            array_column($nodes[1]['itemListElement'], 'name'),
        );
        self::assertStringEndsWith(
            BlogArticleHelper::blogPath('sk'),
            $nodes[1]['itemListElement'][0]['item'],
            'The trail parent is the locale-aware index, not a cut string.',
        );

        // The listing is noindex today; the markup beside it stays truthful so
        // lifting that is a decision about robots, not a rewrite of the block.
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    /**
     * The first category the installed content actually carries.
     *
     * A tree that publishes no article is a valid deployment — this export
     * ships the catalogue, not the site's corpus — and a category listing
     * without a category has nothing to announce. That is a skip rather than a
     * failure: the listing is unmeasurable, not broken.
     */
    private static function firstCategory(): string
    {
        foreach (BlogServices::articles()->all('sk') as $article) {
            if ('' !== $article->category) {
                return $article->category;
            }
        }

        self::markTestSkipped('No article in storage/blog carries a category, so there is no listing to render.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonLd(string $html): array
    {
        preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);

        self::assertArrayHasKey(1, $matches, 'The document carries no JSON-LD block.');

        $nodes = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($nodes);

        return $nodes;
    }
}
