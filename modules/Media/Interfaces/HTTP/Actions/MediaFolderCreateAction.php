<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Media\Interfaces\HTTP\MediaFolderResponses;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Create a media folder — POST /mark/media/folders/create.
 *
 * A folder is a record, not a side effect of a file: creating one here is what
 * makes an empty folder representable at all, and it is the only place a
 * registry row is born from user input.
 *
 * The refusal is the interesting half. A malformed name is refused with a
 * message and stored NOWHERE — the service never coerces it to the root, so
 * "create `..`" cannot quietly become "create nothing, and claim success".
 */
#[Route(path: '/mark/media/folders/create', method: 'POST', name: 'media.folder-create')]
final class MediaFolderCreateAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $raw = $request->body('path');
        $result = MediaServices::folders()->create(is_string($raw) ? $raw : '');

        if (!$result->ok) {
            return MediaFolderResponses::refusal($this->router(), $request, $result->error());
        }

        $path = $result->context['path'];

        CacheInvalidator::flush();
        AuditServices::log(
            'media.folder-create',
            'media',
            'Created folder: ' . $path,
            $request->getClientIp(),
        );

        return MediaFolderResponses::success($this->router(), $request, 'Folder ' . $path . ' created.', $path);
    }
}
