<?php

declare(strict_types = 1);

namespace Modules\Locales\Infrastructure\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * JsonTranslationLoader — loads/saves translations from/to JSON files.
 *
 * Stores translation files in storage/locales/{locale}.json (or a custom
 * base path for tests). Used as a fallback source when the database has no
 * value for a key. Concrete class (no interface — C-pattern).
 */
final readonly class JsonTranslationLoader
{
    public function __construct(private string $basePath = '')
    {
    }

    /**
     * Load translations for a locale from its JSON file.
     *
     * @return array<string, string> key → value
     */
    public function load(string $locale): array
    {
        $path = $this->getPath($locale);

        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (false === $contents || '' === $contents) {
            return [];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return [];
        }

        $translations = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $translations[$key] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            }
        }

        return $translations;
    }

    /**
     * Save translations for a locale to its JSON file.
     *
     * @param array<string, string> $translations
     *
     * @throws \RuntimeException When the file cannot be written
     */
    public function save(string $locale, array $translations): void
    {
        $path = $this->getPath($locale);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $json = json_encode(
            $translations,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        $written = file_put_contents($path, $json);

        if (false === $written) {
            throw new \RuntimeException(sprintf('Failed to write JSON translation file: %s', $path));
        }
    }

    /**
     * Check if a JSON file exists for the given locale.
     */
    public function exists(string $locale): bool
    {
        return is_file($this->getPath($locale));
    }

    /**
     * Full file path for a locale's JSON file (locale is filename-safe by construction).
     */
    public function getPath(string $locale): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $locale) ?: 'default';

        return $this->resolveBasePath() . '/' . $safe . '.json';
    }

    /**
     * Resolve the base path — storage/locales by default, custom in tests.
     */
    private function resolveBasePath(): string
    {
        return '' === $this->basePath
            ? AppPaths::instance()->storage('locales')
            : $this->basePath;
    }
}
