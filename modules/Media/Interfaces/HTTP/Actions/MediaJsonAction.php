<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Media JSON — GET /mark/media/json.
 *
 * Returns a JSON array of media assets (id, url, original_name, mime_type)
 * for use by the media picker in the article editor. Images only by default.
 */
#[Route(path: '/mark/media/json', method: 'GET', name: 'media.json')]
final class MediaJsonAction
{
    public function handle(Request $request): Response
    {
        $service = MediaServices::media();
        $assets = $service->list(1, 200);

        $defaultStorage = MediaServices::storage();
        $items = [];

        foreach ($assets as $asset) {
            $items[] = [
                'id'            => $asset->id(),
                // '' when the provider cannot be built in this environment —
                // an unconfigured Cloudinary must not take the picker down.
                'url'           => MediaServices::urlFor($asset),
                'original_name' => $asset->originalName(),
                'mime_type'     => $asset->mimeType(),
                'provider'      => $asset->provider() ?: $defaultStorage->getName(),
            ];
        }

        return Response::json($items);
    }
}
