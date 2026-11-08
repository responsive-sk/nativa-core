<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services\View;

/**
 * InlineCss — reads runtime CSS assets from src/ and returns their bytes.
 *
 * The single loader behind every inline <style> block: critical CSS lives as
 * real .css files under src/**\/assets/critical/ (shipped to production by
 * build-dist.sh and the Docker image, which both copy src/), and this class
 * reads them verbatim with a per-request static cache.
 *
 * Guard: the sources must not contain @charset/@import/@layer (the bytes are
 * inlined into every document response; those at-rules are either meaningless
 * inline or dropped by old browsers). Stripping is a safety net, not a
 * feature — the file-based inline parity test asserts the sources ship none.
 */
final class InlineCss
{
    /** @var array<string, string> per-request cache, keyed by absolute path */
    private static array $cache = [];

    /**
     * Read a CSS asset path relative to src/ (e.g.
     * 'Themes/erik/assets/critical/hero.css'). '' when the file is
     * missing (dev-tree tolerance; committed files are always present).
     */
    public static function file(string $srcRelative): string
    {
        $path = Paths::moduleFile($srcRelative);

        if (\array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }

        $css = '';
        if (is_file($path)) {
            $content = file_get_contents($path);
            if (is_string($content)) {
                $css = self::stripForbiddenAtRules($content);
            }
        }

        return self::$cache[$path] = $css;
    }

    /**
     * Concatenate an ordered list of src/-relative CSS files. The order
     * contract lives at the call site (one list per theme), mirroring the
     * pre-extraction concatenation order byte-for-byte.
     *
     * @param list<string> $files
     */
    public static function files(array $files): string
    {
        $css = '';
        foreach ($files as $file) {
            $css .= self::file($file);
        }

        return $css;
    }

    /**
     * Strip at-rules that must never reach the inline <style> block.
     * Single-line tolerant: removes the directive up to the terminating
     * semicolon (@charset, @import) or the balanced block (@layer).
     *
     * Comments go first: the patterns above cannot tell an at-rule from its
     * NAME inside a comment, so `No @charset/@import/@layer.` in a header
     * comment ate the comment tail AND the `:root{...}` opener behind it —
     * every var() went undefined and the page rendered white (measured on
     * the Mono theme, 2026-10-11). A safety net that corrupts what it guards
     * is not one.
     */
    private static function stripForbiddenAtRules(string $css): string
    {
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
        $css = preg_replace('/@charset[^;]*;/', '', $css) ?? $css;
        $css = preg_replace('/@import[^;]*;/', '', $css) ?? $css;

        // @layer: block form (balanced braces) or statement form (@layer a, b;).
        while (true) {
            $pos = strpos($css, '@layer');
            if (false === $pos) {
                break;
            }
            $open = strpos($css, '{', $pos);
            $semi = strpos($css, ';', $pos);
            if (false !== $semi && (false === $open || $semi < $open)) {
                // Statement form — remove up to and including the semicolon.
                $css = substr($css, 0, $pos) . substr($css, $semi + 1);

                continue;
            }
            if (false === $open) {
                $css = (string) preg_replace('/@layer[^;]*;/', '', $css, 1);

                continue;
            }
            $depth = 0;
            $len = strlen($css);
            $close = $open;
            for ($i = $open; $i < $len; $i++) {
                if ('{' === $css[$i]) {
                    $depth++;
                } elseif ('}' === $css[$i]) {
                    $depth--;
                    if (0 === $depth) {
                        $close = $i;

                        break;
                    }
                }
            }
            $css = substr($css, 0, $pos) . substr($css, $close + 1);
        }

        return $css;
    }
}
