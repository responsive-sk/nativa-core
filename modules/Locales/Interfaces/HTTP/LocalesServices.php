<?php

declare(strict_types = 1);

namespace Modules\Locales\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Locales\Application\Services\LocaleService;
use Modules\Locales\Application\Services\TranslationService;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Infrastructure\Services\JsonTranslationLoader;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;

/**
 * LocalesServices — lazy cached service factories for the Locales module.
 *
 * The Kernel instantiates action classes without dependency injection and
 * layouts are static, so shared Locales services are resolved through these
 * factories (same pattern as AuthServices/CardboardServices/SettingsServices).
 *
 * Also exposes t() — the C-pattern template translation helper: an explicit
 * static method, no global container access.
 */
final class LocalesServices
{
    private static ?LocaleService $locale = null;

    private static ?TranslationService $translations = null;

    /**
     * LocaleService with a default resolver reading settings.locale (config.db).
     */
    public static function locale(): LocaleService
    {
        return self::$locale ??= new LocaleService(
            static fn (): ?string => self::defaultLocaleFromSettings(),
        );
    }

    /**
     * TranslationService over locales.db with JSON file fallback.
     */
    public static function translations(): TranslationService
    {
        return self::$translations ??= new TranslationService(
            new TranslationRepository(
                DatabaseConnectionManager::instance()->connection('locales'),
            ),
            new JsonTranslationLoader(),
        );
    }

    /**
     * Template translation helper.
     *
     * @param string  $key     Translation key (e.g. nav.home)
     * @param string  $default Fallback value when no translation exists
     * @param ?string $locale  Optional locale code (defaults to active locale)
     *
     * @return string Translated value or the default/key
     */
    public static function t(string $key, string $default = '', ?string $locale = null): string
    {
        $activeLocale = self::locale()->current()->code();

        return self::translations()->get($key, $locale ?? $activeLocale, $default);
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$locale = null;
        self::$translations = null;
    }

    /**
     * Resolve the configured default locale from settings (fallback null).
     */
    private static function defaultLocaleFromSettings(): ?string
    {
        $settings = new SettingsRepository(
            DatabaseConnectionManager::instance()->connection('config'),
        );

        $value = $settings->get('locale', null);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
