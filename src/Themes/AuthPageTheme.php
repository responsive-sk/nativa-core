<?php

declare(strict_types = 1);

namespace Themes;

/**
 * AuthPageTheme — the optional auth surfaces a `ThemeModule` may compose.
 *
 * `ThemeModule` names every PUBLIC page surface, and its auth pages used to be
 * the exception: `/login`, `/register`, `/forgot-password` and
 * `/reset-password` rendered one shared standalone page from `Users`, so a
 * theme could style the site and still hand the visitor a login form in the
 * kernel's own grey card. A theme that wants those pages in its design
 * vocabulary implements this contract; one that does not keeps the shared
 * page, unchanged.
 *
 * Optional on purpose. Adding the method to `ThemeModule` would force every
 * installed theme to grow form markup it has no design for, so the capability
 * is a second interface the caller asks about (`$theme instanceof
 * AuthPageTheme`) instead of a method every theme must answer.
 *
 * ## What the caller keeps, and what the theme owns
 *
 * The theme owns the MARKUP: the section, the heading, the labels, the input
 * classes and the submit button, in its own prefix. `Users\AuthTemplate` keeps
 * the SECURITY: it builds `$hiddenHtml` — the CSRF field and the validated
 * `return_to` input — and hands it over ready to place inside the form, so a
 * theme cannot forget either. Errors arrive as plain strings and are escaped
 * by the theme; `$footnote` arrives as trusted HTML because it is a static
 * sentence from the action ("No account yet? <a …>"), never user input.
 *
 * The caller wraps the returned block in `FrontendLayout`, so the page keeps
 * the theme's header, footer and stylesheet; a theme that implements this
 * contract never writes a second `<html>` document.
 */
interface AuthPageTheme
{
    /**
     * The complete auth surface: heading, form, errors and footnote.
     *
     * @param list<array{name: string, type?: string, label?: string, value?: mixed, required?: bool, autofocus?: bool}> $fields
     * @param string                                                                                                     $hiddenHtml CSRF + return_to inputs, already built
     * @param list<string>                                                                                               $errors
     * @param string|null                                                                                                $footnote   trusted HTML (a static link line)
     * @param string|null                                                                                                $alert      plain text, escaped by the theme
     */
    public function authPage(
        string $title,
        string $formAction,
        string $submitLabel,
        array $fields,
        string $hiddenHtml,
        array $errors,
        ?string $footnote = null,
        ?string $alert = null,
    ): string;
}
