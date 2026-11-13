<?php

declare(strict_types = 1);

namespace View;

/**
 * ViewRenderer — output-buffered include for PHP templates under src/.
 *
 * The zero-dependency template mechanism for large HTML bodies (admin/auth/
 * debug surfaces): templates are plain PHP files under module-owned
 * `templates/` directories, 95% markup, receiving ONLY the variables passed
 * to render() (EXTR_SKIP), with `$this` unavailable and strict_types
 * expected (enforced by the template hygiene guard).
 */
final class ViewRenderer
{
    /**
     * Render a template path relative to src/ with the given variables.
     * Output buffering isolates the include; anything echoed becomes the
     * return value, exceptions propagate after buffer cleanup.
     *
     * @param array<string, mixed> $vars
     */
    public static function render(string $srcRelative, array $vars = []): string
    {
        $path = Paths::src($srcRelative);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Template not found: %s', $srcRelative));
        }

        // Include inside a closure so render()'s own locals ($path, $vars,
        // $srcRelative) never leak into the template scope — EXTR_SKIP keeps
        // existing variables, so a template receiving e.g. $path would
        // otherwise silently see the template file path instead of its data.
        return (static function (string $templatePath, array $templateVars): string {
            // Safety pin for template files: every converted template starts
            // with a guard clause on this variable, so a template included by
            // anything other than this renderer fails fast instead of
            // rendering with undefined variables.
            $viewRendererGuard = true;

            extract($templateVars, EXTR_SKIP);

            ob_start();

            try {
                include $templatePath;
            } finally {
                $output = (string) ob_get_clean();
            }

            return $output;
        })($path, $vars);
    }
}
