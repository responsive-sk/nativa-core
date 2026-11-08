<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Application\BlogServices;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\ContentServices;

/**
 * TaxonomyService — persistent registry for blog tags and categories.
 *
 * Tags and categories are normally derived from article front matter, so an
 * entry only exists once at least one article uses it. This service adds a
 * small JSON registry (storage/data/blog-taxonomy.json) so an admin can
 * pre-create entries that persist even with zero articles. Readers merge
 * registry entries with the front-matter-derived ones, deduped
 * case-insensitively (first-seen casing wins).
 */
final class TaxonomyService
{
    private const string REGISTRY_FILE = 'data' . DIRECTORY_SEPARATOR . 'blog-taxonomy.json';

    /** @return list<string> */
    public function allTags(): array
    {
        return $this->mergeWithRegistry($this->derivedTags(), 'tags');
    }

    /** @return list<string> */
    public function allCategories(): array
    {
        return $this->mergeWithRegistry($this->derivedCategories(), 'categories');
    }

    /**
     * Register a new tag. Returns false when the name is empty or already
     * present (case-insensitively) in the registry or derived taxonomy.
     */
    public function createTag(string $name): bool
    {
        return $this->create($name, 'tags');
    }

    /**
     * Register a new category. Returns false when the name is empty or
     * already present (case-insensitively) in the registry or derived
     * taxonomy.
     */
    public function createCategory(string $name): bool
    {
        return $this->create($name, 'categories');
    }

    /**
     * Rename a registered category.
     *
     * The registry is the only place a pre-created category lives: with zero
     * articles using it, it exists nowhere else. A rename that only rewrites
     * article front matter — what the admin action used to do — is therefore a
     * silent no-op for it, which is why this exists. Returns false when either
     * name is empty, when they differ only by case, or when nothing matched.
     */
    public function renameCategory(string $old, string $new): bool
    {
        return $this->rename($old, $new, 'categories');
    }

    /**
     * Dropping a registered category is what makes a pre-created one vanish
     * from the admin list; articles are the caller's business, not this
     * registry's. Returns false when the name is empty or nothing matched.
     */
    public function deleteCategory(string $name): bool
    {
        return $this->delete($name, 'categories');
    }

