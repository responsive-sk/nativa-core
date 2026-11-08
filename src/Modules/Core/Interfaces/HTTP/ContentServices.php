<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Application\Services\ContentLocaleMapper;
use Modules\Core\Application\Services\ShellContextProviderRegistry;

/**
 * ContentServices — lazy factory for the ContentLocaleMapper.
 *
 * The Kernel instantiates action classes without dependency injection and
 * service factories are the established wiring point. This one lives in Core
 * because Blog, Pages, Cardboard and the CLI all need the same mapper and no
 * feature module may import another feature module.
 *
 * The default-locale closure now asks the `ShellContextProvider` port instead
 * of `LocalesServices`. Which locale owns the content root is still nobody's
 * hardcoded guess — it is the provider's answer, and a tree with no provider
 * gets the mapper's own declared fallback rather than a kernel that refuses to
 * render. One settings read, one answer, no module import.
 */
final class ContentServices
{
    private static ?ContentLocaleMapper $mapper = null;

    /**
     * Mapper whose default locale tracks the configured `settings.locale`
     * through the shell port.
     */
    public static function mapper(): ContentLocaleMapper
    {
        return self::$mapper ??= new ContentLocaleMapper(
            static fn (): ?string => ShellContextProviderRegistry::defaultLocale(),
        );
    }

    /**
     * Reset the cached instance (test isolation, admin settings writes).
     */
    public static function reset(): void
    {
        self::$mapper = null;
    }
}
