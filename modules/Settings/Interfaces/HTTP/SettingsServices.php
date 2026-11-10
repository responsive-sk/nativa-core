<?php

declare(strict_types = 1);

namespace Modules\Settings\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Application\Services\MenuService;
use Modules\Settings\Application\Services\SettingsService;
use Modules\Settings\Infrastructure\Persistence\Repositories\MenuRepository;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;

/**
 * SettingsServices — lazy cached service factories for public-facing code.
 *
 * The Kernel instantiates action classes without dependency injection and
 * layouts are static, so shared Settings services are resolved through
 * these factories (same pattern as CardboardServices/AuthServices).
 * Connections are cached by DatabaseConnectionManager.
 */
final class SettingsServices
{
    private static ?MenuService $menu = null;

    private static ?SettingsService $settings = null;

    public static function menu(): MenuService
    {
        return self::$menu ??= new MenuService(
            new MenuRepository(
                DatabaseConnectionManager::instance()->connect('config'),
            ),
        );
    }

    public static function settings(): SettingsService
    {
        return self::$settings ??= new SettingsService(
            new SettingsRepository(
                DatabaseConnectionManager::instance()->connect('config'),
            ),
        );
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$menu = null;
        self::$settings = null;
    }
}
