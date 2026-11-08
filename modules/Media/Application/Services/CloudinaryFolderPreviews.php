<?php

declare(strict_types = 1);

namespace Modules\Media\Application\Services;

use Modules\Media\Infrastructure\Cache\CloudinaryPreviewCache;
use Modules\Media\Infrastructure\Storage\Providers\CloudinaryApi;

/**
 * CloudinaryFolderPreviews — the folder tiles' previews, cached for a while.
 *
 * The tiles need one image per folder, and the API answers that one request
 * per folder. This class is the only place that decides which of those
 * answers are already known: a folder with a fresh cache entry costs no
 * request at all, and the folders that are missing one are fetched in one
 * parallel batch.
 *
 * An empty folder is remembered as '' (no image), because otherwise every
 * render would ask again about every folder that has no picture. A folder
 * that was asked about and failed is remembered the same way, which is why
 * the TTL is short (see CloudinaryPreviewCache).
 */
final readonly class CloudinaryFolderPreviews
{
    public function __construct(
        private CloudinaryPreviewCache $cache,
    ) {
    }

    /**
     * @param list<string> $folders
     *
     * @return array<string, string> folder => thumbnail URL ('' = no image)
     */
    public function previewsFor(CloudinaryApi $api, array $folders): array
    {
        if ([] === $folders) {
            return [];
        }

        $cached = $this->cache->fresh($folders);
        $missing = array_values(array_diff($folders, array_keys($cached)));
        if ([] === $missing) {
            return $cached;
        }

        $fetched = $api->folderPreviews($missing);

        // An unanswered folder is an answer too: remember it so the next
        // render of this page does not ask again. Built in the requested
        // order, because the answer's order is the tiles' order and a cold
        // render must not lay the folders out differently than a warm one.
        $fresh = [];
        foreach ($missing as $folder) {
            $fresh[$folder] = $fetched[$folder] ?? '';
        }

        $this->cache->remember($fresh);

        $previews = [];
        foreach ($folders as $folder) {
            $previews[$folder] = $cached[$folder] ?? $fresh[$folder] ?? '';
        }

        return $previews;
    }
}
