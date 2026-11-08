<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * ContentLocaleMapper — maps a locale code to its storage subdirectory.
 *
 * One rule, shared by every localized content family (blog, pages):
 *
 *     directory($family, $locale) === $locale === $defaultLocale()
 *         ? $family                       // the family root — default locale
 *         : $family . '/' . $languageCode  // one subdirectory per other language
 *
 * The default locale comes from settings.locale, NOT from a hardcoded Slovak
 * literal. That literal was the source of three separate bugs: the blog
 * rendered Slovak copy under `<html lang="en">`, pages silently mapped every
 * unknown code to Slovak, and the storage layout drifted away from the
 * canonical spec while two independent call sites each hardcoded their own
 * idea of the layout.
 *
 * Lives in Core (shared kernel) because Blog AND Pages both need it, and
 * because Core is the only layer allowed to be imported across module
 * boundaries. The default resolver is injected as a closure so this class
 * never touches the Settings module itself — same pattern as LocaleService.
 */
final readonly class ContentLocaleMapper
{
    /** Fallback when the resolver yields nothing usable. */
    private const string FALLBACK_DEFAULT = 'sk_SK';

    /** @var array<string, string> Known locale codes → language directory names. */
    private const array LANGUAGES = [
        'sk_SK' => 'sk',
        'en_US' => 'en',
    ];

    /**
     * @param \Closure(): ?string $defaultResolver Returns settings.locale, or null when unset
     */
    public function __construct(
        private \Closure $defaultResolver,
    ) {
    }

    /**
     * The configured default locale code (settings.locale), normalised.
     */
    public function defaultLocale(): string
    {
        $code = ($this->defaultResolver)();

        return is_string($code) && isset(self::LANGUAGES[$code]) ? $code : self::FALLBACK_DEFAULT;
    }

    /**
     * Is this locale the configured default (the one stored in the root)?
     */
    public function isDefault(string $locale): bool
    {
        return $this->code($locale) === $this->defaultLocale();
    }

    /**
     * Storage subdirectory for a locale within a content family.
     *
     * Unknown codes resolve to the DEFAULT's directory — never to a hardcoded
     * language. A typo must fail towards the configured default, not silently
     * serve the wrong language.
     */
    public function directory(string $family, string $locale): string
    {
        if ($this->isDefault($locale)) {
            return $family;
        }

        return $family . '/' . $this->languageCode($locale);
    }

    /**
     * Language directory name ('sk_SK' → 'sk').
     */
    public function languageCode(string $locale): string
    {
        return self::LANGUAGES[$this->code($locale)];
    }

    /**
     * URL prefix segment for a locale: '' for the default (it owns the bare
     * path), 'en' for every other locale.
     *
     * This is the inverse of the hardcoded 'en_US' === $locale ? '/en' : ''
     * ternaries: those encode "Slovak is the default" rather than "the
     * configured default owns the bare path".
     */
    public function urlPrefix(string $locale): string
    {
        return $this->isDefault($locale) ? '' : '/' . $this->languageCode($locale);
    }

    /**
     * Every locale the system supports, default first.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $default = $this->defaultLocale();
        $codes = array_keys(self::LANGUAGES);

        return [$default, ...array_values(array_diff($codes, [$default]))];
    }

    /**
     * Normalise a raw locale value to its canonical code: 'sk' → 'sk_SK',
     * 'en' → 'en_US'. Unknown codes resolve to the configured default rather
     * than throwing — a stale admin query param must not take a page down.
     */
    public function code(string $raw): string
    {
        $raw = trim($raw);

        if (isset(self::LANGUAGES[$raw])) {
            return $raw;
        }

        foreach (self::LANGUAGES as $code => $language) {
            if ($language === strtolower($raw)) {
                return $code;
            }
        }

        return $this->defaultLocale();
    }
}
