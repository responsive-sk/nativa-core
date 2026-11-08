<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

use Modules\Core\Infrastructure\Env;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Application\Services\LocalThumbnail;
use Modules\Media\Application\Services\MediaFolderService;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\MediaFolderRepository;
use Modules\Media\Infrastructure\Persistence\MediaRepository;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;
use Modules\Media\Infrastructure\Storage\Providers\MediaProviderInterface;
use Modules\Media\Infrastructure\Storage\Providers\StorageProviderFactory;

/**
 * MediaServices — lazy cached service factories for media actions.
 *
 * Same pattern as CardboardServices/AuditServices: actions resolve shared
 * services through these factories. Zero service locator outside Core.
 *
 * The storage provider is resolved via StorageProviderFactory — when
 * CLOUDINARY_CLOUD_NAME is set, uploads go to Cloudinary; otherwise
 * the local filesystem is used.
 */
final class MediaServices
{
    private static ?MediaService $media = null;

    private static ?MediaRepository $repository = null;

    private static ?MediaFolderRepository $folderRepository = null;

    private static ?MediaFolderService $folderService = null;

    private static ?MediaProviderInterface $storage = null;

    private static ?CloudinaryApi $cloudinaryApi = null;

    private static bool $cloudinaryApiResolved = false;

    public static function media(): MediaService
    {
        return self::$media ??= new MediaService(
            self::repository(),
            self::storage(),
            self::folderRepository(),
        );
    }

    public static function folderRepository(): MediaFolderRepository
    {
        return self::$folderRepository ??= new MediaFolderRepository(
            DatabaseConnectionManager::instance()->connection('media'),
        );
    }

    /**
     * The folder operations service. Shares the ONE media PDO with the
     * repository, because `UnitOfWork` nests per connection handle — two
     * connections would be two transactions that neither knows about the
     * other.
     */
    public static function folders(): MediaFolderService
    {
        return self::$folderService ??= new MediaFolderService(
            self::folderRepository(),
            DatabaseConnectionManager::instance()->connection('media'),
        );
    }

    public static function repository(): MediaRepository
    {
        return self::$repository ??= new MediaRepository(
            DatabaseConnectionManager::instance()->connection('media'),
        );
    }

    public static function storage(): MediaProviderInterface
    {
        return self::$storage ??= StorageProviderFactory::create();
    }

    /**
     * The read-only Admin API client for browsing/importing the Cloudinary
     * library, or null when the environment does not carry all three
     * credentials. Null is a first-class answer: the browse page renders
     * "not configured" and never makes an outbound request.
     */
    public static function cloudinaryApi(): ?CloudinaryApi
    {
        if (!self::$cloudinaryApiResolved) {
            self::$cloudinaryApiResolved = true;
            self::$cloudinaryApi = self::buildCloudinaryApi();
        }

        return self::$cloudinaryApi;
    }

    private static function buildCloudinaryApi(): ?CloudinaryApi
    {
        $cloudName = Env::get('CLOUDINARY_CLOUD_NAME', '') ?? '';
        $apiKey = Env::get('CLOUDINARY_API_KEY', '') ?? '';
        $apiSecret = Env::get('CLOUDINARY_API_SECRET', '') ?? '';

        if ('' === $cloudName || '' === $apiKey || '' === $apiSecret) {
            return null;
        }

        return new CloudinaryApi($cloudName, $apiKey, $apiSecret, Env::get('CLOUDINARY_FOLDER', '') ?? '');
    }

    /**
     * The public URL for a tracked asset, or '' when its provider cannot be
     * built in this environment — a `provider = 'cloudinary'` row while the
     * Cloudinary env is unset constructs a CloudinaryProvider that throws.
     * Rendering a card is not the moment to fail: one unconfigured external
     * service must never be able to blank the media library, so callers fall
     * back to a placeholder when this returns ''.
     */
    public static function urlFor(MediaAsset $asset): string
    {
        try {
            $storage = MediaService::resolveProvider($asset->provider(), self::storage());

            return $storage->url($asset->path());
        } catch (\RuntimeException) {
            return '';
        }
    }

    /**
     * The URL a tile should load for an asset: a cropped cloudinary square
     * when the file is remote, the square written beside a local upload, and
     * the stored file itself when neither exists.
     *
     * Asking Cloudinary for the original would make a glance at the library
     * download megabytes, so the remote side answers with a square crop. The
     * edge is the caller's, because two surfaces show two sizes: a folder
     * tile is 34px (68 on a 2x screen), and a card in the grid is 150px tall,
     * which asks for 300. A local file gets one square at store time when
     * ext-gd is present, and has no resize step when it is not, so the
     * original stays the answer there. '' means "render the placeholder"
     * the way urlFor() does. A null edge is the tile default, so that number
     * stays owned by the client and not re-spelt here.
     */
    public static function previewUrl(MediaAsset $asset, ?int $edge = null): string
    {
        if ('cloudinary' !== $asset->provider()) {
            return LocalThumbnail::url($asset->path()) ?? self::urlFor($asset);
        }

        return self::cloudinaryApi()?->thumbUrl($asset->path(), $edge) ?? '';
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$media = null;
        self::$repository = null;
        self::$folderRepository = null;
        self::$folderService = null;
        self::$storage = null;
        self::$cloudinaryApi = null;
        self::$cloudinaryApiResolved = false;
        StorageProviderFactory::reset();
    }
}
