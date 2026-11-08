<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Domain\Entities\Article;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * BlogFeedService — RSS/Atom feed generation from the shared article cards.
 *
 * Both feed formats (Atom 1.0 and RSS 2.0) are built from the same
 * BlogArticlesService::all() cards, so the feeds always agree with the blog
 * index and article pages on titles, excerpts, dates, tags and authors.
 * All text is escaped XML-safe via FrontendLayout::e(). Feed links are
 * absolute (base URL passed in from the request) so subscribers can resolve
 * article URLs.
 */
final readonly class BlogFeedService
{
    /**
     * Atom 1.0 feed document.
     *
     * @param list<Article> $articles
     */
    public function atom(array $articles, string $baseUrl): string
    {
        $entries = '';

        foreach ($articles as $article) {
            $entries .= $this->atomEntry($article, $baseUrl);
        }

        $title = FrontendLayout::e(BlogServices::brandSuffix() . ' ... Blog');
        $subtitle = FrontendLayout::e('Articles from storage/blog ... plain Markdown, zero dependencies.');
        $updated = '' !== ($articles[0]->date ?? '')
            ? FrontendLayout::e($this->rfc2822($articles[0]->date))
            : FrontendLayout::e($this->rfc2822(date('Y-m-d')));

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
            . '  <title>' . $title . '</title>' . "\n"
            . '  <subtitle>' . $subtitle . '</subtitle>' . "\n"
            . '  <id>' . FrontendLayout::e($baseUrl . '/blog/feed.atom') . '</id>' . "\n"
            . '  <link rel="self" href="' . FrontendLayout::e($baseUrl . '/blog/feed.atom') . '"/>' . "\n"
            . '  <updated>' . $updated . '</updated>' . "\n"
            . $entries
            . '</feed>' . "\n";
    }

    /**
     * RSS 2.0 feed document.
     *
     * @param list<Article> $articles
     */
    public function rss(array $articles, string $baseUrl): string
    {
        $items = '';

        foreach ($articles as $article) {
            $items .= $this->rssItem($article, $baseUrl);
        }

        $title = FrontendLayout::e(BlogServices::brandSuffix() . ' ... Blog');
        $description = FrontendLayout::e('Articles from storage/blog ... plain Markdown, zero dependencies.');
        $feedUrl = FrontendLayout::e($baseUrl . '/blog/feed.xml');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0">' . "\n"
            . '  <channel>' . "\n"
            . '    <title>' . $title . '</title>' . "\n"
            . '    <link>' . FrontendLayout::e($baseUrl) . '</link>' . "\n"
            . '    <description>' . $description . '</description>' . "\n"
            . '    <atom:link xmlns:atom="http://www.w3.org/2005/Atom" rel="self" type="application/rss+xml" href="' . $feedUrl . '"/>' . "\n"
            . $items
            . '  </channel>' . "\n"
            . '</rss>' . "\n";
    }

    /**
     * Atom <entry> for one article.
     *
     * @param Article $article
     */
    private function atomEntry(Article $article, string $baseUrl): string
    {
        $url = $this->articleUrl($baseUrl, $article);
        $title = FrontendLayout::e($article->title);
        $excerpt = FrontendLayout::e($article->excerpt);
        $author = FrontendLayout::e($article->author);
        $date = FrontendLayout::e($this->rfc2822($article->date));

        $categories = $this->categoryTags($article->tags);

        return '  <entry>' . "\n"
            . '    <title>' . $title . '</title>' . "\n"
            . '    <link href="' . FrontendLayout::e($url) . '"/>' . "\n"
            . '    <id>' . FrontendLayout::e($url) . '</id>' . "\n"
            . '    <published>' . $date . '</published>' . "\n"
            . '    <updated>' . $date . '</updated>' . "\n"
            . '    <summary>' . $excerpt . '</summary>' . "\n"
            . '    <author><name>' . $author . '</name></author>' . "\n"
            . $categories
            . '  </entry>' . "\n";
    }

    /**
     * RSS <item> for one article.
     *
     * @param Article $article
     */
    private function rssItem(Article $article, string $baseUrl): string
    {
        $url = $this->articleUrl($baseUrl, $article);
        $title = FrontendLayout::e($article->title);
        $excerpt = FrontendLayout::e($article->excerpt);
        $author = FrontendLayout::e($article->author);
        $date = FrontendLayout::e($this->rfc2822($article->date));

        $categories = $this->categoryTags($article->tags);

        return '    <item>' . "\n"
            . '      <title>' . $title . '</title>' . "\n"
            . '      <link>' . FrontendLayout::e($url) . '</link>' . "\n"
            . '      <guid isPermaLink="true">' . FrontendLayout::e($url) . '</guid>' . "\n"
            . '      <pubDate>' . $date . '</pubDate>' . "\n"
            . '      <description>' . $excerpt . '</description>' . "\n"
            . '      <author>' . $author . '</author>' . "\n"
            . $categories
            . '    </item>' . "\n";
    }

    /**
     * Absolute article URL with the locale prefix — /blog/{slug} for the
     * default locale, /en/blog/{slug} for en_US (the /en prefix is handled
     * by LocaleMiddleware before routing).
     *
     * @param Article $article
     */
    private function articleUrl(string $baseUrl, Article $article): string
    {
        $prefix = ContentServices::mapper()->urlPrefix($article->locale);

        return $baseUrl . $prefix . '/blog/' . $article->slug;
    }

    /**
     * One <category> line per tag (empty when the article has no tags).
     *
     * @param list<string> $tags
     */
    private function categoryTags(array $tags): string
    {
        $categories = '';

        foreach ($tags as $tag) {
            $categories .= '    <category>' . FrontendLayout::e($tag) . '</category>' . "\n";
        }

        return $categories;
    }

    /**
     * Normalize a YYYY-MM-DD date to RFC 2822 (used by Atom and RSS).
     */
    private function rfc2822(string $date): string
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return date('r');
        }

        $timestamp = strtotime(substr($date, 0, 10) . ' 12:00:00');

        return false === $timestamp ? date('r') : date('r', $timestamp);
    }
}
