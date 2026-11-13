<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Cache;

/**
 * CloudinaryPreviewCache — the folder tiles' preview URLs, for a few minutes.
 *
 * A library page asks the Cloudinary Search API one request per folder tile,
 * and those answers change only when somebody uploads or deletes a file. The
 * cache is the difference between "open the library twice" and "pay for the
 * previews twice": the second render reads one JSON file.
 *
 * Staleness is bounded twice. TTL is the same 300 seconds the page cache
 * uses, and every media write that changes the remote library forgets the
 * file (MediaService), so a fresh upload is never hidden behind a stale
 * preview.
 *
 * A negative entry ('' URL) is stored too: "this folder has no image" is an
 * answer, and without it every render would ask again about every empty
 * folder. A failed request is indistinguishable from an empty folder at this
 * layer, which is the second reason the TTL is short.
 *
 * The cache is an optimisation, never a source of truth: an unreadable or
 * unwritable file means an empty cache, not an error.
 */
final readonly class CloudinaryPreviewCache
{
    /** Same staleness contract as the page cache. */
    public const int TTL = 300;

    public const string FILE = 'cloudinary-previews.json';

    /**
     * @param string $dir Absolute cache directory; '' means the app's
     *                    storage/cache. Tests pass a temp dir.
     */
    public function __construct(
        private string $dir = '',
    ) {
    }

    /**
     * The fresh entries for the asked folders, keyed by folder path.
     *
     * @param list<string> $folders
     * @param int|null     $now     Test seam: the clock the TTL is read against
     *
     * @return array<string, string> folder => thumbnail URL ('' = no image)
     */
    public function fresh(array $folders, ?int $now = null): array
    {
        $now ??= time();
        $entries = $this->read();
        $fresh = [];

        foreach ($folders as $folder) {
            $entry = $entries[$folder] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $url = $entry['url'] ?? null;
            $expires = $entry['expires'] ?? null;
            if (!is_string($url) || !is_numeric($expires) || (int) $expires <= $now) {
                continue;
            }

            $fresh[$folder] = $url;
        }

        return $fresh;
    }

    /**
     * Store one batch of previews, dropping entries that have expired.
     *
     * @param array<string, string> $previews folder => thumbnail URL ('' = no image)
     */
    public function remember(array $previews): void
    {
        if ([] === $previews) {
            return;
        }

        $now = time();
        $entries = [];

        foreach ($this->read() as $folder => $entry) {
            if (!is_array($entry) || !is_numeric($entry['expires'] ?? null) || (int) $entry['expires'] <= $now) {
                continue;
            }

            $entries[$folder] = $entry;
        }

        $expires = $now + self::TTL;
        foreach ($previews as $folder => $url) {
            $entries[$folder] = ['url' => $url, 'expires' => $expires];
        }

        $this->write($entries);
    }

    /**
     * Drop every entry. Called by the writes that change the remote library.
     */
    public function forget(): void
    {
        $file = $this->file();

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function file(): string
    {
        $dir = '' !== $this->dir ? rtrim($this->dir, '/') : APP_BASE_PATH . '/storage/cache';

        return $dir . '/' . self::FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array{url: string, expires: int}> $entries
     */
    private function write(array $entries): void
    {
        $file = $this->file();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($file, json_encode($entries), LOCK_EX);
    }
}
