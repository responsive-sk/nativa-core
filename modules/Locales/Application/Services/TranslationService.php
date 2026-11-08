<?php

declare(strict_types = 1);

namespace Modules\Locales\Application\Services;

use Modules\Locales\Application\DTOs\TranslationDTO;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Domain\Model\Translation;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Infrastructure\Services\JsonTranslationLoader;

/**
 * TranslationService — orchestrates translation CRUD with fallback support.
 *
 * Provides a high-level API for reading and writing translations with a
 * fallback chain for reads: per-request cache → database → JSON file →
 * default → raw key. Writes invalidate the per-request cache for the locale.
 *
 * The FILE half is a real source, not an accident of the chain. A key the
 * database does not carry is served from `storage/locales/<locale>.json`, so a
 * tree whose `locales.db` was never seeded (fresh install, restored database,
 * a key shipped ahead of its migration) renders words instead of keys — the
 * same "bootstrapping is not an outage" rule the Health endpoint answers with.
 * `export()` is the other direction: the admin writes the database's rows out
 * to that file, which is what makes the file source maintainable rather than
 * hand-written. `list()` shows file-only keys beside database rows, marked as
 * coming from the file, because an operator editing translations must be able
 * to see a key that renders before they can fix it in the panel.
 */
final class TranslationService
{
    /** @var array<string, array<string, string>> locale → key → value request cache */
    private array $cache = [];

    /** @var array<string, array<string, string>> locale → key → value file cache */
    private array $fileCache = [];

    public function __construct(
        private readonly TranslationRepository $repository,
        private readonly ?JsonTranslationLoader $jsonLoader = null,
    ) {
    }

    /**
     * Get translation value by key and locale with fallback.
     *
     * @param string $key     Translation key (e.g. nav.home)
     * @param string $locale  Locale code (e.g. sk_SK)
     * @param string $default Default value if no translation found
     *
     * @return string Translated value
     */
    public function get(string $key, string $locale, string $default = ''): string
    {
        if (isset($this->cache[$locale][$key])) {
            return $this->cache[$locale][$key];
        }

        $translation = $this->repository->findByKey($key, $locale);

        if (null !== $translation) {
            return $this->cache[$locale][$key] = $translation->value();
        }

        $fallback = $this->fileTranslations($locale);

        if (isset($fallback[$key])) {
            return $this->cache[$locale][$key] = $fallback[$key];
        }

        return '' !== $default ? $default : $key;
    }

    /**
     * Keys the locale's JSON file provides, sorted.
     *
     * @return list<string>
     */
    public function fileKeys(string $locale): array
    {
        $keys = array_keys($this->fileTranslations($locale));
        sort($keys);

        return $keys;
    }

    /**
     * The admin list: database rows for the locale, plus the keys only the JSON
     * file carries, each marked with the source it came from.
     *
     * The database still wins for a key it holds — the file is the fallback, so
     * an operator edit is never shadowed by a file value. File-only rows carry
     * an empty id, which is what tells the template they cannot be edited in
     * place: a write would create a database row, and doing that silently from
     * an edit link is not something a fallback source may decide on its own.
     *
     * @return list<TranslationDTO>
     */
    public function list(string $locale, string $group = '', string $search = ''): array
    {
        $rows = '' !== $search
            ? $this->search($search, $locale)
            : ('' !== $group ? $this->getByGroup($group, $locale) : $this->getAll($locale));

        $seen = [];
        $listed = [];

        foreach ($rows as $row) {
            $seen[$row->key] = true;
            $listed[] = $row;
        }

        foreach ($this->fileTranslations($locale) as $key => $value) {
            if (isset($seen[$key])) {
                continue;
            }

            $fileGroup = str_contains($key, '.') ? explode('.', $key, 2)[0] : 'general';

            // Same predicates the repository applies, so a filter cannot show a
            // file row the database half of the same query would have hidden.
            if ('' !== $group && $group !== $fileGroup) {
                continue;
            }

            if ('' !== $search && !str_contains($key, $search) && !str_contains($value, $search)) {
                continue;
            }

            $listed[] = new TranslationDTO(
                id: '',
                key: $key,
                locale: $locale,
                value: $value,
                group: $fileGroup,
                createdAt: '',
                updatedAt: '',
                source: 'file',
            );
        }

        usort($listed, static fn (TranslationDTO $left, TranslationDTO $right): int => $left->key <=> $right->key);

        return $listed;
    }

