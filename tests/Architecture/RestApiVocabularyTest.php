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
 * RestApiVocabularyTest — the slice is named after its subject, not the shell.
 *
 * `RestPanel` named the module after `Cardboard`'s job. The module is a REST
 * API admin: it renders a specification and speaks MCP, inside the panel shell.
 * `panel` belongs to `Cardboard`; `RestApi` belongs here.
 *
 * The gate fails in both directions on paths: a segment that carries the old
 * name and is not history fails, and a history entry that no longer exists
 * fails, so the exemption cannot outlive the document it excuses. A second
 * test reads declarations, because a type renamed without its file is the
 * half-rename a path gate cannot see.
 *
 * There is no ledger. The rename landed in one round; an empty allowance is
 * the finish line, not a passing exception.
 *
 * @internal
 *
 * @coversNothing
 */
final class RestApiVocabularyTest extends TestCase
{
    /**
     * Documents that recorded a tree that had `RestPanel`.
     *
     * A changelog and a boot log are records, not surfaces: rewriting them
     * would claim a name this tree never shipped under.
     *
     * @return list<string>
     */
    private static function history(): array
    {
        // The export prunes `docs/`, so a boot log is listed only when this
        // tree carries it. `CHANGELOG.md` ships and is checked every time.
        $files = ['CHANGELOG.md'];

        foreach ([
            'docs/kernel-status/32-migrations.md',
            'docs/kernel-status/34-runtime-sql.md',
            'docs/kernel-status/35-postgres-measured.md',
        ] as $file) {
            if (is_file(APP_BASE_PATH . '/' . $file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    #[Test]
    public function noTrackedPathNamesTheSliceAfterTheShell(): void
    {
        $offenders = array_values(array_filter(
            $this->offendingPaths(),
            static fn (string $path): bool => !in_array($path, self::history(), true),
        ));

        self::assertSame([], $offenders, sprintf(
            "These tracked paths still carry the retired module name:\n%s\n"
            . 'The module is `RestApi` (the REST API admin); `panel` belongs to `Cardboard`.'
            . " See `.ai-factory/plans/feature-restapi-module-name.md`.\n",
            implode("\n", array_map(static fn (string $path): string => '  ' . $path, $offenders)),
        ));
    }

    #[Test]
    public function theHistoryExemptionNamesRealFiles(): void
    {
        foreach (self::history() as $file) {
            self::assertFileExists(APP_BASE_PATH . '/' . $file, sprintf(
                'The history exemption names %s, which no longer exists; drop the entry rather than excusing nothing.',
                $file,
            ));
        }
    }

    #[Test]
    public function noTypeIsDeclaredUnderTheRetiredName(): void
    {
        $found = [];

        foreach ($this->trackedPaths() as $relative) {
            if (!str_ends_with($relative, '.php') || in_array($relative, self::history(), true)) {
                continue;
            }

            foreach ($this->declaredNames($relative) as $name) {
                if (str_starts_with($name, 'RestPanel')) {
                    $found[$name] = $relative;
                }
            }
        }

        ksort($found);

        self::assertSame([], $found, sprintf(
            "These types keep the retired module name in a clean-named file:\n%s\n"
            . 'Rename the type with the file; a type and its path disagreeing is the half-rename this gate exists for.',
            implode("\n", array_map(
                static fn (string $name, string $file): string => sprintf('  %-32s %s', $name, $file),
                array_keys($found),
                array_values($found),
            )),
        ));
    }

    /**
     * Every tracked path whose file name or directory carries the retired name.
     *
     * @return list<string>
     */
    private function offendingPaths(): array
    {
        $offenders = [];

        foreach ($this->trackedPaths() as $path) {
            foreach (explode('/', $path) as $segment) {
                if (preg_match('/rest[-_]?panel/i', $segment) === 1) {
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
     * `git ls-files` is the authority; a checkout without `.git` falls back to
     * a walk with the same boundaries, so an installed slice or a built
     * artifact is never read as tree vocabulary.
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
     * The short names this file declares, read from tokens rather than text.
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
