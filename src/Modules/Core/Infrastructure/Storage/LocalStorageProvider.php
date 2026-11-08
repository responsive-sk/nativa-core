<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Storage;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * LocalStorageProvider — local file upload helper (task 10.6).
 *
 * Stores uploaded files on the local filesystem under storage/uploads/ with a
 * date-based directory structure and a random filename. Validates uploads
 * server-side (extension + MIME whitelist, size limit) and refuses path
 * traversal. Local-only: future modules (e.g. media) can swap in a remote
 * provider behind the same shape.
 *
 * Usage:
 *   $storage = new LocalStorageProvider();
 *   $result = $storage->store($_FILES['file']);   // ['path' => ..., 'url' => ...]
 *   $storage->delete('2026/08/04/abc…xyz.png');
 */
final readonly class LocalStorageProvider
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    private const array ALLOWED = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/gif'       => ['gif'],
        'image/webp'      => ['webp'],
        'application/pdf' => ['pdf'],
        'text/plain'      => ['txt'],
        'text/markdown'   => ['md'],
    ];

    private string $basePath;

    private string $baseUrl;

    public function __construct()
    {
        $this->basePath = AppPaths::instance()->storage('uploads');
        $this->baseUrl = '/storage/uploads';
        AppPaths::instance()->ensureDir($this->basePath);
    }

    /**
     * Store an uploaded file.
     *
     * @param array<string, mixed> $file $_FILES entry (name, tmp_name, error, size)
     *
     * @throws \RuntimeException on validation or storage failure
     *
     * @return array{path: string, url: string, size: int, mime_type: string}
     */
    public function store(array $file): array
    {
        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $originalName = is_string($file['name'] ?? null) ? $file['name'] : '';
        $error = $file['error'] ?? null;

        if (!is_numeric($error) || UPLOAD_ERR_OK !== (int) $error || '' === $tmpName) {
            throw new \RuntimeException(self::uploadErrorMessage($error, '' === $tmpName));
        }
        if (!is_uploaded_file($tmpName) && !is_file($tmpName)) {
            throw new \RuntimeException('Invalid file upload path');
        }

        // Normalize the reported size ONCE at the boundary — the same int is
        // reused for validation and the result (no re-guessing later).
        $size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;

        $this->validateFile($tmpName, $originalName, $size);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $datePath = date('Y/m/d');

        $targetDir = AppPaths::instance()->storage('uploads/' . $datePath);
        AppPaths::instance()->ensureDir($targetDir);
        $targetPath = $targetDir . '/' . $filename;

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $targetPath)
            : copy($tmpName, $targetPath);

        if (!$moved) {
            throw new \RuntimeException('Failed to move uploaded file');
        }

        // If no size was reported, fall back to the real on-disk size.
        $size = 0 !== $size ? $size : (int) filesize($targetPath);
        $relativePath = $datePath . '/' . $filename;

        return [
            'path'      => $relativePath,
            'url'       => $this->url($relativePath),
            'size'      => $size,
            'mime_type' => $this->detectMimeType($targetPath),
        ];
    }

    /**
     * Whether a stored file is servable — the server-side detected MIME must
     * match the extension whitelist (the same rule store() enforces). Used by
     * the read-only /storage/uploads serving route.
     */
    public function isAllowed(string $relativePath): bool
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            return false;
        }

        $fullPath = $this->basePath . '/' . $relativePath;
        if (!is_file($fullPath)) {
            return false;
        }

        $mime = $this->detectMimeType($fullPath);
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED[$mime] ?? [], true);
    }

    /**
     * Delete a stored file (relative path under uploads/).
     */
    public function delete(string $path): bool
    {
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        $fullPath = $this->basePath . '/' . $path;

        return is_file($fullPath) && unlink($fullPath);
    }

    /**
     * Whether a stored file exists.
     */
    public function exists(string $path): bool
    {
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        return is_file($this->basePath . '/' . $path);
    }

    /**
     * Public URL for a stored file.
     */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Human-readable message for a PHP UPLOAD_ERR_* code — the raw codes
     * are invisible to users, and the generic "Invalid file upload" hid
     * the one piece of information that matters (which limit rejected the
     * file, or that the server itself is misconfigured).
     *
     * @param int|mixed $error
     */
    private static function uploadErrorMessage(mixed $error, bool $missingTmpName): string
    {
        if (!is_numeric($error) || UPLOAD_ERR_OK === (int) $error) {
            return $missingTmpName
                ? 'Invalid file upload: no temporary file was received by the server.'
                : 'Invalid file upload.';
        }

        return match ((int) $error) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit (upload_max_filesize = '
                . (ini_get('upload_max_filesize') ?: 'unknown') . ').',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE form limit.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially transferred — please retry.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: the PHP temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server misconfiguration: PHP failed to write the upload to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'Invalid file upload.',
        };
    }

    /**
     * Detect MIME type server-side (never trusts the client).
     */
    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        return false !== $mime ? $mime : 'application/octet-stream';
    }

    /**
     * Validate an upload against the whitelist.
     *
     * @param int $size normalized reported size (0 when missing/non-numeric)
     */
    private function validateFile(string $tmpName, string $originalName, int $size): void
    {
        // Defense-in-depth: validate the reported size AND the real on-disk
        // size, so a crafted/missing size key cannot bypass the limit.
        $realSize = @filesize($tmpName);
        if ($size > self::MAX_BYTES
            || (false !== $realSize && $realSize > self::MAX_BYTES)
        ) {
            throw new \RuntimeException('File size exceeds maximum allowed size (10MB)');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = $this->detectMimeType($tmpName);

        $expectedExtensions = self::ALLOWED[$mimeType] ?? null;
        if (null === $expectedExtensions) {
            throw new \RuntimeException('File type not allowed: ' . $mimeType);
        }

        if (!in_array($extension, $expectedExtensions, true)) {
            throw new \RuntimeException(
                'File extension mismatch: expected ' . implode(' or ', $expectedExtensions)
                . " for MIME type {$mimeType}, got {$extension}",
            );
        }
    }
}
