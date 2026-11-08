<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP;

/**
 * MediaUploadLimits — resolves the effective server-side upload limit.
 *
 * On shared hosting the PHP INI values (upload_max_filesize, post_max_size)
 * are typically 2–8 MB and are NOT visible to the app — a POST body that
 * exceeds them is dropped by PHP before the request ever reaches the route,
 * so the application-level 10 MB check never runs and the user only sees a
 * generic failure. This helper exposes the effective limit to the upload
 * form so the browser can reject oversized files up front with a readable
 * message instead of a red progress bar.
 */
final class MediaUploadLimits
{
    /** Application-level cap enforced by LocalStorageProvider (10 MB). */
    private const int APP_LIMIT_BYTES = 10 * 1024 * 1024;

    /**
     * Raw server limit in bytes: min(upload_max_filesize, post_max_size),
     * 0 when neither INI value can be parsed.
     */
    public static function maxBytes(): int
    {
        $limits = array_filter(
            array_map(self::iniBytes(...), ['upload_max_filesize', 'post_max_size']),
            static fn (int $bytes): bool => $bytes > 0,
        );

        if ([] === $limits) {
            return 0;
        }

        return min($limits);
    }

    /**
     * Limit the client should enforce: the stricter of the server INI
     * limit and the application's own 10 MB cap. Falls back to the app
     * cap when the server limit cannot be read.
     */
    public static function effectiveBytes(): int
    {
        $server = self::maxBytes();

        return 0 === $server ? self::APP_LIMIT_BYTES : min($server, self::APP_LIMIT_BYTES);
    }

    /**
     * Client-side guard config for the upload surfaces (JSON-ready).
     *
     * The JS clients send the file as a base64 POST field (see
     * MediaUploadPayload), which bypasses PHP's multipart staging — so
     * upload_max_filesize does not apply and the binding limit is
     * post_max_size adjusted for the ≈4/3 base64 growth, capped by the
     * app's 10 MB. multipartMaxBytes stays for the no-JS fallback, which
     * still goes through native $_FILES staging.
     *
     * @return array{maxBytes: int, maxMegaBytes: float, multipartMaxBytes: int, multipartMaxMegaBytes: float}
     */
    public static function formConfig(): array
    {
        $post = self::iniBytes((string) ini_get('post_max_size'));

        $base64Max = $post > 0 ? (int) ($post * 3 / 4) : self::APP_LIMIT_BYTES;
        $base64Max = min($base64Max, self::APP_LIMIT_BYTES);

        $multipartMax = min(self::effectiveBytes(), $post > 0 ? $post : self::APP_LIMIT_BYTES);

        return [
            'maxBytes'              => $base64Max,
            'maxMegaBytes'          => round($base64Max / (1024 * 1024), 1),
            'multipartMaxBytes'     => $multipartMax,
            'multipartMaxMegaBytes' => round($multipartMax / (1024 * 1024), 1),
        ];
    }

    /**
     * Human-readable effective limit, e.g. "10 MB" or "2 MB".
     */
    public static function label(): string
    {
        return self::formatBytes(self::effectiveBytes());
    }

    /**
     * Parse a PHP INI shorthand byte value ("2M", "512K", "1G", "8m").
     * Returns 0 for empty/unparseable values and for -1 ("unlimited").
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ('' === $value) {
            return 0;
        }

        $number = is_numeric($value) ? (float) $value : (float) preg_replace('/[^0-9.]/', '', $value);

        if ($number <= 0) {
            return 0;
        }

        $unit = strtolower(substr($value, -1));

        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) round($number * $multiplier);
    }

    /**
     * Raw INI shorthand for a key ("2M"), as configured on the server.
     */
    public static function iniLabel(string $key): string
    {
        return ini_get($key) ?: 'unknown';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1) . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / 1024 ** 2, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
