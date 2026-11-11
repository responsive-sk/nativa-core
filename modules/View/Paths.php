<?php

declare(strict_types = 1);

namespace Modules\View;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * Paths — src/-relative resolution for the view helpers.
 *
 * One place where the view helpers resolve asset/template files, so the
 * test suite can redirect the base without touching AppPaths (whose base is
 * the immutable APP_BASE_PATH bootstrap constant).
 */
final class Paths
{
    /** @internal test seam — base directory override (null = real AppPaths) */
    private static ?string $override = null;

    /** @internal test seam — redirect resolution to a sandbox base. */
    public static function overrideBase(?string $base): void
    {
        self::$override = $base;
    }

    /**
     * Absolute path of a src/-relative file (e.g. 'Themes/erik/assets/
     * critical/hero.css').
     */
    public static function src(string $srcRelative): string
    {
        $base = self::$override ?? AppPaths::instance()->base();

        return $base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $srcRelative);
    }

    /**
     * Absolute path of a module-declared asset (`Modules/<Name>/<tail>`),
     * wherever the installed tree files that module — the layout may group it
     * (`src/Modules/<Group>/<Name>/`). Delegates to `AppPaths::moduleFile()`
     * so the view helpers and the app share one resolver; a sandbox override
     * still answers first.
     */
    public static function moduleFile(string $srcRelative): string
    {
        if (null === self::$override) {
            return AppPaths::instance()->moduleFile($srcRelative);
        }

        $direct = self::src($srcRelative);

        if (is_file($direct) || !str_starts_with($srcRelative, 'Modules/')) {
            return $direct;
        }

        $parts = explode('/', substr($srcRelative, strlen('Modules/')), 2);
        $tail = $parts[1] ?? '';

        if ('' === $tail) {
            return $direct;
        }

        foreach (glob(self::src('Modules/*/' . $parts[0] . '/' . $tail)) ?: [] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $direct;
    }
}
