<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Infrastructure\Storage\Providers\MediaProviderInterface;
use Modules\Media\Interfaces\HTTP\MediaCloudinarySelection;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Cloudinary delete — POST /mark/media/cloudinary/delete.
 *
 * The library page used to be read-only, so a test upload could only be
 * removed by importing it first and deleting the tracked row. This is the
 * other half: the checked public ids are destroyed at the CDN through the
 * same signed `image/destroy` the row delete uses, and any row that pointed
 * at a deleted id is forgotten with it.
 *
 * Destructive and irreversible, so it is a POST behind the admin CSRF field,
 * guarded by the confirm dialog, capped at MediaCloudinarySelection::MAX_IDS
 * and refused outright when the credentials are absent (nothing to sign the
 * destroy with). Audited as `media.cloudinary-delete`, and it always
 * redirects back with the counts so the browse page can paint them.
 */
#[Route(path: '/mark/media/cloudinary/delete', method: 'POST', name: 'media.cloudinary.delete')]
final readonly class MediaCloudinaryDeleteAction
{
    use ResolvesRouteUrls;

    /**
     * @param MediaService|null          $service  Test seam: the delete itself is
     *                                             asserted without opening media.db
     * @param MediaProviderInterface|null $provider Test seam: CI has no credentials,
     *                                             so a substituted provider is the
     *                                             only way to exercise this caller
     *                                             offline
     */
    public function __construct(
        private ?MediaService $service = null,
        private ?MediaProviderInterface $provider = null,
        ?RouterInterface $router = null,
    ) {
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        $folder = MediaService::normalizeFolder($request->bodyString('folder'));
        $back = $this->url('media.cloudinary') . ('' !== $folder ? '?' . http_build_query(['folder' => $folder]) : '');

        $provider = $this->provider ?? self::configuredProvider();
        if (null === $provider) {
            return Response::redirect(self::withParams($back, ['error' => 'unconfigured']));
        }

        $ids = MediaCloudinarySelection::publicIds($request);
        if ([] === $ids) {
            return Response::redirect(self::withParams($back, ['error' => 'selection']));
        }
        if (count($ids) > MediaCloudinarySelection::MAX_IDS) {
            return Response::redirect(self::withParams($back, ['error' => 'too-many']));
        }

        $counts = ($this->service ?? MediaServices::media())->deleteRemoteResources($provider, $ids);

        CacheInvalidator::flush();
        AuditServices::log(
            'media.cloudinary-delete',
            'media',
            sprintf(
                'Deleted %d remote file(s), forgot %d tracked row(s), %d failed',
                $counts['deleted'],
                $counts['rows'],
                $counts['failed'],
            ),
            $request->getClientIp(),
        );

        return Response::redirect(self::withParams($back, [
            'deleted' => $counts['deleted'],
            'rows'    => $counts['rows'],
            'failed'  => $counts['failed'],
        ]));
    }

    /**
     * The provider that can destroy a remote file, or null when this
     * environment carries no credentials. The gate is the Admin API client,
     * because a destroy call needs the same key and secret an import does.
     */
    private static function configuredProvider(): ?MediaProviderInterface
    {
        if (null === MediaServices::cloudinaryApi()) {
            return null;
        }

        return MediaService::resolveProvider('cloudinary', MediaServices::storage());
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function withParams(string $back, array $params): string
    {
        return $back . (str_contains($back, '?') ? '&' : '?') . http_build_query($params);
    }
}