    /**
     * @param array<string, string> $derived lowercase key => display value
     * @param 'tags'|'categories'   $key
     *
     * @return list<string>
     */
    private function mergeWithRegistry(array $derived, string $key): array
    {
        foreach ($this->registryEntries($key) as $entry) {
            $normalized = strtolower($entry);
            if ('' !== $normalized) {
                $derived[$normalized] ??= $entry;
            }
        }

        $values = array_values($derived);
        sort($values, SORT_STRING | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * @param 'tags'|'categories' $key
     */
    private function create(string $name, string $key): bool
    {
        $name = trim($name);
        if ('' === $name) {
            return false;
        }

        if (isset($this->existingNormalized($key)[strtolower($name)])) {
            return false;
        }

        $registry = $this->readRegistry();
        $registry[$key][] = $name;
        $this->writeRegistry($registry);

        return true;
    }

    /**
     * Rewrite one registry entry, case-insensitively, keeping the list deduped.
     *
     * A target that already exists as its own entry is a MERGE, not a
     * contradiction: articles move from the old name to the new one exactly the
     * same way, so the two entries must not both survive and render twice.
     *
     * @param 'tags'|'categories' $key
     */
    private function rename(string $old, string $new, string $key): bool
    {
        $old = trim($old);
        $new = trim($new);

        if ('' === $old || '' === $new || 0 === strcasecmp($old, $new)) {
            return false;
        }

        $registry = $this->readRegistry();
        $changed = false;

        foreach ($registry[$key] as $index => $entry) {
            if (is_string($entry) && 0 === strcasecmp(trim($entry), $old)) {
                $registry[$key][$index] = $new;
                $changed = true;

                break;
            }
        }

        if (!$changed) {
            return false;
        }

        $registry[$key] = $this->dedupe($registry[$key]);
        $this->writeRegistry($registry);

        return true;
    }

    /**
     * Drop every registry entry matching the name, case-insensitively.
     *
     * @param 'tags'|'categories' $key
     */
    private function delete(string $name, string $key): bool
    {
        $name = trim($name);

        if ('' === $name) {
            return false;
        }

        $registry = $this->readRegistry();
        $kept = [];
        $removed = false;

        foreach ($registry[$key] as $entry) {
            if (is_string($entry) && 0 === strcasecmp(trim($entry), $name)) {
                $removed = true;

                continue;
            }

            $kept[] = $entry;
        }

        if (!$removed) {
            return false;
        }

        $registry[$key] = $kept;
        $this->writeRegistry($registry);

        return true;
    }

    /**
     * First occurrence wins, case-insensitively — the same rule readers apply
     * when they merge the registry with the front-matter-derived taxonomy.
     *
     * @param list<mixed> $entries
     *
     * @return list<string>
     */
    private function dedupe(array $entries): array
    {
        $seen = [];
        $kept = [];

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $normalized = strtolower(trim($entry));

            if ('' === $normalized || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $kept[] = trim($entry);
        }

        return $kept;
    }

    /**
     * Lowercased set of all known entries (registry + derived) for the key.
     *
     * @param 'tags'|'categories' $key
     *
     * @return array<string, true>
     */
    private function existingNormalized(string $key): array
    {
        $known = [];

        foreach ($this->registryEntries($key) as $entry) {
            $known[strtolower($entry)] = true;
        }

        $derived = 'tags' === $key ? $this->derivedTags() : $this->derivedCategories();
        foreach ($derived as $value) {
            $known[strtolower($value)] = true;
        }

        return $known;
    }

    /**
     * Trimmed string entries from the registry for the key.
     *
     * @param 'tags'|'categories' $key
     *
     * @return list<string>
     */
    private function registryEntries(string $key): array
    {
        $entries = [];

        foreach ($this->readRegistry()[$key] as $entry) {
            if (is_string($entry) && '' !== trim($entry)) {
                $entries[] = trim($entry);
            }
        }

        return $entries;
    }

    /**
     * Front-matter-derived tags: lowercase key => display value.
     *
     * @return array<string, string>
     */
    private function derivedTags(): array
    {
        $derived = [];

        foreach (ContentServices::mapper()->locales() as $locale) {
            foreach (BlogServices::crud()->list($locale) as $article) {
                foreach ($article['tags'] as $tag) {
                    $normalized = strtolower(trim($tag));
                    if ('' !== $normalized) {
                        $derived[$normalized] = trim($tag);
                    }
                }
            }
        }

        return $derived;
    }

    /**
     * Front-matter-derived categories: lowercase key => display value.
     *
     * @return array<string, string>
     */
    private function derivedCategories(): array
    {
        $derived = [];

        foreach (ContentServices::mapper()->locales() as $locale) {
            foreach (BlogServices::crud()->list($locale) as $article) {
                $category = trim($article['category']);
                if ('' !== $category) {
                    $derived[strtolower($category)] = $category;
                }
            }
        }

        return $derived;
    }

    /**
     * @return array{tags: list<mixed>, categories: list<mixed>}
     */
    private function readRegistry(): array
    {
        $empty = ['tags' => [], 'categories' => []];
        $path = AppPaths::instance()->storage(self::REGISTRY_FILE);
        $raw = is_file($path) ? file_get_contents($path) : false;

        if (false === $raw || '' === trim($raw)) {
            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $empty;
        }

        $tags = $decoded['tags'] ?? null;
        $categories = $decoded['categories'] ?? null;

        if (!is_array($tags) || !is_array($categories)) {
            return $empty;
        }

        return ['tags' => array_values($tags), 'categories' => array_values($categories)];
    }

    /**
     * @param array{tags: list<mixed>, categories: list<mixed>} $registry
     */
    private function writeRegistry(array $registry): void
    {
        $path = AppPaths::instance()->storage(self::REGISTRY_FILE);
        $dir = dirname($path);

        if (is_dir($dir) || @mkdir($dir, 0o775, true)) {
            file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }
}
