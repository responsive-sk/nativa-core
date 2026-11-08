<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Request;

/**
 * MediaCloudinarySelection — the public ids one Cloudinary form carries.
 *
 * Both library actions (import and delete) post the same checkbox list, so
 * the shape check lives here once: non-empty, de-duplicated, no control
 * characters, no traversal, and never more than MAX_IDS in one batch. The
 * Admin API re-resolves every id it is asked about anyway; this only keeps
 * obvious junk off the wire and bounds the number of outbound calls one
 * submission can cause.
 */
final class MediaCloudinarySelection
{
    public const int MAX_IDS = 50;

    /**
     * @return list<string>
     */
    public static function publicIds(Request $request): array
    {
        $raw = $request->body('public_ids');
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $id = trim($value);
            if ('' === $id || strlen($id) > 255 || str_contains($id, '..')) {
                continue;
            }
            if (1 === preg_match('/[\x00-\x1F\x7F]/', $id)) {
                continue;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
