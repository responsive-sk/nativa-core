<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;

/**
 * CoverPathResolver — which locale an article belongs to, and where its
 * cover file goes.
 *
 * Two rules live here, and both used to live as private helpers of the
 * `generate-images` command, where nothing could test them:
 *
 *  1. The locale of a content file comes from the mapper. The family root is
 *     owned by `settings.locale` and every other locale gets a `{lang}`
 *     subdirectory, so "which locale is this file?" is a question only the
 *     mapper can answer. The command used to answer it with
 *     `in_array($dirLocale, ['sk', 'en'], true) ? $dirLocale : 'sk'` — a
 *     hardcoded Slovak default that would silently mislabel a cover the day a
 *     third locale appears.
 *
 *  2. The flat `blog-images/{slug}.svg` can hold exactly one locale's
 *     identity, so a slug that exists in two locales gets one file per
 *     locale under `blog-images/{lang}/`. Every other slug stays flat. The
 *     collision check compared `mapper()->locales()` (which returns full
 *     codes like `sk_SK`) against the directory code (`sk`), so the
 *     `continue` never fired, the article's OWN directory counted as "another
 *     locale", and every cover in the repo was written locale-qualified —
 *     quietly defeating the rule the method documents.
 */
final class CoverPathResolver
{
    /**
     * The locale code (`sk`, `en`, …) a content file belongs to.
     *
     * A file in the family root is the configured default locale; a file in a
     * `{lang}` subdirectory is that language. An unknown directory falls back
     * to the default rather than guessing.
     */
    public function localeFor(string $file): string
    {
        $mapper = ContentLocaleServices::mapper();
        $directory = dirname($file);

        foreach ($mapper->locales() as $locale) {
            if ($this->directory($locale) === $directory) {
                return $mapper->languageCode($locale);
            }
        }

        return $mapper->languageCode($mapper->defaultLocale());
    }

    /**
     * Collision-proof output path: flat for a unique slug, one file per
     * locale for a slug two locales share.
     */
    public function pathFor(string $fileSlug, string $locale): string
    {
        $root = AppPaths::instance()->storage('blog-images');

        return $this->collides($fileSlug, $locale)
            ? $root . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $fileSlug . '.svg'
            : $root . DIRECTORY_SEPARATOR . $fileSlug . '.svg';
    }

    /**
     * Does this slug exist in another locale's content directory?
     *
     * The comparison is by LANGUAGE CODE on both sides: the mapper's locale
     * codes and the code of the article being written have to mean the same
     * thing, or the article matches itself.
     */
    public function collides(string $fileSlug, string $locale): bool
    {
        $mapper = ContentLocaleServices::mapper();

        foreach ($mapper->locales() as $candidate) {
            if ($mapper->languageCode($candidate) === $locale) {
                continue;
            }

            $file = $this->directory($candidate) . DIRECTORY_SEPARATOR . $fileSlug . '.md';

            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Absolute content directory of one locale.
     */
    private function directory(string $locale): string
    {
        return AppPaths::instance()->storage(
            str_replace('/', DIRECTORY_SEPARATOR, ContentLocaleServices::mapper()->directory('blog', $locale)),
        );
    }

    /**
     * Every generated cover that belongs to one article, in whichever of the
     * two shapes it was written.
     *
     * The two shapes are NOT equally safe to remove, which is the reason this
     * method exists rather than a call to `pathFor()` at delete time:
     *
     *  - `blog-images/{locale}/{slug}.svg` carries the locale in its path, so
     *    it can only ever have been generated for THIS locale's article. Safe.
     *
     *  - `blog-images/{slug}.svg` does not. It is written for a slug only one
     *    locale holds, but a locale can gain an article afterwards — and then
     *    that flat file is the OTHER locale's cover, not this one's. Removing
     *    it on that article's delete would blank a live article's image.
     *
     * So the flat file is a candidate only while no other locale holds the
     * slug, which is exactly `collides()` — the same question `pathFor()` asks,
     * asked in the safe direction.
     *
     * @return list<string>
     */
    public function existingCovers(string $fileSlug, string $locale): array
    {
        $code = $this->codeFor($locale);
        $root = AppPaths::instance()->storage('blog-images');

        $candidates = [$root . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $fileSlug . '.svg'];

        if (!$this->collides($fileSlug, $code)) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $fileSlug . '.svg';
        }

        return array_values(array_filter($candidates, is_file(...)));
    }

    /**
     * The language CODE (`sk`) for a locale named either way (`sk` or
     * `sk_SK`), and the default when the name is neither.
     *
     * `existingCovers()` is reached from the admin delete, which passes what
     * `AdminLocale::canonical()` returns — `sk_SK` — while the generator gets
     * its locale from `localeFor()`, which returns `sk`. Handing `sk_SK`
     * straight to `collides()` makes the article match **itself**: the
     * comparison there is by language code, so the `continue` never fires, the
     * caller sees its own slug as "another locale's", and the flat cover is
     * excluded as if it were a neighbour's. That is the same defect
     * `localeFor()`'s own docblock names, reached through the other door.
     *
     * Normalising here means no caller has to know which spelling it holds.
     */
    private function codeFor(string $locale): string
    {
        $mapper = ContentLocaleServices::mapper();

        foreach ($mapper->locales() as $candidate) {
            if ($candidate === $locale || $mapper->languageCode($candidate) === $locale) {
                return $mapper->languageCode($candidate);
            }
        }

        return $mapper->languageCode($mapper->defaultLocale());
    }

    /**
     * The public URL of a cover file — the form an article's `image` front
     * matter carries it in, which is what makes two covers comparable.
     */
    public function urlFor(string $coverPath): string
    {
        $root = AppPaths::instance()->storage('blog-images') . DIRECTORY_SEPARATOR;
        $relative = str_starts_with($coverPath, $root)
            ? substr($coverPath, strlen($root))
            : basename($coverPath);

        return '/storage/blog-images/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
