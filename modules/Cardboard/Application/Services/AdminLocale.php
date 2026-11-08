<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

/**
 * AdminLocale — canonical mapping for admin locale query/body params.
 *
 * The admin UI carries locale as a URL query / form body value ('sk' | 'en'),
 * while the storage services accept canonical locale codes ('sk_SK' | 'en_US').
 * Actions historically mixed both, and bulk POST endpoints read the raw body
 * value ('en') and passed it to BlogCrudService — whose directoryFor() only
 * recognizes 'en_US', silently falling back to the sk directory. Bulk
 * publish/unpublish/delete then always mutated (or missed) the SK files
 * while the UI showed the EN table.
 *
 * This helper is the single mapping point: actions normalize once on entry,
 * keep the canonical code for services and the raw code for URLs/redirects.
 */
final class AdminLocale
{
    /**
     * Canonical storage locale code from a raw admin param.
     *
     * Anything that is not exactly 'en' (or already-canonical 'en_US')
     * resolves to sk_SK — the admin default locale.
     */
    public static function canonical(string $raw): string
    {
        return in_array($raw, ['en', 'en_US'], true) ? 'en_US' : 'sk_SK';
    }

    /**
     * Raw URL param ('sk' | 'en') from a raw admin param.
     */
    public static function raw(string $raw): string
    {
        return in_array($raw, ['en', 'en_US'], true) ? 'en' : 'sk';
    }
}
