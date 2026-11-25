<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use View\InlineCss;
use View\InlineJs;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Themes\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Themes\ThemeKernel;
use Themes\ThemeResolver;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Themes\AuthPageTheme;

/**
 * AuthTemplate — the auth flow's forms, in the active theme when it has one.
 *
 * A theme that implements `AuthPageTheme` composes the whole surface (heading,
 * fields, errors, footnote) and this class wraps it in `FrontendLayout`, so the
 * visitor keeps the site's header, footer and stylesheet. Every other theme
 * keeps the standalone self-contained document below — no external assets,
 * CSP-safe — which is the behaviour the kernel-only tree has always had.
 *
 * The security-relevant parts stay here on BOTH paths: the CSRF field and the
 * validated `return_to` input are built by this class and handed to the theme
 * inside `$hiddenHtml`, so a theme cannot drop either.
 */
final class AuthTemplate
{
    /**
     * @param list<array{name: string, type?: string, label?: string, value?: mixed, required?: bool, autofocus?: bool}> $fields
     * @param list<string>                                                                                               $errors
     * @param string                                                                                                     $returnTo
     */
    public static function render(
        string $title,
        string $formAction,
        string $submitLabel,
        array $fields,
        array $errors,
        ?string $footnote = null,
        ?string $alert = null,
        ?string $returnTo = null,
    ): Response {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        // Path-only resolution: auth routes are never admin paths, so the
        // active preference (not the URL) decides which theme answers.
        $theme = ThemeResolver::resolvePath($formAction);
        $module = ThemeKernel::for($theme);

        if ($module instanceof AuthPageTheme) {
            return Response::html(FrontendLayout::render(
                $title,
                $module->authPage(
                    $title,
                    $formAction,
                    $submitLabel,
                    $fields,
                    self::returnToField($returnTo, $e) . CsrfMiddleware::field(),
                    $errors,
                    $footnote,
                    $alert,
                ),
                description: $title . ' — account access.',
                currentPath: $formAction,
                noindex: true,
                theme: $theme,
            ));
        }

        $body = self::pageShell(
            $e,
            $title,
            $formAction,
            $submitLabel,
            self::fieldsHtml($fields, $e),
            self::errorBlock($errors, $e),
            self::alertBlock($alert, $e),
            self::footnoteHtml($footnote),
            self::returnToField($returnTo, $e),
        );

        return Response::html($body);
    }

    /**
     * @param list<array{name: string, type?: string, label?: string, value?: mixed, required?: bool, autofocus?: bool}> $fields
     * @param \Closure(string): string                                                                                   $e
     */
    private static function fieldsHtml(array $fields, \Closure $e): string
    {
        $fieldHtml = '';
        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $name = $field['name'];
            $label = $field['label'] ?? ucfirst($name);
            $rawValue = $field['value'] ?? '';
            $value = is_string($rawValue) ? $rawValue : '';
            $required = ($field['required'] ?? true) ? ' required' : '';
            $autofocus = ($field['autofocus'] ?? false) ? ' autofocus' : '';

            $fieldHtml .= '<div class="auth-field"><label for="' . $e($name) . '">' . $e($label)
                . '</label><input type="' . $e($type) . '" id="' . $e($name) . '" name="' . $e($name)
                . '" value="' . $e((string) $value) . '"' . $required . $autofocus . '></div>';
        }

