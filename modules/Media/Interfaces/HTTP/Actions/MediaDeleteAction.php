<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Media delete — POST /mark/media/{id}/delete.
 *
 * Removes the asset from media.db AND the filesystem. Logs the deletion
 * to the audit log. Redirects back to the media index.
 */
#[Route(path: '/mark/media/{id}/delete', method: 'POST', name: 'media.delete')]
final class MediaDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id) {
            $asset = MediaServices::media()->findById($id);

            if (null !== $asset) {
                $deleted = MediaServices::media()->delete($id);

                if ($deleted) {
                    CacheInvalidator::flush();
                    AuditServices::log(
                        'media.delete',
                        'media',
                        'Deleted: ' . $asset->originalName() . ' (' . $asset->path() . ')',
                        $request->getClientIp(),
                    );
                }
            }
        }

        return Response::redirect($this->url('media.index'));
    }
}
