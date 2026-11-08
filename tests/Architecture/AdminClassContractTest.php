<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AdminClassContractTest — every admin class the markup writes has a rule.
 *
 * Measured 2026-11-07, while the module page's `m-muted` text rendered at full
 * ink in ninety places across the panel: the class was written everywhere and
 * defined nowhere, and the design token it should have read (`--m-muted`) had
 * existed since the panel was built. No gate could see it. `ThemeClassOwnership`
 * asks who owns a class, `FormBlockTest` asks whether a form's classes exist in
 * its stylesheet; the ADMIN sheet had neither question.
 *
 * ## What counts as defined
 *
 * Three ways, because all three are honest:
 *
 *  1. a rule for the class itself;
 *  2. a rule for one of its BEM children or modifiers — `m-chart` is a hook and
 *     the styling lives in `m-chart--donut` and `m-chart__svg`, so charging it
 *     would be a gate with an allow-list longer than its subject;
 *  3. a rule for a prefix the markup concatenates (`m-badge m-badge--` +
 *     `$tone`), which is why a trailing `--` token is matched by family and not
 *     by name.
 *
 * Stylesheets come from `Frontend/` (every package: the panel loads the
 * moonshine sheet, a theme sheet at once, and a future class may live in
 * either) plus the `<style>` block a layout carries inline — the compact panel
 * defines its whole top navigation there, and a scan that ignored it would
 * report a styled layout as broken.
 *
 * ## The scope is one direction
 *
 * Only `class="…"` attributes written in `modules/Cardboard`, the panel's own
 * markup, are read. Not the classes a module generator computes at runtime
 * from data, not the theme prefixes (`je-`, `st-`) that `ThemeKernel` owns, and
 * not the same attribute in a docblock: the scan tokenises, so an example in a
 * comment is not the markup emitting anything.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminClassContractTest extends TestCase
{
    private const string MODULE = 'Cardboard';

    #[Test]
    public function everyEmittedAdminClassHasAStylesheetRule(): void
    {
        $defined = $this->definedSelectors();
        $emitted = $this->emittedClasses();

        $undefined = [];

        foreach ($emitted as $class => $files) {
            if (!$this->isDefined($class, $defined)) {
                $undefined[$class] = $files;
            }
        }

        ksort($undefined);

        self::assertSame([], $undefined, sprintf(
            "These classes are written by the panel's markup and defined by no stylesheet:\n%s\n"
            . 'A class with no rule renders unstyled, which is how `m-muted` read as body'
            . ' text in ninety places (Boot 47). Add the rule to'
            . ' Frontend/packages/moonshine/src/mark/*.css, or delete the class if it is'
            . ' dead markup. A base class that exists only as the hook for its BEM'
            . ' children and modifiers counts as defined.',
            implode("\n", array_map(
                static fn (string $class, array $files): string => sprintf(
                    '  %-28s %s',
                    $class,
                    implode(', ', array_unique($files)),
                ),
                array_keys($undefined),
                array_values($undefined),
            )),
        ));
    }

    /**
     * @param array<string, true> $defined
     */
    private function isDefined(string $class, array $defined): bool
    {
        // A prefix the markup concatenates: `m-badge--` + a tone or a size.
        if (str_ends_with($class, '-')) {
            $prefix = rtrim($class, '-');

            foreach (array_keys($defined) as $selector) {
                if (str_starts_with($selector, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($defined[$class])) {
            return true;
        }

        foreach (array_keys($defined) as $selector) {
            if (str_starts_with($selector, $class . '__') || str_starts_with($selector, $class . '--')) {
                return true;
            }
        }

        return in_array($class, $this->knownUndefined(), true);
    }

    /**
     * Classes measured undefined today, with the reason each stays.
     *
     * Empty on purpose, and the emptiness is the measurement: the four the scan
     * found were fixed rather than excused — `m-btn--sm` was a typo for the
     * `m-btn-sm` the sheet defines, `m-bulk__select-all` was a second name for
     * `m-bulk__check`, `m-mono` and `m-muted` arrived with the rule they had
     * always assumed. An entry here needs a reason a reader can check, and one
     * that stops applying fails the test: the list is a ledger, not a pass.
     *
     * @return list<string>
     */
    private function knownUndefined(): array
    {
        return [];
    }

    /**
     * @return array<string, list<string>> class => the markup files writing it
     */
    private function emittedClasses(): array
    {
        $classes = [];

        foreach ($this->markupFiles() as $file) {
            $relative = substr($file, strlen(APP_BASE_PATH) + 1);

            foreach ($this->classAttributes($file) as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                    if ('' === $class || 1 !== preg_match('/^(m-|cba-)/', $class)) {
                        continue;
                    }

                    if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $class)) {
                        continue;
                    }

                    $classes[$class][] = $relative;
                }
            }
        }

        return $classes;
    }

    /**
     * The `class="…"` values in a file's CODE.
     *
     * Tokenising rather than regexing the file is the difference between
     * reading markup and reading prose about markup: `Trend`'s docblock shows an
     * example `<span class="m-trend m-trend--flat">` that the panel never emits,
     * and a file-wide regex charged it as a missing rule. PHP strings and
     * template inline HTML are the two places an attribute is actually built.
     *
     * @return list<string>
     */
    private function classAttributes(string $file): array
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token)
                || (T_CONSTANT_ENCAPSED_STRING !== $token[0] && T_INLINE_HTML !== $token[0])
            ) {
                continue;
            }

            if (0 < preg_match_all('/class="([^"$]*?)"/', $token[1], $matches)) {
                foreach ($matches[1] as $attribute) {
                    $found[] = $attribute;
                }
            }
        }

        return $found;
    }

    /**
     * Every class selector the stylesheets define, keyed for lookup.
     *
     * @return array<string, true>
     */
    private function definedSelectors(): array
    {
        $defined = [];

        foreach ($this->cssFiles() as $file) {
            if (0 < preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', (string) file_get_contents($file), $matches)) {
                foreach ($matches[1] as $selector) {
                    $defined[$selector] = true;
                }
            }
        }

        // A layout may carry its own block: the compact panel defines its whole
        // top bar inside `compact.phtml`, and those classes ARE styled.
        foreach ($this->markupFiles() as $file) {
            if (0 < preg_match_all('/<style[^>]*>(.*?)<\/style>/s', (string) file_get_contents($file), $blocks)) {
                foreach ($blocks[1] as $block) {
                    if (0 < preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $block, $matches)) {
                        foreach ($matches[1] as $selector) {
                            $defined[$selector] = true;
                        }
                    }
                }
            }
        }

        return $defined;
    }

    /**
     * @return list<string>
     */
    private function markupFiles(): array
    {
        $dir = APP_BASE_PATH . '/modules/' . self::MODULE;

        if (!is_dir($dir)) {
            self::markTestSkipped(self::MODULE . ' is not catalogued.');
        }

        return $this->filesIn($dir, ['php', 'phtml']);
    }

    /**
     * @return list<string>
     */
    private function cssFiles(): array
    {
        return $this->filesIn(APP_BASE_PATH . '/Frontend', ['css']);
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private function filesIn(string $dir, array $extensions): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo
                && $file->isFile()
                && in_array($file->getExtension(), $extensions, true)
            ) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
