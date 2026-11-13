<?php

declare(strict_types = 1);

namespace Modules\Validation\Validation;

/**
 * Dot-notation data access (`items.0.email`) for values and cross-field
 * rules. Arrays only — objects are not traversed, a non-array segment
 * resolves to null instead of fataling.
 */
final class DataPath
{
    /**
     * @param array<string, mixed> $data
     */
    public static function get(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function has(array $data, string $path): bool
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }
}
