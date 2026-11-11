<?php

declare(strict_types = 1);

namespace Modules\View;

/**
 * InlineJs — reads runtime JavaScript assets from src/ and returns their bytes.
 *
 * Static inline <script> blocks live as real .js files under
 * src/**\/assets/scripts/ and are inlined by the shells/layouts through this
 * loader; the nonce-carrying <script> tag stays in PHP (CSP contract). The
 * FrontendLayout theme-init script deliberately does NOT go through here —
 * it must run before first paint and stays inline by documented contract.
 */
final class InlineJs
{
    /** @var array<string, string> per-request cache, keyed by absolute path */
    private static array $cache = [];

    /**
     * Read a JS asset path relative to src/ (e.g.
     * 'Modules/Users/Interfaces/HTTP/assets/scripts/auth.js'). '' when the
     * file is missing (dev-tree tolerance; committed files are always present).
     */
    public static function file(string $srcRelative): string
    {
        $path = Paths::moduleFile($srcRelative);

        if (\array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }

        $js = '';
        if (is_file($path)) {
            $content = file_get_contents($path);
            if (is_string($content)) {
                $js = $content;
            }
        }

        return self::$cache[$path] = $js;
    }
}
