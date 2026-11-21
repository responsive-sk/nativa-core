<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * FileNameVocabularyTest — no path on disk names the panel with the word a
 * scanner greps for.
 *
 * The panel answers at `/mark`, its routes are `mark.*`, its design system is
 * `mark.css`, its classes are `m-`, and `ADMIN_PATH` defaults to that spelling.
 * The name on disk was the one place the application and its own files
 * disagreed: measured 2026-11-17 on export `cba3518`, 130 tracked paths carried
 * `admin` — 99 of them inside `modules/RestApi/` — including the kernel's own
 * route table.
 *
 * ## What this is, and what it is not
 *
 * A vocabulary rule, not a security control. `MarkRoutePolicy` still fails
 * closed on a path no table row classifies and `MarkUnavailableMiddleware`
 * still answers 404 when no auth provider is installed; who may open the panel
 * does not change because a file was renamed. What changes is that a leaked
 * archive, an error trace or a `git ls-files` listing no longer names the panel
 * with the one word the runtime never uses.
 *
 * ## The ledger fails in both directions
 *
 * `.ai-factory/plans/feature-mark-file-vocabulary.md` burns today's paths down
 * to zero. Until then `excused()` lists them with the task that removes each,
 * and the list is compared BOTH ways: an offending path that is not listed
 * fails, and a listed entry that no longer excuses anything also fails. An
 * excuse that outlives its defect is a claim the suite would otherwise repeat.
 * The finish line is an empty ledger, not a passing allowance.
 *
 * A directory entry ends in `/` and excuses everything beneath it:
 * `modules/RestApi/` is one module move, not 99 separate renames.
 *
 * ## The second test catches the half-rename
 *
 * A path gate covers declarations too — PSR-4 makes the file name and the type
 * short name one fact — but only while the file is renamed at all. A type
 * renamed without its file, or a file renamed without its type, is caught here
 * for files the ledger does not already excuse. Fixture classes declared
 * inside a test file are the case paths cannot see.
 *
 * @internal
 *
 * @coversNothing
 */
