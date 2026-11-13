<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Storage\Providers;

use Modules\Core\Infrastructure\Env;

/**
 * CloudinaryProvider — remote storage via Cloudinary REST API.
 *
 * Uses raw cURL (no composer dependency). Configured via env vars:
 *   CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET,
 *   CLOUDINARY_UPLOAD_PRESET (optional, for unsigned uploads).
 *
 * Implements exponential backoff for 429 rate-limit responses (3 retries,
 * 1s/2s/4s delays). Max upload size: 50 MB (Cloudinary limit).
 */
final readonly class CloudinaryProvider implements MediaProviderInterface
{
    private const int MAX_RETRIES = 3;

    private const int BASE_DELAY_MS = 1000;

    private const int CONNECT_TIMEOUT = 30;

    private const int TIMEOUT = 60;

    private const int MAX_BYTES = 50 * 1024 * 1024;

    private string $cloudName;

    private string $apiKey;

    private string $apiSecret;

    private string $uploadPreset;

    private string $folder;

    public function __construct(
        ?string $cloudName = null,
        #[\SensitiveParameter] ?string $apiKey = null,
        #[\SensitiveParameter] ?string $apiSecret = null,
        ?string $uploadPreset = null,
        ?string $folder = null,
    ) {
        $this->cloudName = $cloudName ?? Env::get('CLOUDINARY_CLOUD_NAME') ?? '';
        $this->apiKey = $apiKey ?? Env::get('CLOUDINARY_API_KEY') ?? '';
        $this->apiSecret = $apiSecret ?? Env::get('CLOUDINARY_API_SECRET') ?? '';
        $this->uploadPreset = $uploadPreset ?? Env::get('CLOUDINARY_UPLOAD_PRESET') ?? '';
        $this->folder = $folder ?? Env::get('CLOUDINARY_FOLDER') ?? 'nativa-media';

        if ('' === $this->cloudName) {
            throw new \RuntimeException('CLOUDINARY_CLOUD_NAME not configured');
        }
    }

    public function store(array $file): array
    {
        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $error = $file['error'] ?? null;

        if ('' === $tmpName || !is_numeric($error) || UPLOAD_ERR_OK !== (int) $error) {
            throw new \RuntimeException('Invalid file upload');
        }
        if (!is_uploaded_file($tmpName) && !is_file($tmpName)) {
            throw new \RuntimeException('Invalid file upload path');
        }

        $size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;
        $realSize = @filesize($tmpName);
        if ($size > self::MAX_BYTES || (false !== $realSize && $realSize > self::MAX_BYTES)) {
            throw new \RuntimeException('File size exceeds maximum allowed size (50 MB)');
        }

        $originalName = is_string($file['name'] ?? null) ? $file['name'] : '';
        $mimeType = is_string($file['type'] ?? null) ? $file['type'] : $this->detectMimeType($tmpName);

        $uploadUrl = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";
        $timestamp = time();

        $postData = [
            'file'      => new \CURLFile($tmpName, $mimeType, $originalName),
            'folder'    => $this->folder,
            'timestamp' => $timestamp,
        ];

        $result = $this->uploadWithAuth($uploadUrl, $postData, $this->folder, $timestamp);

        if (!isset($result['secure_url']) || !isset($result['public_id'])) {
            throw new \RuntimeException('Invalid Cloudinary response');
        }

        $publicId = is_string($result['public_id']) ? $result['public_id'] : '';
        $secureUrl = is_string($result['secure_url']) ? $result['secure_url'] : '';
        $resultSize = isset($result['bytes']) && is_numeric($result['bytes'])
            ? (int) $result['bytes']
            : ($size > 0 ? $size : (false !== $realSize ? (int) $realSize : 0));

        return [
            'path'      => $publicId,
            'url'       => $secureUrl,
            'size'      => $resultSize,
            'mime_type' => $mimeType,
        ];
    }

    public function delete(string $path): bool
    {
        if ('' === $this->apiKey || '' === $this->apiSecret) {
            return false;
        }

        $deleteUrl = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/destroy";
        $timestamp = time();

        $postData = [
            'public_id'  => $path,
            'invalidate' => 'true',
            'timestamp'  => $timestamp,
            'api_key'    => $this->apiKey,
            'signature'  => $this->signParams([
                'invalidate' => 'true',
                'public_id'  => $path,
                'timestamp'  => (string) $timestamp,
            ]),
        ];

        try {
            $result = $this->executeWithRetry($deleteUrl, $postData);

            return ($result['result'] ?? '') === 'ok';
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function url(string $path): string
    {
        return "https://res.cloudinary.com/{$this->cloudName}/image/upload/{$path}";
    }

    public function exists(string $path): bool
    {
        // Cloudinary doesn't have a cheap existence check — assume true
        // for newly uploaded files. The URL will 404 if deleted remotely.
        return true;
    }

    public function getName(): string
    {
        return 'cloudinary';
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
            // prints so the real value never reaches a log line.
            // @mago-expect lint:no-literal-password
            'apiKey'    => '***redacted***', // audit-ok: redaction placeholder
            'folder'    => $this->folder,
            'name'      => $this->getName(),
        ];
    }

    /**
     * Upload with unsigned preset fallback to signed auth.
     *
     * @param non-falsy-string     $uploadUrl
     * @param array<string, mixed> $basePostData
     *
     * @return array<string, mixed>
     */
    private function uploadWithAuth(string $uploadUrl, array $basePostData, string $folder, int $timestamp): array
    {
        if ('' !== $this->uploadPreset) {
            $unsignedData = $basePostData;
            $unsignedData['upload_preset'] = $this->uploadPreset;

            try {
                return $this->executeWithRetry($uploadUrl, $unsignedData);
            } catch (\RuntimeException $e) {
                if ('' !== $this->apiKey && '' !== $this->apiSecret) {
                    $signedData = $basePostData;
                    $signedData['upload_preset'] = $this->uploadPreset;
                    $this->applySignedAuth($signedData, $folder, $timestamp);

                    return $this->executeWithRetry($uploadUrl, $signedData);
                }

                throw $e;
            }
        }

        if ('' === $this->apiKey || '' === $this->apiSecret) {
            throw new \RuntimeException('Cloudinary requires upload preset or API key/secret');
        }

        $signedData = $basePostData;
        $this->applySignedAuth($signedData, $folder, $timestamp);

        return $this->executeWithRetry($uploadUrl, $signedData);
    }

    /**
     * Execute cURL with exponential backoff for 429 errors.
     *
     * @param non-empty-string     $url
     * @param array<string, mixed> $postData
     *
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    private function executeWithRetry(string $url, array $postData): array
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;

            $result = $this->executeCurl($url, $postData);

            if (429 === $result['httpCode']) {
                $retryAfter = $this->getRetryAfter($result['response']);
                $delayMs = null !== $retryAfter && $retryAfter > 0
                    ? $retryAfter * 1000
                    : self::BASE_DELAY_MS * (2 ** ($attempt - 1));
                usleep($delayMs * 1000);
                $lastError = 'Cloudinary API rate limit exceeded';
                continue;
            }

            if (200 === $result['httpCode'] && is_string($result['response'])) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($result['response'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            $lastError = $this->buildHttpErrorMessage($result['httpCode'], $result['response']);

            break;
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * Execute a single cURL request.
     *
     * @param non-empty-string     $url
     * @param array<string, mixed> $postData
     *
     * @return array{httpCode: int, response: string|null}
     */
    private function executeCurl(string $url, array $postData): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        /** @var array<string, mixed> $postData */
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'httpCode' => (int) $httpCode,
            'response' => is_string($response) ? $response : null,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function signParams(array $params): string
    {
        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return hash('sha256', implode('&', $parts) . $this->apiSecret);
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function applySignedAuth(array &$postData, string $folder, int $timestamp): void
    {
        if ('' === $this->apiKey || '' === $this->apiSecret) {
            throw new \RuntimeException('Cloudinary API key/secret required for signed uploads');
        }

        $signParams = [
            'folder'    => $folder,
            'timestamp' => (string) $timestamp,
        ];

        $postData['api_key'] = $this->apiKey;
        $postData['signature'] = $this->signParams($signParams);
    }

    private function getRetryAfter(?string $response): ?int
    {
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $retryAfter = $data['error']['retry_after'] ?? null;
            if (is_numeric($retryAfter)) {
                return (int) $retryAfter;
            }
        }

        return null;
    }

    private function buildHttpErrorMessage(int $httpCode, ?string $response): string
    {
        if (null !== $response && '' !== $response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['error'])) {
                $error = $decoded['error'];
                if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                    return sprintf('Cloudinary API request failed (HTTP %d): %s', $httpCode, $error['message']);
                }
            }
        }

        return sprintf('Cloudinary API request failed (HTTP %d)', $httpCode);
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        return false !== $mime ? $mime : 'application/octet-stream';
    }

}
