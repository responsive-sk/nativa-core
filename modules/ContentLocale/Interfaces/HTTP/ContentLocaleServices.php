<?php

declare(strict_types = 1);

namespace Modules\ContentLocale\Interfaces\HTTP;

use Modules\ContentLocale\Application\Services\ContentLocaleMapper;
use Shell\ShellContextProviderRegistry;

/**
 * ContentLocaleServices — lazy factory for the content locale mapper.
 *
 * The Kernel instantiates action classes without dependency injection and
 * themes render from static context, so this is the shared wiring point. The
 * mapper itself is the module's rule; the default locale is still the
 * `ShellContextProvider` port's answer, never a module import in the kernel.
 */
final class ContentLocaleServices
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
