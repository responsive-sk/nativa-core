<?php

declare(strict_types = 1);

namespace Modules\Locales\Application\Services;

use Modules\Locales\Domain\Model\Locale;

/**
 * LocaleService — active locale detection and management.
 *
 * Resolves the current locale from the middleware chain (URL prefix,
 * X-Locale header, cookie, session) and provides locale-aware utilities
 * for templates and layouts.
 *
 * Detection priority:
 *   1. Explicit set() from the middleware
 *   2. Default from the configured setting (settings.locale, seeded en_US)
 *   3. Fallback to built-in default (sk_SK)
 *
 * The default resolver is provided by the LocalesServices lazy factory so
 * this service stays free of feature-module dependencies (C-pattern).
 */
final class LocaleService
{
    private ?Locale $currentLocale = null;

    /**
     * @param \Closure(): ?string $defaultResolver Returns a configured locale code or null
     */
    public function __construct(
        private readonly ?\Closure $defaultResolver = null,
    ) {
    }

    /**
     * Get the currently active locale.
     */
    public function current(): Locale
    {
        return $this->currentLocale ?? $this->default();
    }

    /**
     * Set the active locale (called from middleware).
     */
    public function set(Locale | string $locale): void
    {
        $this->currentLocale = is_string($locale) ? Locale::fromString($locale) : $locale;
    }

    /**
     * Get all available locales.
     *
     * @return array<string, Locale>
     */
    public function available(): array
    {
        return Locale::available();
    }

    /**
     * Check if a locale code is available in the system.
     */
    public function isAvailable(string $code): bool
    {
        try {
            return Locale::fromString($code)->isAvailable();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Get the default locale from the configured setting or built-in default.
     */
    public function default(): Locale
    {
        if (null !== $this->defaultResolver) {
            $code = ($this->defaultResolver)();

            if (is_string($code) && '' !== $code && $this->isAvailable($code)) {
                return Locale::fromString($code);
            }
        }

        return Locale::default();
    }

    /**
     * Get the HTML lang attribute value (language part only, e.g. "sk", "en").
     */
    public function htmlLang(): string
    {
        return $this->current()->language();
    }

    /**
     * Reset the cached locale (forces re-resolve on next current() call).
     */
    public function reset(): void
    {
        $this->currentLocale = null;
    }
}
