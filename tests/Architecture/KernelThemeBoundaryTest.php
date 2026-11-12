<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\TestCase;

/**
 * KernelThemeBoundaryTest — the shipped theme names exactly two module symbols,
 * and this is the list.
 *
 * Measured 2026-11-12: `src/Modules/KernelTheme` is the one tracked, shipped
 * file under `src/` that imports a module a kernel-only export does not carry.
 * No static gate reads it:
 *
 *  - `phpstan.neon` leaves `src/Modules/<Name>` outside Core/Database/Tooling
 *    out so the gate's verdict does not depend on what this checkout installed,
 *    and analysing a file that implements a module contract would do exactly
 *    that;
 *  - `mago.toml`'s scope follows PHPStan, and its `[guard]` perimeter names the
 *    kernel namespaces, not this one;
 *  - `KernelCoupling` walks the kernel modules and the bootstrap files; adding
 *    this slice there would need an M/C/G answer for a dependency that is the
 *    theme contract itself.
 *
 * So the file had no guard — and its docblock claimed it imported nothing from a
 * feature module, which was false, with nothing measuring the sentence. This
 * test measures it the only way that works without the modules installed: it
 * reads `use` lines as tokens. A THIRD module name fails, and so does a recorded
 * name that disappeared, because either way the boundary moved without a
 * decision.
 *
 * Why the two names are the boundary rather than a bug:
 *
 *  - `Modules\Layout\ThemeModule` is the contract a theme implements. The module
 *    owns the theme surface (`FrontendLayout`, `ThemeKernel`, discovery), so the
 *    contract travels with it; the kernel theme is the fallback implementor.
 *    Closing this means moving the contract into the kernel — a source
 *    repository decision, not an edit here.
 *  - `ContentLocaleServices` is asked only behind `class_exists` in
 *    `localeHref()`; a tree without locales renders the short prefix from the
 *    locale code, which is what the theme did before the mapping moved.
 *
 * Read as tokens, not as text: the docblock above `localeHref()` names both
 * modules, and the CSS constant spells `Modules/KernelTheme/...`. A comment is a
 * `T_DOC_COMMENT` and a string is a literal; neither is an import, so neither is
 * counted.
 *
 * @internal
 *
 * @coversNothing
 */
final class KernelThemeBoundaryTest extends TestCase
{
    /**
     * The module symbols the kernel theme may name, recorded as decided.
     *
     * @var list<string>
     */
    private const array MODULE_SURFACE = [
        'Modules\\ContentLocale\\Interfaces\\HTTP\\ContentLocaleServices',
        'Modules\\Layout\\ThemeModule',
    ];

    /**
     * A new module name in the theme is a failing test, not a convenience.
     */
    public function testTheThemeNamesOnlyTheRecordedModules(): void
    {
        $found = [];

        foreach (self::themeFiles() as $path) {
            $code = file_get_contents($path);

            if (false === $code) {
                continue;
            }

            foreach (self::imports($code) as [$line, $symbol]) {
                if (str_starts_with($symbol, 'Modules\\')) {
                    $found[] = [$symbol, self::relative($path), $line];
                }
            }
        }

        $places = implode("\n", array_map(
            static fn (array $entry): string => sprintf('  %s  (%s:%d)', $entry[0], $entry[1], $entry[2]),
            $found,
        ));

        $symbols = array_map(static fn (array $entry): string => $entry[0], $found);
        $expected = self::MODULE_SURFACE;

        sort($symbols);
        sort($expected);

        self::assertSame(
            $expected,
            $symbols,
            "The kernel theme's module boundary changed:\n" . $places . "\n\n"
            . 'The theme implements a contract owned by `modules/Layout` and asks `ContentLocale` behind '
            . '`class_exists`. A NEW name means the kernel theme depends on another module — bring it '
            . 'under PHPStan/mago scope instead if it is genuinely the theme surface. A MISSING name '
            . 'means the boundary moved: update MODULE_SURFACE and say why in the docblock.',
        );
    }

    /**
     * The runtime guard the second name relies on.
     *
     * The import says "this file may read ContentLocale"; the promise is that a
     * tree without it still renders. `class_exists()` at the call site is that
     * promise, and it has to be visible here rather than assumed.
     */
    public function testTheLocaleServiceIsReachedOnlyBehindClassExists(): void
    {
        $code = (string) file_get_contents(
            AppPaths::instance()->join('src', 'Modules', 'KernelTheme', 'Theme', 'KernelTheme.php'),
        );

        self::assertStringContainsString(
            'class_exists(ContentLocaleServices::class)',
            $code,
            'ContentLocaleServices must be asked behind class_exists: a locale-less tree renders the '
            . 'short prefix rather than fataling on a missing class.',
        );
    }

    /**
     * The scanner must find the theme.
     *
     * Every rule above compares against a walk. A moved root or a changed
     * extension would leave the found list empty and the rule above comparing
     * two empty lists, so the walk proves it read the tree: one PHP file with
     * five imports (three `Shell`, two `Modules`).
     */
    public function testTheScannerFindsTheTheme(): void
    {
        $files = self::themeFiles();

        self::assertNotEmpty(
            $files,
            'the walk found no PHP files under src/Modules/KernelTheme — every rule above would cover nothing',
        );

        $imports = [];

        foreach ($files as $path) {
            $code = file_get_contents($path);

            if (false !== $code) {
                $imports[] = self::imports($code);
            }
        }

        self::assertGreaterThanOrEqual(
            5,
            \count(array_merge(...$imports)),
            'the scanner read fewer imports than KernelTheme.php carries — the tokenizer or the '
            . 'depth-zero rule stopped matching',
        );
    }

    /**
     * Top-level imports as `[line, symbol]` pairs.
     *
     * Depth zero is the rule: a `use` inside a class body is a trait use, and a
     * `use` after a closure's parameter list is a capture. The interpolation
     * openers count as openers for the same reason their closing brace is a
     * plain `}`.
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
     * Every PHP file under the kernel theme slice, sorted for a stable order.
     *
     * @return list<string>
     */
    private static function themeFiles(): array
    {
        $directory = AppPaths::instance()->join('src', 'Modules', 'KernelTheme');

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
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
