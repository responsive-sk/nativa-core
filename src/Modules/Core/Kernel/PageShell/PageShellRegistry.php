<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\PageShell;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Kernel\Contracts\PageShellInterface;

/**
 * PageShellRegistry — the installed page shell, or the kernel's plain one.
 *
 * Discovery is a directory scan, exactly like `ShellContextProviderRegistry`:
 * a module joins the kernel by existing, and a module that is deleted takes
 * its shell with it. The kernel file therefore names no module — which is
 * what lets the shell live outside the kernel at all.
 *
 * The scan looks for `*PageShell.php` under each module's `Interfaces/HTTP`,
 * flat or one group deep (`src/Modules/<Group>/<Name>/...`). One shell is
 * expected; the first discovered wins, and a class that does not implement
 * the port or needs constructor arguments is skipped rather than fatal.
 */
final class PageShellRegistry
{
    private static ?PageShellInterface $shell = null;

    public static function current(): PageShellInterface
    {
        return self::$shell ??= self::discover() ?? new PlainPageShell();
    }

    /** Reset the cached discovery (test isolation). */
    public static function reset(): void
    {
        self::$shell = null;
    }

    private static function discover(): ?PageShellInterface
    {
        foreach (self::files() as $file) {
            $content = file_get_contents($file);

            if (!is_string($content) || !str_contains($content, 'AsPageShell')) {
                continue;
            }

            $fqcn = self::resolveFqcn($content);

            if (null === $fqcn) {
                continue;
            }

            if (!class_exists($fqcn)) {
                require_once $file;
            }

            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);

            if (!$reflection->implementsInterface(PageShellInterface::class)) {
                continue;
            }

            if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            /** @var PageShellInterface $shell */
            $shell = $reflection->newInstance();

            return $shell;
        }

        return null;
    }

    /**
     * Candidate files: flat modules and one catalogue group deep.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $base = AppPaths::instance()->src('Modules');

        $files = array_merge(
            glob($base . '/*/Interfaces/HTTP/*PageShell.php') ?: [],
            glob($base . '/*/*/Interfaces/HTTP/*PageShell.php') ?: [],
        );

        return $files;
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else — the same text-first rule the other
     * discovery registries use.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }
}
