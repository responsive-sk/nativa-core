<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\PageShell;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Kernel\Contracts\PageShellInterface;
use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * PlainPageShell — the kernel's own answer when no layout module is installed.
 *
 * Deliberately dependency-free: inline styles only, no theme resolution, no
 * navigation, no database. The kernel-only tree still answers `/` with 200 and
 * every error with its status code, which is the whole kernel-only promise.
 *
 * `bare()` is also the last-resort body `ErrorPageRenderer` uses when the
 * installed shell itself throws — typically a 500 caused by a database
 * failure, where rendering the styled page would fail the same way.
 */
final class PlainPageShell implements PageShellInterface
{
    public function home(
        Request $request,
        string $siteName,
        string $locale,
        array $copy,
        ?RouterInterface $router = null,
    ): string {
        // No router, no advertised link: this shell renders no navigation at
        // all, so the parameter is part of the port's signature and not its
        // markup.
        $title = $copy['title'] ?? $siteName;

        return '<!DOCTYPE html><html lang="' . self::e($locale) . '"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.5rem">' . self::e($siteName) . '</h1>'
            . '</body></html>';
    }

    public function error(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): string
    {
        return self::bare($status, $title, $detail);
    }

    /**
     * Dependency-free fallback — inline styles only, no layout shell, no
     * database access. Keeps the error page renderable when the shell
     * itself broke (e.g. a 500 from a database failure).
     */
    public static function bare(int $status, string $title, string $detail): string
    {
        $safeTitle = self::e($title);
        $safeDetail = self::e($detail);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $status . ' ' . $safeTitle . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.5rem">' . $status . ' ' . $safeTitle . '</h1>'
            . '<p style="color:#555;white-space:pre-wrap">' . $safeDetail . '</p>'
            . '</body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
