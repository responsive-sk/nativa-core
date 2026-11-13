<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;
use Modules\Media\Interfaces\HTTP\MediaCloudinarySelection;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Cloudinary import — POST /mark/media/cloudinary/import.
 *
 * Turns picked public ids into tracked rows in media.db. The form posts
 * ids only: every byte of metadata (size, format, folder) is re-resolved
 * server-side through CloudinaryApi::byIds(), because a form that accepts
 * numbers from the browser eventually accepts somebody else's numbers.
 *
 * Idempotent on `path`, capped at MediaCloudinarySelection::MAX_IDS ids per
 * run, audited as `media.import`, and it always redirects back with the
 * counts so the browse page can paint them.
 */
#[Route(path: '/mark/media/cloudinary/import', method: 'POST', name: 'media.cloudinary.import')]
final readonly class MediaCloudinaryImportAction
{
    use ResolvesRouteUrls;

    /**
     * @param CloudinaryApi|null $api Test seam: CI has no credentials, so a
     *                                substituted client is the only way to
     *                                exercise this caller offline
     */
    public function __construct(
        private ?CloudinaryApi $api = null,
        ?RouterInterface $router = null,
    ) {
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        $folder = MediaService::normalizeFolder($request->bodyString('folder'));
        $back = $this->url('media.cloudinary') . ('' !== $folder ? '?' . http_build_query(['folder' => $folder]) : '');

        $api = $this->api ?? MediaServices::cloudinaryApi();
        if (null === $api) {
            return Response::redirect($back);
        }

        $ids = MediaCloudinarySelection::publicIds($request);
        if ([] === $ids) {
            return Response::redirect(self::withParams($back, ['error' => 'selection']));
        }
        if (count($ids) > MediaCloudinarySelection::MAX_IDS) {
            return Response::redirect(self::withParams($back, ['error' => 'too-many']));
        }

        try {
            $resources = $api->byIds($ids);
        } catch (\RuntimeException) {
            // The library is unreachable mid-import: nothing was written,
            // so say so instead of 500-ing an admin screen.
            return Response::redirect(self::withParams($back, ['error' => 'unreachable']));
        }

        $counts = MediaServices::media()->importCloudinaryResources($resources, $this->actor(), $ids);
        $imported = $counts['imported'];
        $skipped = $counts['skipped'];

        CacheInvalidator::flush();
        AuditServices::log(
            'media.import',
            'media',
            "Imported {$imported}, skipped {$skipped}",
            $request->getClientIp(),
        );

        return Response::redirect(self::withParams($back, ['imported' => $imported, 'skipped' => $skipped]));
    }

    private function actor(): string
    {
        $auth = AuditServices::auth();
        $userId = $auth->currentUserId();

        return null !== $userId ? ($auth->currentUser()?->name() ?? 'admin') : 'admin';
    }

    /**
     * @param array<string, int|string> $params
     */
    private static function withParams(string $back, array $params): string
    {
        return $back . (str_contains($back, '?') ? '&' : '?') . http_build_query($params);
    }
}