final class FileNameVocabularyTest extends TestCase
{
    #[Test]
    public function noTrackedPathNamesThePanelWithTheForbiddenWord(): void
    {
        $offenders = $this->offendingPaths();
        $excused = $this->excused();

        $unexcused = array_values(array_filter(
            $offenders,
            fn (string $path): bool => !$this->isExcused($path, $excused),
        ));

        self::assertSame([], $unexcused, sprintf(
            "These tracked paths carry the word the panel never serves:\n%s\n"
            . 'The panel is `mark` (surface) and `Cardboard` (app); see'
            . " `.ai-factory/plans/feature-mark-file-vocabulary.md` for the mapping.\n"
            . 'A path gate is cheap; an obvious keyword in a leaked archive is not.',
            implode("\n", array_map(static fn (string $path): string => '  ' . $path, $unexcused)),
        ));

        $stale = [];

        foreach ($excused as $entry) {
            $matched = false;

            foreach ($offenders as $path) {
                if ($this->isExcused($path, [$entry])) {
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                $stale[] = $entry;
            }
        }

        self::assertSame([], $stale, sprintf(
            "These ledger entries excuse nothing any more:\n%s\n"
            . 'The path was renamed and the excuse stayed. The ledger is a task list,'
            . ' not an allowance — delete the entry with the path it named.',
            implode("\n", array_map(static fn (string $entry): string => '  ' . $entry, $stale)),
        ));
    }

    #[Test]
    public function noTypeIsNamedAfterThePanelOutsideAnExcusedPath(): void
    {
        $excused = $this->excused();
        $found = [];

        foreach ($this->trackedPaths() as $relative) {
            if (!str_ends_with($relative, '.php') || $this->isExcused($relative, $excused)) {
                continue;
            }

            foreach ($this->declaredNames($relative) as $name) {
                if (str_starts_with($name, 'Admin')) {
                    $found[$name] = $relative;
                }
            }
        }

        ksort($found);

        self::assertSame($this->knownDeclarations(), array_keys($found), sprintf(
            "These types are declared with the forbidden word in a file whose own name is clean:\n%s\n"
            . 'Rename the type with the file, or move it into a conforming one — a type'
            . ' whose file disagrees with it is the half-rename this gate exists for.',
            implode("\n", array_map(
                static fn (string $name, string $file): string => sprintf('  %-32s %s', $name, $file),
                array_keys($found),
                array_values($found),
            )),
        ));
    }

    /**
     * Paths that still carry the word, and the task that removes each.
     *
     * Empty on purpose, and the emptiness is the measurement: `admin` leaves
     * the tree in the round that writes this test, file by file, task by task.
     * An entry needs the task that deletes it; one that stops applying fails
     * `noTrackedPathNamesThePanelWithTheForbiddenWord()`.
     *
     * @return list<string>
     */
    private function excused(): array
    {
        return [];
    }

    /**
     * Declaration names the ledger cannot see, keyed by their file.
     *
     * Empty on purpose: the fixtures that lived in a clean-named test file
     * (`MarkFixtureAction`, `MarkRedirectProbeAction`) were renamed with the
     * kernel round rather than excused. An entry here needs a reason a reader
     * can check.
     *
     * @return list<string>
     */
    private function knownDeclarations(): array
    {
        return [];
    }

    /**
     * Every tracked path that carries the word in any segment.
     *
     * @return list<string>
     */
    private function offendingPaths(): array
    {
        $offenders = [];

        foreach ($this->trackedPaths() as $path) {
            foreach (explode('/', $path) as $segment) {
                if (false !== stripos($segment, 'admin')) {
                    $offenders[] = $path;

                    break;
                }
            }
        }

        return $offenders;
    }

    /**
     * Every path the repository tracks, relative and sorted.
     *
     * `git ls-files` is the authority: an installed module slice and the `dist/`
     * build are operator state, and reading them as tree vocabulary would fail
     * this test on a working copy that is behaving. A checkout without `.git`
     * (a tarball, a built artifact) falls back to a walk with the same
     * boundaries.
     *
     * @return list<string>
     */
    private function trackedPaths(): array
    {
        if (is_dir(APP_BASE_PATH . '/.git')) {
            $output = shell_exec('git -C ' . escapeshellarg(APP_BASE_PATH) . ' ls-files');

            if (is_string($output) && '' !== trim($output)) {
                $paths = array_values(array_filter(
                    array_map(static fn (string $line): string => trim($line), explode("\n", $output)),
                    static fn (string $path): bool => '' !== $path,
                ));

                sort($paths);

                return $paths;
            }
        }

        $root = APP_BASE_PATH;
        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !self::isGenerated(
                    substr($file->getPathname(), \strlen($root) + 1),
                ),
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $paths[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * A generated tree or an installed slice — not this repository's vocabulary.
     */
    private static function isGenerated(string $relative): bool
    {
        $segments = explode('/', $relative);

        if (in_array($segments[0], ['vendor', 'dist', '.git', 'node_modules', 'storage'], true)) {
            return true;
        }

        if ('public' === $segments[0] && 'dist' === ($segments[1] ?? '')) {
            return true;
        }

        return 'src' === $segments[0]
            && 'Modules' === ($segments[1] ?? '')
            && isset($segments[2])
            && !in_array($segments[2], ['Core', 'Database', 'Tooling', 'KernelTheme'], true);
    }

    /**
     * @param list<string> $entries
     */
    private function isExcused(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/')) {
                if (str_starts_with($path, $entry)) {
                    return true;
                }

                continue;
            }

            if ($path === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * The short names this file declares, read from tokens rather than text.
     *
     * A docblock that shows an example declaration is prose: tokenising is the
     * difference between reading code and reading a comment about code — the
     * lesson `MetricRegistryResolutionTest` records at the cost of one
     * dashboard card.
     *
     * @return list<string>
     */
    private function declaredNames(string $relative): array
    {
        $source = file_get_contents(APP_BASE_PATH . '/' . $relative);

        if (!is_string($source)) {
            return [];
        }

        $found = [];
        $expectName = false;

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $expectName = false;

                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $expectName = true;

                continue;
            }

            if (!$expectName) {
                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $expectName = false;

            if (T_STRING === $token[0]) {
                $found[] = $token[1];
            }
        }

        return $found;
    }
}
