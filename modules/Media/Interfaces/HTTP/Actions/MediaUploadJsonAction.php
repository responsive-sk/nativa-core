<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaServices;
use Modules\Media\Interfaces\HTTP\MediaUploadLimits;
use Modules\Media\Interfaces\HTTP\MediaUploadPayload;

/**
 * Media upload JSON — POST /mark/media/upload-json.
 *
 * AJAX endpoint for inline image uploads from the markdown editor.
 * Accepts a single file, uploads it via MediaService, and returns
 * the asset URL as JSON for insertion into the editor.
 */
#[Route(path: '/mark/media/upload-json', method: 'POST', name: 'media.upload.json')]
final class MediaUploadJsonAction
{
    public function handle(Request $request): Response
    {
        $rawFile = MediaUploadPayload::files()['file'] ?? null;

        if (!is_array($rawFile)) {
            // Empty $_POST alongside a missing file means PHP dropped the
            // request body (post_max_size exceeded) before the route ran.
            if ([] === $request->getBodyParams()) {
                return Response::json([
                    'error' => 'Upload failed: the file exceeds the server upload limit ('
                    . MediaUploadLimits::label() . ').',
                ], 413);
            }

            return Response::json(['error' => 'No file provided.'], 400);
        }

        /** @var array<string, mixed> $file */
        $file = $rawFile;

        try {
            $auth = AuditServices::auth();
            $userId = $auth->currentUserId();
            $actorName = null !== $userId ? ($auth->currentUser()?->name() ?? 'admin') : 'admin';

            $provider = $request->body('provider');
            $asset = MediaServices::media()->upload(
                $file,
                $actorName,
                is_string($provider) ? $provider : '',
            );

            CacheInvalidator::flush();
            AuditServices::log(
                'media.upload',
                'media',
                'Uploaded: ' . $asset->originalName() . ' (' . $this->formatSize($asset->size()) . ')',
                $request->getClientIp(),
            );

            $storage = MediaServices::storage();
            $url = $storage->url($asset->path());

            return Response::json([
                'id'            => $asset->id(),
                'url'           => $url,
                'original_name' => $asset->originalName(),
                'mime_type'     => $asset->mimeType(),
                'provider'      => $asset->provider() ?: $storage->getName(),
            ]);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
