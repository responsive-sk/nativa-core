<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MediaUploadPayload — accepts file uploads both as multipart ($_FILES)
 * and as a base64 form field ("file_b64").
 *
 * The base64 path exists for shared hosting where PHP's multipart staging
 * is broken (UPLOAD_ERR_NO_TMP_DIR — upload_tmp_dir is PHP_INI_SYSTEM and
 * cannot be fixed from .htaccess/.user.ini) or tightly limited
 * (upload_max_filesize). A urlencoded POST body carrying the file in a
 * base64 field bypasses $_FILES entirely — the bytes live in $_POST —
 * sidestepping the broken staging and the per-file INI cap; the remaining
 * limit is post_max_size.
 *
 * The synthetic $_FILES entry carries error=UPLOAD_ERR_OK and a real tmp
 * file, so every downstream check (whitelist, size, MIME detection) runs
 * unchanged. Plain multipart uploads keep working — this only adds a path.
 */
final class MediaUploadPayload
{
    /** Form field carrying the base64-encoded file bytes. */
    public const string FIELD = 'file_b64';

    /** Form field carrying the original filename (base64 path). */
    public const string NAME_FIELD = 'file_b64_name';

    /**
     * The effective $_FILES for media uploads: the real multipart entry
     * when present, otherwise a synthetic one built from file_b64.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function files(): array
    {
        return self::filesFor('file');
    }

    /**
     * The effective $_FILES entry for an arbitrary upload field ("file",
     * "avatar", …): the real multipart entry when present, otherwise a
     * synthetic one built from the "{field}_b64" POST field.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filesFor(string $field): array
    {
        $entry = $_FILES[$field] ?? null;

        if (is_array($entry)) {
            // $_FILES entries are keyed by field attribute names.
            /** @var array<string, mixed> $entry */
            return [$field => $entry];
        }

        $data = self::decodedField($field);

        if (null === $data) {
            return [];
        }

        $tmp = self::tempFile($data['bytes']);

        if (null === $tmp) {
            return [];
        }

        DisposalRegistry::register('media-upload-' . bin2hex(random_bytes(4)), static function (\Throwable|null $error) use ($tmp): void {
            @unlink($tmp);
        });

        return [$field => [
            'name'     => $data['name'],
            'type'     => self::detectMime($tmp),
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($data['bytes']),
        ]];
    }

    /**
     * Server-side MIME detection for the synthetic entry — a browser would
     * supply this in the multipart part header, and downstream checks rely
     * on it (MediaService's Cloudinary image-only pre-check reads the
     * client-reported type; an empty string would reject every upload).
     */
    private static function detectMime(string $path): string
    {
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            return is_string($mime) ? $mime : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Create a unique temp file holding the decoded bytes.
     *
     * sys_get_temp_dir() is deliberately NOT used: shared hostings often
     * forbid it via open_basedir (the staging dir may sit outside the
     * allowlist, and tempnam() then fatal-errors the request). The app's
     * own storage/cache tree is always inside the allowlist.
     */
    private static function tempFile(string $bytes): ?string
    {
        $dir = AppPaths::instance()->storage('cache' . DIRECTORY_SEPARATOR . 'media-uploads');

        try {
            AppPaths::instance()->ensureDir($dir);
        } catch (\RuntimeException) {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.tmp';

        // @-suppressed: the request handler escalates warnings to
        // ErrorException (500) — a failed write must surface as the 413/400
        // "dropped payload" answer instead of a fatal.
        return false !== @file_put_contents($path, $bytes) ? $path : null;
    }

    /**
     * Decoded "{field}_b64" field from the POST body (urlencoded fallback —
     * multipart requests are handled by the $_FILES branch above).
     *
     * @return array{bytes: string, name: string}|null
     */
    private static function decodedField(string $field): ?array
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            return null;
        }

        $encoded = $_POST[$field . '_b64'] ?? null;

        if (!is_string($encoded) || '' === trim($encoded)) {
            return null;
        }

        $bytes = base64_decode($encoded, true);

        if (false === $bytes || '' === $bytes) {
            return null;
        }

        $name = $_POST[$field . '_b64_name'] ?? null;

        if (!is_string($name) || '' === trim($name)) {
            $name = 'upload.bin';
        }

        return ['bytes' => $bytes, 'name' => $name];
    }
}
