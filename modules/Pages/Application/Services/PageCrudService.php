<?php

declare(strict_types = 1);

namespace Modules\Pages\Application\Services;

use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Pages\Infrastructure\Persistence\PageFileStorage;

/**
 * PageCrudService — CRUD operations for static pages.
 *
 * Pages are stored as markdown files with YAML frontmatter in
 * storage/pages/ (default locale) and storage/pages/{lang}/ (others). This
 * service coordinates the file storage
 * layer and provides the API consumed by admin actions.
 */
final readonly class PageCrudService
{
    private PageFileStorage $storage;

    public function __construct()
    {
        $this->storage = new PageFileStorage();
    }

    /**
     * List all pages in the given locale, sorted by title.
     *
     * @return list<array{slug: string, title: string, published: bool, seo_title: string, seo_description: string, translation: ?string, aliases: list<string>}>
     */
    public function list(string $locale): array
    {
        return $this->storage->list($locale);
    }

    /**
     * Find a single page by slug. Returns null when not found.
     *
     * @return array{meta: array<string, mixed>, body: string}|null
     */
    public function find(string $slug, string $locale): ?array
    {
        return $this->storage->find($slug, $locale);
    }

    /**
     * Create or update a page. Overwrites the file if it already exists.
     *
     * @param array<string, mixed> $meta Front matter fields
     * @param string               $body Markdown body content
     */
    public function save(string $slug, array $meta, string $body, string $locale): void
    {
        $this->storage->save($slug, $meta, $body, $locale);
    }

    /**
     * Check if a page is published.
     */
    public function isPublished(string $slug, string $locale): bool
    {
        $page = $this->find($slug, $locale);

        if (null === $page) {
            return false;
        }

        return true === ($page['meta']['published'] ?? false);
    }

    /**
     * Toggle the published status of a page.
     */
    public function setPublished(string $slug, bool $published, string $locale): bool
    {
        $page = $this->find($slug, $locale);

        if (null === $page) {
            return false;
        }

        $meta = $page['meta'];
        $meta['published'] = $published;
        $this->save($slug, $meta, $page['body'], $locale);

        return true;
    }

    /**
     * Delete a page file. Returns true on success.
     */
    public function delete(string $slug, string $locale): bool
    {
        return $this->storage->delete($slug, $locale);
    }

    /**
     * Check if a slug already exists in the given locale.
     */
    public function exists(string $slug, string $locale): bool
    {
        return $this->storage->exists($slug, $locale);
    }

    /**
     * The paired page's slug in the OTHER locale — resolved, not merely
     * declared: the front-matter key names the pair and the target file must
     * actually be there. A dangling key is "no pair", exactly like the blog.
     */
    public function translationOf(string $slug, string $locale): ?string
    {
        $target = $this->storage->translationOf($slug, $locale);

        if (null === $target) {
            return null;
        }

        return $this->storage->exists($target, $this->otherLocale($locale)) ? $target : null;
    }

    /**
     * The declared aliases (previous slugs) of one page.
     *
     * @return list<string>
     */
    public function aliases(string $slug, string $locale): array
    {
        return $this->storage->aliases($slug, $locale);
    }

    /**
     * The page this slug was renamed away to, if any — null when the slug
     * belongs to nobody. The answer behind the public 301.
     */
    public function aliasTarget(string $slug, string $locale): ?string
    {
        return $this->storage->aliasTarget($slug, $locale);
    }

    /**
     * May this slug be used in this locale? A live file OR somebody's alias
     * both own the URL — an alias collision would shadow a redirect, and a
     * file collision shadows a page.
     */
    public function isSlugTaken(string $slug, string $locale): bool
    {
        return $this->storage->exists($slug, $locale)
            || null !== $this->storage->aliasTarget($slug, $locale);
    }

    /**
     * Rename a page: the content moves to the new slug and the OLD slug is
     * appended to `aliases:`, so every previously published URL answers a 301
     * instead of a 404. False when the old page is missing or the new slug is
     * taken (file or alias).
     */
    public function rename(string $old, string $new, string $locale): bool
    {
        if ($old === $new) {
            return true;
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $new)) {
            return false;
        }

        if (!$this->storage->exists($old, $locale) || $this->isSlugTaken($new, $locale)) {
            return false;
        }

        $page = $this->storage->find($old, $locale);

        if (null === $page) {
            return false;
        }

        $meta = $page['meta'];
        $aliases = $this->storage->aliases($old, $locale);
        $aliases[] = $old;
        // A real flow list, like the blog's tags: — the spec pins
        // `aliases: ["old"]` and a comma-scalar would only read back by
        // accident of the tolerant parser.
        $meta['aliases'] = array_values(array_unique($aliases));

        $this->storage->save($new, $meta, $page['body'], $locale);
        $this->storage->delete($old, $locale);

        error_log(sprintf('[pages] renamed slug=%s -> %s locale=%s', $old, $new, $locale));

        // Without the flush the next request serves the PREVIOUS slug's cached
        // HTML and the 301 appears not to work for a cache lifetime.
        CacheInvalidator::flush();

        return true;
    }

    /**
     * Generate a URL-safe slug from a title.
     */
    public function generateSlug(string $title): string
    {
        return $this->storage->generateSlug($title);
    }

    /**
     * The other locale of the two-locale content family. The mapper owns the
     * family; this only picks the sibling of the given code.
     */
    private function otherLocale(string $locale): string
    {
        foreach (ContentServices::mapper()->locales() as $candidate) {
            if ($candidate !== $locale) {
                return $candidate;
            }
        }

        return $locale;
    }
}