        return $fieldHtml;
    }

    /**
     * @param list<string>             $errors
     * @param \Closure(string): string $e
     */
    private static function errorBlock(array $errors, \Closure $e): string
    {
        if ([] === $errors) {
            return '';
        }

        $items = implode('', array_map(
            static fn (string $err): string => '<li>' . $e($err) . '</li>',
            $errors,
        ));

        return '<div class="auth-error"><ul>' . $items . '</ul></div>';
    }

    /**
     * @param \Closure(string): string $e
     */
    private static function alertBlock(?string $alert, \Closure $e): string
    {
        return null !== $alert ? '<div class="auth-alert">' . $e($alert) . '</div>' : '';
    }

    /**
     * Safe return_to from a request — the hidden field rides in the POST
     * body, the query parameter on GET. Only local paths (starting with a
     * single /, never // or a scheme) are honoured, so the redirect target
     * can never point off-site (open-redirect guard). Shared by the login
     * and register actions so the guard cannot drift between them.
     */
    public static function returnTo(Request $request): ?string
    {
        $value = $request->body('return_to') ?? $request->query('return_to');
        if (!is_string($value) || '' === $value || strlen($value) > 512) {
            return null;
        }

        if (1 !== preg_match('@^/[a-z0-9/._?&=#%+~-]*$@i', $value) || str_starts_with($value, '//')) {
            return null;
        }

        return $value;
    }

    private static function footnoteHtml(?string $footnote): string
    {
        return null !== $footnote ? '<p class="auth-footnote">' . $footnote . '</p>' : '';
    }

    /**
     * Hidden return_to field — carried across the POST so a successful
     * login/registration can redirect back to the page the user came from
     * (e.g. a member-only article). Only safe local paths ever reach this
     * point (the actions validate return_to before passing it).
     *
     * @param \Closure(string): string $e
     */
    private static function returnToField(?string $returnTo, \Closure $e): string
    {
        return null !== $returnTo ? '<input type="hidden" name="return_to" value="' . $e($returnTo) . '">' : '';
    }

    /**
     * @param \Closure(string): string $e
     */
    private static function pageShell(
        \Closure $e,
        string $title,
        string $formAction,
        string $submitLabel,
        string $fieldHtml,
        string $errorBlock,
        string $alertBlock,
        string $footnoteHtml,
        string $returnToField,
    ): string {
        $htmlLang = LocalesServices::locale()->htmlLang();

        return '<!DOCTYPE html><html lang="' . $htmlLang . '"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $e($title) . '</title>'
            . '<meta name="theme-color" content="#0f172a">'
            . '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . self::pageCss() . '</style>'
            . self::themeInitScript()
            . '</head><body>'
            . self::themeToggleButton()
            . '<div class="auth-box">'
            . '<h1>' . $e($title) . '</h1>'
            . $alertBlock . $errorBlock
            . '<form method="post" action="' . $e($formAction) . '">'
            . $fieldHtml
            . $returnToField
            . CsrfMiddleware::field()
            . '<button type="submit" class="auth-submit">' . $e($submitLabel) . '</button>'
            . '</form>' . $footnoteHtml . '</div>'
            . self::themeToggleScript()
            . '</body></html>';
    }

    /**
     * Inline page styles — dark-first design tokens with a manual
     * [data-theme="light"] override and an OS auto-light fallback.
     */
    private static function pageCss(): string
    {
        return InlineCss::file('Modules/Users/Interfaces/HTTP/assets/styles/auth.css');
    }

    /**
     * Sync theme init in <head> — FOUC prevention: restore the persisted
     * theme (or the OS preference) before first paint.
     */
    private static function themeInitScript(): string
    {
        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . InlineJs::file('Modules/Users/Interfaces/HTTP/assets/scripts/theme-init.js')
            . '</script>';
    }

    /**
     * Theme toggle button (fixed top-right) — flips data-theme on <html>.
     */
    private static function themeToggleButton(): string
    {
        return '<button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">'
            . '<span class="auth-theme-toggle__icon auth-theme-toggle__icon--sun" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>'
            . '</span>'
            . '<span class="auth-theme-toggle__icon auth-theme-toggle__icon--moon" aria-hidden="true">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
            . '</span>'
            . '</button>';
    }

    /**
     * Theme toggle click handler — persists the choice to localStorage.
     */
    private static function themeToggleScript(): string
    {
        return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
            . InlineJs::file('Modules/Users/Interfaces/HTTP/assets/scripts/theme-toggle.js')
            . '</script>';
    }
}
