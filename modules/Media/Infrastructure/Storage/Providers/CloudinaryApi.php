<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Storage\Providers;

/**
 * CloudinaryApi — read-only Admin API client for the media library.
 *
 * CloudinaryProvider owns uploads and deletes (signed POSTs); this client
 * only reads — listing a folder, listing its child folders, and re-resolving
 * authoritative metadata for ids the admin picked. Import takes its numbers
 * from here, never from the browser.
 *
 * Two measured facts shape the request building:
 *
 * - `resources/search?folder=<path>` and `?prefix=<path>/` are **silently
 *   ignored** (both reported the whole account's 125 resources), so folder
 *   scoping uses `expression=folder:<path>` — the only form that narrowed
 *   the answer (26 for `nativa-media`).
 * - `GET /folders` and `GET /folders/{path}` return the tree directly.
 *
 * The `baseUrl` is injectable so the failure path is testable offline: a
 * client pointed at a closed local port must throw, and the action must
 * turn that into a message instead of a 500.
 *
 * Deliberately NOT `final` (the project otherwise prefers it): the class is
 * the transport seam, and CI has no Cloudinary credentials. Tests substitute
 * it with an anonymous subclass so the browse and import actions — including
 * their audit row and their redirect — are covered without a network call.
 */
readonly class CloudinaryApi
{
    private const int CONNECT_TIMEOUT = 5;

    private const int TIMEOUT = 10;

    private const int MAX_RETRIES = 3;

    private const int BASE_DELAY_MS = 200;

    /** The folder tile's square, doubled for a 2x screen. */
    private const int THUMB_SIZE = 68;



    /**
     * @param string $baseUrl Injectable for tests; the production default is the real API
     */
    public function __construct(
        private string $cloudName,
        #[\SensitiveParameter] private string $apiKey,
        #[\SensitiveParameter] private string $apiSecret,
        private string $folder = '',
        private string $baseUrl = 'https://api.cloudinary.com',
    ) {
    }

    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * The delivery URL for a public id — always https, never the http://
     * the Admin API returns in its `url` field.
     */
    public function url(string $publicId): string
    {
        return 'https://res.cloudinary.com/' . rawurlencode($this->cloudName) . '/image/upload/' . self::encodePath($publicId);
    }

    /**
     * Resources of one folder ('' = the whole account), with the cursor for
     * the next page.
     *
     * @return array{total_count: int, next_cursor: string, resources: list<array<string, mixed>>}
     */
    public function search(string $folderPath, int $max = 60, string $cursor = ''): array
    {
        return self::parseSearch($this->get($this->searchUrl($folderPath, $max, $cursor)));
    }

    /**
     * The exact request search() issues — public so the folder scoping can
     * be asserted offline: `expression=folder:<path>` is the only form that
     * narrows the answer.
     */
    public function searchUrl(string $folderPath, int $max = 60, string $cursor = ''): string
    {
        $query = ['max_results' => min(max($max, 1), 100)];
        if ('' !== $folderPath) {
            $query['expression'] = 'folder:' . $folderPath;
        }
        if ('' !== $cursor) {
            $query['next_cursor'] = $cursor;
        }

        return $this->endpoint('/resources/search', $query);
    }

    /**
     * One delivery URL per child folder, one request each, all in flight
     * together.
     *
     * The exact answer costs one request per tile: eight sequential Search
     * requests measured 5.6s on the account root, the same eight in parallel
     * measured 1.6s. A single page-wide search would be one request but a
     * wrong answer: this API rejects `sort_by`, and the account's first page
     * of images belongs to two busy folders, so most siblings silently lost
     * their preview (7 tiles had one, then 2). Every tile keeps its own
     * answer here.
     *
     * A child with no image is absent from the answer and keeps its glyph,
     * and so is a request that failed: painting a tile is not the moment to
     * fail the page.
     *
     * @param list<string> $childPaths
     *
     * @return array<string, string> child path => thumbnail URL
     */
    public function folderPreviews(array $childPaths): array
    {
        if ([] === $childPaths) {
            return [];
        }

        $urls = [];
        foreach ($childPaths as $path) {
            $urls[$path] = $this->firstImageUrl($path);
        }

        $previews = [];
        foreach ($this->getMany($urls) as $path => $body) {
            try {
                $listing = self::parseSearch($body);
            } catch (\RuntimeException) {
                continue;
            }

            $publicId = is_string($listing['resources'][0]['public_id'] ?? null)
                ? $listing['resources'][0]['public_id']
                : '';

            if ('' !== $publicId) {
                $previews[$path] = $this->thumbUrl($publicId);
            }
        }

        return $previews;
    }

    /**
     * The exact request one folder's preview is read from — public so the
     * expression can be asserted offline, the same seam searchUrl() gives the
     * folder scoping: `resource_type:image` keeps a video out of a tile.
     */
    public function firstImageUrl(string $folderPath): string
    {
        return $this->endpoint('/resources/search', [
            'expression'  => 'folder:' . $folderPath . ' AND resource_type:image',
            'max_results' => 1,
        ]);
    }

    /**
     * The delivery URL for a public id as a cropped square thumbnail.
     *
     * A folder tile is 34px, so THUMB_SIZE is 68: a 2x screen keeps its
     * pixels, and a glance at a folder never downloads the original. The
     * transform is part of the URL, so Cloudinary crops and caches it, and
     * the asset itself is never modified. `f_auto,q_auto` lets the CDN pick
     * the format the browser actually accepts (avif or webp) and the quality
     * for that format, which is free bytes on every card.
     */
    public function thumbUrl(string $publicId, ?int $size = null): string
    {
        $edge = max(1, $size ?? self::THUMB_SIZE);

        return 'https://res.cloudinary.com/' . rawurlencode($this->cloudName)
            . '/image/upload/c_fill,w_' . $edge . ',h_' . $edge . ',f_auto,q_auto/'
            . self::encodePath($publicId);
    }

    /**
     * Child folders of a path ('' = the top level of the account).
     *
     * @return list<array{name: string, path: string}>
     */
    public function folders(string $path): array
    {
        $url = '' === $path
            ? $this->endpoint('/folders', [])
            : $this->root() . '/folders/' . self::encodePath($path);

        return self::parseFolders($this->get($url));
    }

    /**
     * Authoritative metadata for picked public ids — the only source import
     * trusts for size, format and folder.
     *
     * @param list<string> $publicIds
     *
     * @return list<array<string, mixed>>
     */
    public function byIds(array $publicIds): array
    {
        $ids = array_values(array_filter(array_map(trim(...), $publicIds), static fn (string $id): bool => '' !== $id));
        if ([] === $ids) {
            return [];
        }

        $query = ['max_results' => count($ids)];
        $url = $this->endpoint('/resources/image', $query) . '&' . implode(
            '&',
            array_map(static fn (string $id): string => 'public_ids[]=' . rawurlencode($id), $ids),
        );

        return self::parseResources($this->get($url));
    }

    // ─── Response parsing (pure — unit-tested without a network) ─────

    /**
     * @return array{total_count: int, next_cursor: string, resources: list<array<string, mixed>>}
     */
    public static function parseSearch(string $json): array
    {
        $data = json_decode($json, true);

        return [
            'total_count' => is_array($data) && is_numeric($data['total_count'] ?? null)
                ? (int) $data['total_count']
                : count(self::resourcesOf($data)),
            'next_cursor' => is_array($data) && is_string($data['next_cursor'] ?? null) ? $data['next_cursor'] : '',
            'resources'   => self::resourcesOf($data),
        ];
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    public static function parseFolders(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['folders'] ?? null)) {
            throw new \RuntimeException('Cloudinary API returned an unexpected folder payload');
        }

        $folders = [];
        foreach ($data['folders'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                continue;
            }
            $path = $entry['path'];
            $folders[] = [
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : basename($path),
                'path' => $path,
            ];
        }

        return $folders;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function parseResources(string $json): array
    {
        $data = json_decode($json, true);

        return self::resourcesOf($data);
    }

    // ─── Transport ───────────────────────────────────────────────────

    /**
     * One GET with Basic auth, retrying the rate limit the way the provider
     * does. Throws on any non-200 or undecodable body — a caller that gets
     * an answer here knows the answer is usable.
     */
    private function get(string $url): string
    {
        if ('' === $this->cloudName || '' === $this->apiKey || '' === $this->apiSecret) {
            throw new \RuntimeException('Cloudinary is not configured (CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET)');
        }

        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $this->executeGet($url);

            if (429 === $result['httpCode'] && $attempt < self::MAX_RETRIES) {
                usleep($this->retryDelay($result['response'], $attempt) * 1000);
                continue;
            }

            if (200 === $result['httpCode']) {
                return $result['response'];
            }

            throw new \RuntimeException(self::errorMessage($result['httpCode'], $result['response']));
        }
    }

    /**
     * One GET per URL, at most one batch of them in flight, returning the
     * bodies that answered 200 OK.
     *
     * A folder tile needs one answer per folder, so a page with many folders
     * asks for many answers at once. Unbounded, that is one socket and one
     * Admin API call per folder: a hundred folders mean a hundred calls in
     * one request, which is how a 429 and a file-descriptor limit arrive.
     * The fetch is latency-bound rather than throughput-bound, so a batch
     * costs a round trip and the batches in a row cost a little more than
     * the slowest one; the page still renders, and a folder that never
     * answered keeps its glyph.
     *
     * Simpler than get() on purpose: no retry loop, because every caller
     * hands these URLs to a tile that already has a fallback. A failure is an
     * absent key, not an exception, and a rate limit just leaves glyphs.
     *
     * The default is 12: measured on the account's eight root folders, an
     * unbounded fetch answers in about 0.9 s and a bound of six in about
     * 1.6 s, because the second batch waits for the first, while a bound of
     * 12 answers the whole page in one batch. It still caps the sockets a
     * large account can open, and a caller with a reason may ask for
     * another bound.
     *
     * @param array<string, string> $urls key => URL
     *
     * @return array<string, string> key => body, only for 200 answers
     */
    private function getMany(array $urls, int $concurrency = 12): array
    {
        if ([] === $urls) {
            return [];
        }

        if ('' === $this->cloudName || '' === $this->apiKey || '' === $this->apiSecret) {
            return [];
        }

        $bodies = [];

        foreach (self::chunkRequests($urls, $concurrency) as $batch) {
            foreach ($this->runBatch($batch) as $key => $body) {
                $bodies[$key] = $body;
            }
        }

        return $bodies;
    }

    /**
     * Split the requested URLs into the batches the transport runs.
     *
     * Separated from the transfer because a network call cannot be asserted
     * offline: this is the decision, and the test pins the order, the keys
     * and the degenerate bound. A bound of zero or less would mean "ask for
     * nothing", which no caller wants, so it becomes one URL at a time.
     *
     * @param array<string, string> $urls key => URL
     *
     * @return list<array<string, string>>
     */
    public static function chunkRequests(array $urls, int $concurrency): array
    {
        return array_chunk($urls, max(1, $concurrency), true);
    }

    /**
     * One batch of transfers, all started together.
     *
     * @param array<string, string> $urls key => URL
     *
     * @return array<string, string> key => body, only for 200 answers
     */
    private function runBatch(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $this->apiKey . ':' . $this->apiSecret,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && CURLM_OK === $status);

        $bodies = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            if (200 === (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) && is_string($body)) {
                $bodies[$key] = $body;
            }

            curl_multi_remove_handle($multi, $ch);
        }

        curl_multi_close($multi);

        return $bodies;
    }

    /**
     * @return array{httpCode: int, response: string}
     */
    private function executeGet(string $url): array
    {
        if ('' === $url) {
            throw new \RuntimeException('Cloudinary API request URL is empty');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->apiKey . ':' . $this->apiSecret,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // No curl_close(): deprecated since PHP 8.5 (a no-op since 8.0) —
        // the handle is released when $ch goes out of scope.

        if (!is_string($response)) {
            throw new \RuntimeException('Cloudinary API unreachable: ' . ('' !== $error ? $error : 'no response'));
        }

        return ['httpCode' => $httpCode, 'response' => $response];
    }

    /**
     * Cloudinary's own `retry_after` when it sends one, otherwise the same
     * exponential backoff the upload path uses.
     */
    private function retryDelay(?string $body, int $attempt): int
    {
        if (null !== $body && '' !== $body) {
            $data = json_decode($body, true);
            $error = is_array($data) ? ($data['error'] ?? null) : null;
            $retryAfter = is_array($error) ? ($error['retry_after'] ?? null) : null;
            if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
                return (int) $retryAfter * 1000;
            }
        }

        return self::BASE_DELAY_MS * (2 ** ($attempt - 1));
    }

    private static function errorMessage(int $httpCode, string $body): string
    {
        $data = json_decode($body, true);
        $error = is_array($data) ? ($data['error'] ?? null) : null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        return is_string($message)
            ? sprintf('Cloudinary API request failed (HTTP %d): %s', $httpCode, $message)
            : sprintf('Cloudinary API request failed (HTTP %d)', $httpCode);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function endpoint(string $path, array $query): string
    {
        $url = $this->root() . $path;
        if ([] !== $query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    private function root(): string
    {
        return rtrim($this->baseUrl, '/') . '/v1_1/' . rawurlencode($this->cloudName);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * @param mixed $data
     *
     * @return list<array<string, mixed>>
     */
    private static function resourcesOf(mixed $data): array
    {
        if (!is_array($data) || !is_array($data['resources'] ?? null)) {
            throw new \RuntimeException('Cloudinary API returned an unexpected resource payload');
        }

        /** @var list<mixed> $entries */
        $entries = array_values($data['resources']);

        /** @var list<array<string, mixed>> $resources */
        $resources = array_values(array_filter($entries, is_array(...)));

        return $resources;
    }

    /**
     * Prevent credential leakage on inspection.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'cloudName' => $this->cloudName,
            // The redaction placeholder, not a credential: it is what the report
            // prints so the real values never reach a log line.
            // @mago-expect lint:no-literal-password
            'apiKey'    => '***redacted***', // audit-ok: redaction placeholder
            // @mago-expect lint:no-literal-password
            'apiSecret' => '***redacted***', // audit-ok: redaction placeholder
            'folder'    => $this->folder,
            'baseUrl'   => $this->baseUrl,
        ];
    }
}
