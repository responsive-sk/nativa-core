<?php

declare(strict_types = 1);

namespace Modules\Pages\Infrastructure\Persistence;

use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * PageFileStorage — reads/writes page markdown files with YAML frontmatter.
 *
 * Pages are stored in storage/pages/ (the default locale, settings.locale)
 * and storage/pages/{lang}/ for every other locale. The format mirrors blog
 * articles but with fewer metadata fields.
 *
 * Every method takes the locale explicitly — there is no default argument.
 * A defaulted locale is how a hardcoded Slovak literal leaked in here and
 * made every unknown code silently resolve to Slovak.
 */
final readonly class PageFileStorage
{
    private MarkdownRenderer $renderer;

    public function __construct()
    {
        $this->renderer = new MarkdownRenderer();
    }

    /**
     * List all pages in the given locale, sorted by title.
     *
     * `translation` names the paired page's slug in the OTHER locale (the
     * blog's front-matter key, same spelling) and `aliases` holds previous
     * slugs this page was renamed away from as a flow list (`["a", "b"]`) —
     * the same spelling the blog uses for `tags:`, so the shared
     * extractFrontMatter() parses it back into an array.
     *
     * @return list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string, translation: ?string, aliases: list<string>}>
     */
    public function list(string $locale): array
    {
        $dir = AppPaths::instance()->storage($this->directory($locale));
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];

        $pages = [];

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

            $pages[] = [
                'slug'             => $slug,
                'title'            => is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
                'published'        => true === ($meta['published'] ?? false),
                'seo_title'        => is_string($meta['seo_title'] ?? null) ? $meta['seo_title'] : '',
                'seo_description'  => is_string($meta['seo_description'] ?? null) ? $meta['seo_description'] : '',
                'translation'      => $this->parseTranslation($meta),
                'aliases'          => $this->parseAliases($meta),
            ];
        }

        usort($pages, static fn (array $a, array $b): int => $a['title'] <=> $b['title']);

        return $pages;
    }

    /**
     * Find a single page by slug. Returns null when not found.
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
        $result['body'] = trim($result['body'], "\n");

        return $result;
    }

    /**
     * Create or update a page. Overwrites the file if it already exists.
     *
     * @param array<string, mixed> $meta Front matter fields (title, slug, seo_title, seo_description, published)
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
     * Delete a page file. Returns true on success.
     */
    public function delete(string $slug, string $locale): bool
    {
        $file = $this->filePath($slug, $locale);

        if (!is_file($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Check if a slug already exists in the given locale.
     */
    public function exists(string $slug, string $locale): bool
    {
        return is_file($this->filePath($slug, $locale));
    }

    /**
     * The paired page's slug in the other locale, as declared in this page's
     * front matter. Validated against the slug grammar; existence of the
     * target file is the CALLER's question (PageCrudService::translationOf),
     * because "the key names a page" and "that page is there" are two claims.
     */
    public function translationOf(string $slug, string $locale): ?string
    {
        $page = $this->find($slug, $locale);

        return null === $page ? null : $this->parseTranslation($page['meta']);
    }

    /**
     * The declared aliases (previous slugs) of one page.
     *
     * @return list<string>
     */
    public function aliases(string $slug, string $locale): array
    {
        $page = $this->find($slug, $locale);

        return null === $page ? [] : $this->parseAliases($page['meta']);
    }

    /**
     * Resolve a slug that has no file of its own to the page that was renamed
     * away from it — the page whose `aliases:` list names it. Null when the
     * slug belongs to nobody.
     */
    public function aliasTarget(string $slug, string $locale): ?string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            return null;
        }

        $dir = AppPaths::instance()->storage($this->directory($locale));
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [];

        foreach ($files as $file) {
            $owner = basename($file, '.md');

            if ($owner === $slug) {
                continue;
            }

            $source = file_get_contents($file);

            if (!is_string($source)) {
                continue;
            }

            $meta = $this->renderer->extractFrontMatter($source)['meta'];

            if (in_array($slug, $this->parseAliases($meta), true)) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Generate a URL-safe slug from a title.
     */
    public function generateSlug(string $title): string
    {
        return MarkdownRenderer::slugify($title);
    }

    /**
     * The validated `translation:` key of one page's front matter.
     *
     * @param array<string, mixed> $meta
     */
    private function parseTranslation(array $meta): ?string
    {
        $raw = is_string($meta['translation'] ?? null) ? trim($meta['translation']) : '';

        return '' !== $raw && 1 === preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $raw) ? $raw : null;
    }

    /**
     * The `aliases:` list of one page's front matter — a flow list
     * (`["a", "b"]`), which is what the serializer writes. A bare
     * comma-separated scalar is accepted too: tolerant reading for
     * hand-edited files, strict writing everywhere else.
     *
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private function parseAliases(array $meta): array
    {
        $raw = $meta['aliases'] ?? null;
        $candidates = is_array($raw) ? $raw : explode(',', is_string($raw) ? $raw : '');
        $slugs = [];

        foreach ($candidates as $candidate) {
            $candidate = trim(is_scalar($candidate) ? (string) $candidate : '');

            if ('' !== $candidate && 1 === preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $candidate)) {
                $slugs[] = $candidate;
            }
        }

        return array_values(array_unique($slugs));
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
                // Flow lists mirror the blog's tags: — extractFrontMatter()
                // parses `key: ["a", "b"]` back into an array, so a list
                // written here reads back as the same list.
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
        $dir = $this->directory($locale);

        return AppPaths::instance()->storage($dir . DIRECTORY_SEPARATOR . $slug . '.md');
    }

    /**
     * Resolve the directory path for a locale within the pages family.
     *
     * Delegated to ContentLocaleMapper — the same rule the blog uses, so the
     * two content families can never disagree about which locale is default.
     */
    private function directory(string $locale): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, ContentServices::mapper()->directory('pages', $locale));
    }
}
