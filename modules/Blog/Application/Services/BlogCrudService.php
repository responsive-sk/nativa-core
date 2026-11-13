<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Markdown\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;

/**
 * BlogCrudService — read/write blog articles stored as markdown files
 * with YAML front matter in storage/blog/{sk,en}/.
 *
 * The existing BlogArticlesService is read-only (discovers articles for the
 * public frontend). This service adds create/update/delete for the admin
 * editor, working with the same file format and directory structure.
 */
final readonly class BlogCrudService
{
    private MarkdownRenderer $renderer;
    private CoverPathResolver $covers;

    public function __construct(?CoverPathResolver $covers = null)
    {
        $this->renderer = new MarkdownRenderer();
        $this->covers = $covers ?? new CoverPathResolver();
    }

    /**
     * List all articles in the given locale, newest first.
     *
     * @return list<array{slug: string, title: string, date: string, category: string, author: string, author_email: string, tags: list<string>, members_only: bool, published: bool}>
     */
    public function list(string $locale): array
    {
        $dir = BlogArticlesService::directoryFor($locale);
        $path = AppPaths::instance()->storage($dir);
        $files = glob($path . DIRECTORY_SEPARATOR . '*.md') ?: [];

        $articles = [];

        foreach ($files as $file) {
            $slug = basename($file, '.md');

            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
                continue;
            }

            $source = file_get_contents($file);

            if (!is_string($source)) {
                continue;
            }

            $meta = $this->renderer->extractFrontMatter($source)['meta'];

            $tags = is_array($meta['tags'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
                    $meta['tags'],
                ), static fn (string $tag): bool => '' !== $tag))
                : [];

            $articles[] = [
                'slug'         => $slug,
                'title'        => is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
                'date'         => is_string($meta['date'] ?? null) ? $meta['date'] : '',
                'category'     => is_string($meta['category'] ?? null) ? $meta['category'] : '',
                'author'       => is_string($meta['author'] ?? null) ? $meta['author'] : '',
                // The ownership check reads this from the list row; without it
                // here an author filtering the admin index would see nothing,
                // because a row that omits the byline looks unowned.
                'author_email' => is_string($meta['author_email'] ?? null) ? $meta['author_email'] : '',
                'tags'         => $tags,
                'members_only' => true === ($meta['members_only'] ?? false),
                'published'    => true === ($meta['published'] ?? true),
            ];
        }

        usort($articles, static fn (array $a, array $b): int => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        return $articles;
    }

    /**
     * Find a single article by slug. Returns null when not found.
     *
     * @return array{meta: array<string, mixed>, body: string}|null
     */
    public function find(string $slug, string $locale): ?array
    {
        $file = $this->filePath($slug, $locale);

        if (!is_file($file)) {
            return null;
        }

        $source = file_get_contents($file);

        if (!is_string($source)) {
            return null;
        }

        $result = $this->renderer->extractFrontMatter($source);
        // Strip leading/trailing whitespace added by serialize round-trip
        $result['body'] = trim($result['body'], "\n");

        return $result;
    }

    /**
     * Create or update an article. Overwrites the file if it already exists.
     *
     * @param array<string, mixed> $meta Front matter fields (title, date, tags, excerpt, author, author_email, category, image, translation, members_only)
     * @param string               $body Markdown body content
     */
    public function save(string $slug, array $meta, string $body, string $locale): void
    {
        $content = $this->serialize($meta, $body);
        $file = $this->filePath($slug, $locale);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        file_put_contents($file, $content);
    }

    /**
     * Check if an article is published.
     */
    public function isPublished(string $slug, string $locale): bool
    {
        $article = $this->find($slug, $locale);

        if (null === $article) {
            return false;
        }

        return true === ($article['meta']['published'] ?? true);
    }

    /**
     * Toggle the published status of an article.
     */
    public function setPublished(string $slug, bool $published, string $locale): bool
    {
        $article = $this->find($slug, $locale);

        if (null === $article) {
            return false;
        }

        $meta = $article['meta'];
        $meta['published'] = $published;
        $this->save($slug, $meta, $article['body'], $locale);

        return true;
    }

    /**
     * Delete an article file, and the generated cover that belongs to it.
     *
     * Returns true on success.
     *
     * The cover used to survive: `delete()` was one `unlink()` of the markdown,
     * so every article ever deleted left its SVG behind — still on disk, still
     * served by `ServeBlogImageAction`, reachable at a URL no page links to
     * any more. `lekalo/modules/blog/commands.yaml` has always described this
     * command as removing "its tracked cover if owned", so the model and the
     * code disagreed about what delete does, and nothing in the pipeline could
     * notice: the model has no adapter.
     *
     * The cover is removed, not merely unreferenced, so `CoverPathResolver` is
     * asked for the paths rather than recomputing the rule here.
     */
    public function delete(string $slug, string $locale): bool
    {
        $file = $this->filePath($slug, $locale);

        if (!is_file($file)) {
            return false;
        }

        $covers = $this->covers->existingCovers($slug, $locale);

        if (!unlink($file)) {
            return false;
        }

        $this->removeUnreferencedCovers($covers, $file);

        return true;
    }

    /**
     * Unlink the covers of a deleted article — unless a surviving article still
     * points at one by its `image` front matter.
     *
     * `resolveCover()` ranks an explicit `image:` above the generated file, so
     * an author can point one article at another's cover. Generation is keyed
     * by slug, so that explicit reference is the ONLY way two articles can end
     * up sharing a cover; removing such a file would blank a live article's
     * image, with nothing in any log to say why it vanished.
     *
     * The article being deleted is excluded because its own reference dies with
     * it — it has already been unlinked, and an `image:` on a deleted article
     * is not a reason to keep a file nobody renders.
     *
     * @param list<string> $covers
     */
    private function removeUnreferencedCovers(array $covers, string $deletedFile): void
    {
        if ([] === $covers) {
            return;
        }

        $referenced = $this->coverReferences($deletedFile);

        foreach ($covers as $cover) {
            if (isset($referenced[$this->covers->urlFor($cover)])) {
                continue;
            }

            unlink($cover);
        }
    }

    /**
     * Every image URL a surviving article names in its front matter, as a
     * lookup set.
     *
     * This reads the whole blog tree, on an admin delete — a handful of
     * markdown files, once per delete, and the alternative is unlinking a file
     * that something else is rendering.
     *
     * @return array<string, true>
     */
    private function coverReferences(string $exceptFile): array
    {
        $urls = [];

        foreach (ContentLocaleServices::mapper()->locales() as $locale) {
            $dir = AppPaths::instance()->storage(BlogArticlesService::directoryFor($locale));

            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $file) {
                if ($file === $exceptFile) {
                    continue;
                }

                $source = file_get_contents($file);

                if (!is_string($source)) {
                    continue;
                }

                $image = $this->renderer->extractFrontMatter($source)['meta']['image'] ?? null;

                if (is_string($image) && '' !== $image) {
                    $urls[$image] = true;
                }
            }
        }

        return $urls;
    }

    /**
     * Generate a URL-safe slug from a title.
     */
    public function generateSlug(string $title): string
    {
        return MarkdownRenderer::slugify($title);
    }

    /**
     * Check if a slug already exists in the given locale.
     */
    public function exists(string $slug, string $locale): bool
    {
        return is_file($this->filePath($slug, $locale));
    }

    /**
     * Serialize meta + body into a markdown file with YAML front matter.
     *
     * @param array<string, mixed> $meta
     */
    private function serialize(array $meta, string $body): string
    {
        $lines = ['---'];

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $items = array_map(
                    static fn (mixed $v): string => '"' . str_replace('"', '\\"', is_scalar($v) ? (string) $v : '') . '"',
                    $value,
                );
                $lines[] = $key . ': [' . implode(', ', $items) . ']';
            } elseif (is_bool($value)) {
                $lines[] = $key . ': ' . ($value ? 'true' : 'false');
            } elseif (is_string($value)) {
                // Quote escaping mirrors MarkdownRenderer::extractFrontMatter,
                // which stripslashes() on read — addslashes() would grow
                // backslashes on every load+save round trip.
                $lines[] = $key . ': "' . str_replace('"', '\\"', $value) . '"';
            } elseif (is_int($value) || is_float($value)) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = $body;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Resolve the file path for a slug + locale.
     */
    private function filePath(string $slug, string $locale): string
    {
        $dir = BlogArticlesService::directoryFor($locale);

        return AppPaths::instance()->storage($dir . DIRECTORY_SEPARATOR . $slug . '.md');
    }
}
