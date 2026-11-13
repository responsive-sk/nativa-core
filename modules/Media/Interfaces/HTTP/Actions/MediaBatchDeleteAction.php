<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\PageCache\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Media batch delete — POST /mark/media/batch-delete.
 *
 * Accepts an array of asset IDs (from checkboxes) and deletes them all
 * from DB + filesystem. Logs a single audit entry per batch. Redirects
 * back to the media index.
 */
#[Route(path: '/mark/media/batch-delete', method: 'POST', name: 'media.batch-delete')]
final class MediaBatchDeleteAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $rawIds = $request->body('ids');

        /** @var list<string> $ids */
        $ids = is_array($rawIds) ? array_values(array_filter($rawIds, static fn (mixed $v): bool => is_string($v) && '' !== $v)) : [];

        if ([] !== $ids) {
            $deletedIds = MediaServices::media()->batchDelete($ids);

            if ([] !== $deletedIds) {
                CacheInvalidator::flush();
                AuditServices::log(
                    'media.batch-delete',
                    'media',
                    'Batch deleted ' . count($deletedIds) . ' file(s): ' . implode(', ', $deletedIds),
                    $request->getClientIp(),
                );
            }
        }

        return Response::redirect($this->url('media.index'));
    }
}
