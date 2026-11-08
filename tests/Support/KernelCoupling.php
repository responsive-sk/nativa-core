<?php

declare(strict_types = 1);

namespace Tests\Support;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * KernelCoupling — every `use Modules\<X>\…` the shared kernel carries.
 *
 * The kernel is `Modules\Core` + `Modules\Database`, and the point of this tree
 * is that it boots with no feature module installed. Measuring that begins with
 * the edges, so this scanner is the single source for `KernelCouplingTest` —
 * which fails on an unclassified edge.
 *
 * Ported from `../nativa-minimal/tests/Support/KernelCoupling.php` (2026-10-15)
 * with one addition and no behavioural change to what it counts:
 *
 * - `allModulesImports()` is new. `edges()` filters the shared kernel out, so a
 *   kernel with zero feature-module coupling reports an empty `edges()` —
 *   correct, but indistinguishable from a walker that stopped matching (a moved
 *   directory, a changed extension, a typo). The test needs a signal that is
 *   non-empty whenever the walk works, and the shared kernel's own ~141
 *   self-imports are exactly that.
 *
 * Read as tokens, not as text. A `use` inside a class body is a trait use and a
 * `use` after a closure's parameter list is a closure capture; neither is a
 * module dependency, and neither can be told apart from an import by a regex
 * over lines. Depth zero is the rule, and the two string-interpolation openers
 * (`T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES`) are counted as openers for the
 * same reason their closing brace is a plain `}`: a counter that ignores them
 * drifts negative and starts reading a trait use in a later class body as a
 * top-level import.
 *
 * Grouped imports are one edge, not one per member: the thing being measured is
 * "this file depends on that module", and `use Modules\Blog\{A, B}` says it once.
 *
 * @internal
 *
 * @coversNothing
 */
final class KernelCoupling
{
    /**
     * The shared kernel — the modules that travel whole, and therefore the
     * directories this scanner walks for imports that reach OUT of it.
     *
     * `Tooling` belongs here in both of its roles: it is committed kernel code
     * (bin/cms ships it and the slice carries it), so `Modules\Core` →
     * `Modules\Tooling` is the kernel talking to itself rather than a
     * feature-module edge; and without it in this list `kernelFiles()` would
     * never walk `src/Modules/Tooling` — leaving the one kernel directory added
     * after this scanner was written unguarded against a `Modules\Blog` import
     * nobody had to think twice about.
     *
     * @var list<string>
     */
    public const array KERNEL_MODULES = ['Core', 'Database', 'Tooling'];

    /**
     * Every `Modules\…` import under the kernel, kernel modules included,
     * keyed by `path::Symbol`.
     *
     * @return array<string, array{file: string, line: int, module: string}>
     */
    public static function allModulesImports(): array
    {
        $edges = [];

        foreach (self::kernelFiles() as $path) {
            $content = file_get_contents($path);
            if (false === $content) {
                continue;
            }

            $file = self::relative($path);

            foreach (self::imports($content) as [$line, $symbol]) {
                $module = self::moduleOf($symbol);

                if (null === $module) {
                    continue;
                }

                $edges[$file . '::' . $symbol] = [
                    'file' => $file,
                    'line' => $line,
                    'module' => $module,
                ];
            }
        }

        ksort($edges);

        return $edges;
    }

    /**
     * Every NON-kernel import in the kernel — the edges this tree must not
     * carry — keyed by `path::Symbol`.
     *
     * The key is the file plus the imported symbol rather than the file plus a
     * line number, deliberately: a line number moves whenever anything above it
     * is edited, so a `path:line` ledger fails on every unrelated commit — and a
     * guard that cries wolf is a guard people update without reading. The symbol
     * is what the dependency actually is.
     *
     * The line number still travels, in the value, because a failure message
     * that cannot name a place to look is a failure message somebody mutes.
     *
     * @return array<string, array{file: string, line: int, module: string}>
     */
    public static function edges(): array
    {
        return array_filter(
            self::allModulesImports(),
            static fn (array $edge): bool => !\in_array($edge['module'], self::KERNEL_MODULES, true),
        );
    }

    /**
     * The module a `Modules\<X>\…` symbol belongs to, or null when the symbol
     * is not a Modules symbol at all.
     */
    public static function moduleOf(string $symbol): ?string
    {
        if (!str_starts_with($symbol, 'Modules\\')) {
            return null;
        }

        return explode('\\', $symbol)[1] ?? null;
    }

    /**
     * Every PHP file under the two kernel modules, sorted for a stable key order.
     *
     * @return list<string>
     */
    private static function kernelFiles(): array
    {
        $paths = AppPaths::instance();

        $files = [];
        foreach (self::KERNEL_MODULES as $module) {
            $directory = $paths->join('src', 'Modules', $module);
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        // `sort()` re-indexes, so `$files` is a list here; `array_values()` on
        // top of it would be a no-op phpstan is right to call out.
        return $files;
    }

    /**
     * Top-level imports as `[line, symbol]` pairs.
     *
     * @return list<array{int, string}>
     */
    private static function imports(string $code): array
    {
        $tokens = token_get_all($code);
        $count = \count($tokens);

        $imports = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            // An element is a token ARRAY or a bare character — braces arrive as
            // one-character strings, which is why this is not called `$token`:
            // a security linter reads that name as a credential.
            $element = $tokens[$i];

            if (\is_array($element)) {
                if (T_CURLY_OPEN === $element[0] || T_DOLLAR_OPEN_CURLY_BRACES === $element[0]) {
                    $depth++;
                    continue;
                }

                if (T_USE === $element[0] && 0 === $depth) {
                    $symbol = self::symbolAfter($tokens, $i);
                    if (null !== $symbol) {
                        $imports[] = [$element[2], $symbol];
                    }
                }

                continue;
            }

            if ('{' === $element) {
                $depth++;
            } elseif ('}' === $element) {
                $depth--;
            }
        }

        return $imports;
    }

    /**
     * The qualified name of the import starting at a T_USE token.
     *
     * A grouped import stops at the `{` after its qualifier; the trailing
     * separator that precedes the brace is trimmed, so
     * `use Modules\Blog\{A, B};` reads as `Modules\Blog`.
     *
     * @param list<string|array{int, string, int}> $tokens
     */
    private static function symbolAfter(array $tokens, int $start): ?string
    {
        $symbol = '';
        $count = \count($tokens);

        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                break;
            }

            if (T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                continue;
            }

            if (T_STRING === $token[0] || T_NAME_QUALIFIED === $token[0] || T_NS_SEPARATOR === $token[0]) {
                $symbol .= $token[1];
                continue;
            }

            break;
        }

        $symbol = rtrim($symbol, '\\');

        return '' === $symbol ? null : $symbol;
    }

    /**
     * A path relative to the repository root, with forward slashes.
     */
    private static function relative(string $path): string
    {
        $base = AppPaths::instance()->base();
        $relative = str_starts_with($path, $base) ? substr($path, \strlen($base) + 1) : $path;

        return str_replace('\\', '/', $relative);
    }
}
