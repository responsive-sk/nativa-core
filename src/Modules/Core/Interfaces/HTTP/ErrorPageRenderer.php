<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Kernel\Contracts\ErrorRendererInterface;
use Modules\Core\Kernel\PageShell\PageShellRegistry;
use Modules\Core\Kernel\PageShell\PlainPageShell;

/**
 * ErrorPageRenderer — styled HTTP error pages (404/405/500).
 *
 * Renders the error through the installed page shell (`PageShellInterface`),
 * which wears the ACTIVE theme's own error block: a 404 in another theme's
 * class names is the same bug class as a page in another theme's stylesheet.
 * This file no longer imports `FrontendLayout`, `ThemeKernel` or
 * `ThemeResolver` — the layout lives in a module, and the kernel keeps only
 * the port.
 *
 * When the shell itself cannot render — typically a 500 caused by a database
 * failure, since the shell reads menu/settings from SQL — the renderer falls
 * back to `PlainPageShell::bare()`, a dependency-free HTML page with the same
 * status code (the error page must never fail to render).
 */
final class ErrorPageRenderer implements ErrorRendererInterface
{
    public function renderError(int $status, string $title, string $detail, ?string $path = null): Response
    {
        return self::response($status, $title, $detail, $path);
    }

    /**
     * Styled error Response — the single 404/405/500 entry point used by the
     * Kernel and by actions (Kernel::renderError and the actions' notFound()
     * helpers all delegate here, so every error page in the app is identical).
     *
     * @param string|null $path request path for theme resolution (active theme
     *                          wears the error); null renders kernel-default without resolving.
     * @param string|null $lang explicit content language for the document.
     */
    public static function response(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): Response
    {
        return Response::html(self::renderBody($status, $title, $detail, $path, $lang), $status);
    }

    public function render(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): string
    {
        return self::renderBody($status, $title, $detail, $path, $lang);
    }

    private static function renderBody(int $status, string $title, string $detail, ?string $path, ?string $lang): string
    {
        try {
            return PageShellRegistry::current()->error($status, $title, $detail, $path, $lang);
        } catch (\Throwable) {
            return PlainPageShell::bare($status, $title, $detail);
        }
    }
}
