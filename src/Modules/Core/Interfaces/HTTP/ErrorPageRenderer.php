<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Kernel\Contracts\ErrorRendererInterface;
use Themes\FrontendLayout;
use Themes\ThemeKernel;
use Themes\ThemeResolver;

/**
 * ErrorPageRenderer — styled HTTP error pages (404/405/500).
 *
 * Renders the error through the public FrontendLayout shell (header,
 * navigation, footer, theme, CSP nonce) with the ACTIVE theme's own error
 * block (`ThemeModule::errorContent()`): a 404 in another theme's class
 * names is the same bug class as a page in another theme's stylesheet.
 * When the layout itself cannot render — typically a 500 caused by a
 * database failure, since the shell reads menu/settings from SQLite — the
 * renderer falls back to a dependency-free bare HTML page with the same
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
        return Response::html((new self())->render($status, $title, $detail, $path, $lang), $status);
    }

    public function render(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): string
    {
        $theme = self::resolveTheme($path);
        $content = ThemeKernel::for($theme)->errorContent($status, $title, $detail);

        try {
            return FrontendLayout::render(
                $status . ' ' . $title,
                $content,
                currentPath: $path ?? '/',
                lang: $lang,
                noindex: true,
                theme: $theme,
            );
        } catch (\Throwable) {
            return $this->bare($status, $title, $detail);
        }
    }

    /**
     * Theme resolution that cannot fail the error page: anything the
     * resolver trips on (a provider reading a broken database during a 500)
     * degrades to the kernel theme, which the tree always has.
     */
    private static function resolveTheme(?string $path): string
    {
        if (null === $path) {
            return ThemeResolver::KERNEL_THEME;
        }

        try {
            return ThemeResolver::resolvePath($path);
        } catch (\Throwable) {
            return ThemeResolver::KERNEL_THEME;
        }
    }

    /**
     * Dependency-free fallback — inline styles only, no layout shell, no
     * database access. Keeps the error page renderable when the shell
     * itself broke (e.g. a 500 from a SQLite failure).
     */
    private function bare(int $status, string $title, string $detail): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $status . ' ' . $safeTitle . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.5rem">' . $status . ' ' . $safeTitle . '</h1>'
            . '<p style="color:#555;white-space:pre-wrap">' . $safeDetail . '</p>'
            . '</body></html>';
    }
}