    /**
     * Write the locale's database rows to its JSON file — the admin's export.
     *
     * Returns the number of translations written; a locale with no rows writes
     * no file at all, because an empty file would silently become the fallback
     * that replaces a language with nothing.
     */
    public function export(string $locale): int
    {
        if (null === $this->jsonLoader) {
            throw new \RuntimeException('Translations cannot be exported without a JSON file loader.');
        }

        $rows = [];

        foreach ($this->repository->findByLocale($locale) as $translation) {
            $rows[$translation->key()] = $translation->value();
        }

        if ([] === $rows) {
            return 0;
        }

        $this->jsonLoader->save($locale, $rows);
        // Keep the request cache coherent with what was just written, so a
        // read after the export in the same request sees the exported file.
        $this->fileCache[$locale] = $rows;

        return count($rows);
    }

    /**
     * Set translation value — creates or updates.
     *
     * @throws \InvalidArgumentException When the locale code format or key format is invalid
     */
    public function set(string $key, string $localeCode, string $value, string $group = 'general'): void
    {
        $locale = Locale::fromString($localeCode);

        $existing = $this->repository->findByKey($key, $localeCode);

        if (null !== $existing) {
            $existing->updateValue($value);
            $this->repository->save($existing);
        } else {
            $this->repository->save(Translation::create($key, $locale, $value, $group));
        }

        $this->invalidate($localeCode);
    }

    /**
     * Find a translation by id (admin edit form).
     */
    public function findById(string $id): ?Translation
    {
        return $this->repository->findById($id);
    }

    /**
     * Save an existing entity directly (admin edit form updates value/group).
     */
    public function save(Translation $translation): void
    {
        $this->repository->save($translation);
        $this->invalidate($translation->locale()->code());
    }

    /**
     * Delete translation by key and locale.
     */
    public function delete(string $key, string $locale): void
    {
        $existing = $this->repository->findByKey($key, $locale);

        if (null !== $existing) {
            $this->repository->delete($existing->id());
            $this->invalidate($locale);
        }
    }

    /**
     * Get all translations for a locale as DTOs.
     *
     * @return list<TranslationDTO>
     */
    public function getAll(string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->findByLocale($locale),
        );
    }

    /**
     * Get translations by group for a locale as DTOs.
     *
     * @return list<TranslationDTO>
     */
    public function getByGroup(string $group, string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->findByGroup($group, $locale),
        );
    }

    /**
     * Search translations by key or value.
     *
     * @return list<TranslationDTO>
     */
    public function search(string $query, string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->search($query, $locale),
        );
    }

    /**
     * Get keys present in other locales but missing in the given locale.
     *
     * @return list<string>
     */
    public function getMissingKeys(string $locale): array
    {
        $allKeys = $this->repository->findAllKeys();
        $existingKeys = array_map(
            static fn (Translation $t): string => $t->key(),
            $this->repository->findByLocale($locale),
        );

        // A key the file provides is not missing: it renders. Without this the
        // report would list every key of a file-backed locale as a gap the
        // operator has to fill twice.
        return array_values(array_diff($allKeys, $existingKeys, $this->fileKeys($locale)));
    }

    /**
     * The locale's file translations, read once per request.
     *
     * Cached because `get()` is called per key and per template: without this
     * every cache miss re-read and re-parsed the whole file.
     *
     * @return array<string, string>
     */
    private function fileTranslations(string $locale): array
    {
        if (isset($this->fileCache[$locale])) {
            return $this->fileCache[$locale];
        }

        return $this->fileCache[$locale] = null === $this->jsonLoader ? [] : $this->jsonLoader->load($locale);
    }

    /**
     * Convert a Translation entity to a DTO.
     */
    private static function toDTO(Translation $translation): TranslationDTO
    {
        return new TranslationDTO(
            id: $translation->id(),
            key: $translation->key(),
            locale: $translation->locale()->code(),
            value: $translation->value(),
            group: $translation->group(),
            createdAt: $translation->createdAt()->format('Y-m-d H:i:s'),
            updatedAt: $translation->updatedAt()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Drop the request cache for a locale after a write.
     *
     * The file cache survives: this tree never writes the JSON file except
     * through `export()`, which updates that cache itself.
     */
    private function invalidate(string $locale): void
    {
        unset($this->cache[$locale]);
    }
}
