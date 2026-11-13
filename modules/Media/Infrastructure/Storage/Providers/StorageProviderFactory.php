<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Storage\Providers;

use Modules\Core\Infrastructure\Env;
use Modules\Uploads\Infrastructure\Storage\LocalStorageProvider;

/**
 * StorageProviderFactory — resolves the active MediaProviderInterface.
 *
 * When CLOUDINARY_CLOUD_NAME is set, returns CloudinaryProvider;
 * otherwise falls back to the local filesystem adapter.
 */
final class StorageProviderFactory
{
    private static ?MediaProviderInterface $instance = null;

    public static function create(): MediaProviderInterface
    {
        return self::$instance ??= self::resolve();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function resolve(): MediaProviderInterface
    {
        $cloudName = Env::get('CLOUDINARY_CLOUD_NAME', '');

        if ('' !== $cloudName) {
            return new CloudinaryProvider();
        }

        return new LocalStorageProviderAdapter(new LocalStorageProvider());
    }

    public static function instance(): MediaProviderInterface
    {
        if (null === self::$instance) {
            self::$instance = self::resolve();
        }

        return self::$instance;
    }
}
