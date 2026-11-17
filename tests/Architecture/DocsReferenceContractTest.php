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
 * ## The documentation a reader treats as current has to point at something.
 *
 * A rename is a claim about the tree, and the prose that names the old spelling
 * is the part of the rename nothing compiles. Six pointers survived the
 * `admin` → `mark` vocabulary round that way: `docs/modules.md` named the
 * middleware the kernel had already renamed, `docs/boolean-vocabulary.md` named
 * the Users notifier, `docs/name-ownership.md` named a test file and a test
 * class by their old names, and the queue catalogue attributed `/mark/queue` to
 * an action class that no longer existed. Every one of them read as a fact and
 * was a dead end.
 *
 * So this gate reads the documents a reader is meant to trust — `docs/`, minus
 * the dated records — and checks two kinds of name against the tree:
 *
 *  - **a type**: a backticked CamelCase word whose suffix marks it as a
 *    declaration (`…Test`, `…Middleware`, `…Action`, `…Registry`, …) has to
 *    exist as a declared type or a file name;
 *  - **a path**: a backticked `src/`, `modules/`, `tests/`, `bin/`, `lekalo/`,
 *    `public/` or `docs/` path ending in a shipped extension has to exist on
 *    disk, with any `:line` suffix ignored because a line number is a hint and
 *    not a claim.
 *
 * ## What is deliberately out of scope
 *
 * The dated records — `docs/kernel-status.md` and `docs/kernel-status/`,
 * `docs/kernel-shape.md`, `docs/tree-history.md`, `docs/clanky/` and
 * `docs/mago-evaluation.md` — describe the tree as it was at a date, so an old
 * spelling there is the record working, not the record rotting. They are the
 * only place a retired name is allowed to sit outside an excuse.
 *
 * ## The ledger fails in both directions
 *
 * `excused()` lists the names a live document is allowed to spell without the
 * tree having them, each with the reason. A name that is missing from the tree
 * but not listed fails, and a listed name that no longer excuses anything also
 * fails: an excuse that outlives its defect is a claim this suite would
 * otherwise repeat as true.
 *
 * @internal
 *
 * @coversNothing
 */
final class DocsReferenceContractTest extends TestCase
{
    /**
     * Type suffixes that mark a backticked word as a declaration.
     *
     * Kept narrow on purpose: a bare `Cardboard`, `Users` or `Griffe` is a name
     * this tree owns as a value, and a value is not a declaration.
     *
     * @var list<string>
     */
    private const array TYPE_SUFFIXES = [
        'Action',
        'Command',
        'Contract',
        'Entity',
        'Gate',
        'Guard',
        'Handler',
        'Metric',
        'Middleware',
        'Migrator',
        'Policy',
        'Probe',
        'Provider',
        'Registry',
        'Renderer',
        'Repository',
        'Resolver',
        'Service',
        'Sink',
        'Test',
        'Usecase',
        'Widget',
    ];

    /**
     * Path prefixes a live document may point into.
     *
     * @var list<string>
     */
    private const array PATH_PREFIXES = ['bin/', 'docs/', 'lekalo/', 'modules/', 'public/', 'src/', 'tests/'];

    /**
     * Extensions a pointed-at path must carry to be checked.
     *
     * @var list<string>
     */
    private const array PATH_EXTENSIONS = ['.css', '.js', '.json', '.md', '.php', '.phtml', '.sh', '.ts', '.yaml'];

    /**
     * Documents that record a date, not the current tree.
     *
     * @var list<string>
     */
    private const array DATED = [
        'docs/clanky/',
        'docs/kernel-shape.md',
        'docs/kernel-status.md',
        'docs/kernel-status/',
        'docs/mago-evaluation.md',
        'docs/tree-history.md',
    ];

    /**
     * Names a live document may spell while the tree does not have them.
     *
     * @return array<string, string>
     */
    private static function excusedTypes(): array
    {
        return [
            'MarkdownSeparatorTest' => 'retired with the separator rule, and docs/prose-rhythm.md records that it went — the sentence has to be able to name what left',
        ];
    }

    /**
     * Paths a live document may spell while the tree does not have them.
     *
     * @return array<string, string>
     */
    private static function excusedPaths(): array
    {
        return [
            'src/Modules/Holder/Holder.php' => 'the import-refusal example in docs/modules.md — the path is a throwaway slice the documented run creates, so the tree has nothing to ship there',
        ];
    }

    #[Test]
    public function noLiveDocumentNamesATypeTheTreeDoesNotHave(): void
    {
        $documents = self::guardedDocuments();

        $declared = self::declaredTypeNames();
        $missing = [];

        foreach ($documents as $path) {
            foreach (self::namedTypes((string) file_get_contents($path)) as $type) {
                if (isset($declared[$type])) {
                    continue;
                }

                $missing[$type][$path] = true;
            }
        }

        $excused = self::excusedTypes();
        $unexpected = array_keys(array_diff_key($missing, $excused));
        $stale = array_keys(array_diff_key($excused, $missing));

        self::assertSame([], $unexpected, sprintf(
            "These documents name a type this tree does not declare:\n%s\n"
            . 'A rename that leaves the prose behind is a rename the reader cannot follow —'
            . ' point the sentence at the type that exists, or record why the name has to stay.',
            self::describe($unexpected, $missing),
        ));

        self::assertSame([], $stale, sprintf(
            "These excuses no longer excuse anything:\n%s\n"
            . 'The document stopped naming the retired type, so the entry is a claim about nothing.',
            self::describe($stale, $missing),
        ));
    }

    #[Test]
    public function noLiveDocumentPointsAtACodePathTheTreeDoesNotShip(): void
    {
        $documents = self::guardedDocuments();

        $missing = [];

        foreach ($documents as $path) {
            foreach (self::namedPaths((string) file_get_contents($path)) as $pointed) {
                if (file_exists(APP_BASE_PATH . '/' . $pointed)) {
                    continue;
                }

                $missing[$pointed][$path] = true;
            }
        }

        $excused = self::excusedPaths();
        $unexpected = array_keys(array_diff_key($missing, $excused));
        $stale = array_keys(array_diff_key($excused, $missing));

        self::assertSame([], $unexpected, sprintf(
            "These documents point at paths this tree does not ship:\n%s\n"
            . 'A path in prose is a file a reader will open, so it has to exist —'
            . ' a directory that moved is the same defect as a type that was renamed.',
            self::describe($unexpected, $missing),
        ));

        self::assertSame([], $stale, sprintf(
            "These path excuses no longer excuse anything:\n%s\n"
            . 'The document stopped pointing at the path, so the entry is a claim about nothing.',
            self::describe($stale, $missing),
        ));
    }

    /**
     * @param list<string>                       $names
     * @param array<string, array<string, true>> $where
     */
    private static function describe(array $names, array $where): string
    {
        $lines = [];
        foreach ($names as $name) {
            $lines[] = isset($where[$name])
                ? sprintf('  %s — named in %s', $name, implode(', ', array_keys($where[$name])))
                : '  ' . $name;
        }

        return implode("\n", $lines);
    }

    /**
     * The documents to check, or a skip when this tree has none.
     *
     * The export profile ships the code without `docs/`, and a gate with
     * nothing to read is not a passing gate — the ledger would read as stale
     * and the profile would report a failure about prose it never carried. The
     * profile says so out loud instead, which is the same answer the other
     * shipping gates give when their subject is pruned.
     *
     * @return list<string>
     */
    private static function guardedDocuments(): array
    {
        if (!is_dir(APP_BASE_PATH . '/docs')) {
            self::markTestSkipped('this profile ships no docs/ — the export leaves documentation behind');
        }

        $documents = self::liveDocuments();
        if ([] === $documents) {
            self::markTestSkipped('this tree ships no live documentation to check');
        }

        return $documents;
    }

    /**
     * The documents a reader treats as current: docs/ without the dated
     * records, plus the repository's own front pages.
     *
     * @return list<string>
     */
    private static function liveDocuments(): array
    {
        $documents = [];

        $root = APP_BASE_PATH . '/docs';
        if (is_dir($root)) {
            $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !$file->isDir() || !str_starts_with(
                    str_replace(APP_BASE_PATH . '/', '', $file->getPathname()) . '/',
                    'docs/clanky/',
                ),
            ));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.md')) {
                    continue;
                }

                $relative = str_replace(APP_BASE_PATH . '/', '', $file->getPathname());
                foreach (self::DATED as $dated) {
                    if ($relative === $dated || str_starts_with($relative, $dated)) {
                        continue 2;
                    }
                }

                $documents[] = $relative;
            }
        }

        foreach (['README.md', 'SECURITY.md', 'CONTRIBUTING.md'] as $front) {
            if (is_file(APP_BASE_PATH . '/' . $front)) {
                $documents[] = $front;
            }
        }

        sort($documents);

        return $documents;
    }

    /**
     * Every short type name the tree declares, plus every PHP file name.
     *
     * PSR-4 makes a file name and its type one fact, so both are the same
     * answer here. Fixture classes declared inside a test file are found by the
     * declaration scan, which is why it reads the bodies and not only the names.
     *
     * @return array<string, true>
     */
    private static function declaredTypeNames(): array
    {
        $skip = ['node_modules/', 'storage/', 'vendor/', '.ai-factory/', '.git/', '.upstream/'];
        $names = [];

        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(APP_BASE_PATH, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($skip): bool {
                if (!$file->isDir()) {
                    return true;
                }

                $relative = str_replace(APP_BASE_PATH . '/', '', $file->getPathname()) . '/';
                foreach ($skip as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $names[$file->getBasename('.php')] = true;

            if (!preg_match_all(
                '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m',
                (string) file_get_contents($file->getPathname()),
                $matches,
            )) {
                continue;
            }

            foreach ($matches[1] as $declared) {
                $names[$declared] = true;
            }
        }

        return $names;
    }

    /**
     * The backticked type names a document spells.
     *
     * @return list<string>
     */
    private static function namedTypes(string $markdown): array
    {
        if (!preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)`/', $markdown, $matches)) {
            return [];
        }

        $types = [];
        foreach ($matches[1] as $token) {
            $short = str_contains($token, '\\') ? substr($token, (int) strrpos($token, '\\') + 1) : $token;
            if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $short)) {
                continue;
            }

            foreach (self::TYPE_SUFFIXES as $suffix) {
                if (str_ends_with($short, $suffix)) {
                    $types[$short] = true;

                    break;
                }
            }
        }

        return array_keys($types);
    }

    /**
     * The backticked code paths a document points at, with any line suffix and
     * any trailing punctuation removed.
     *
     * A token carrying a glob, an ellipsis or a placeholder names a pattern
     * rather than a file, and a pattern has nothing to check.
     *
     * @return list<string>
     */
    private static function namedPaths(string $markdown): array
    {
        if (!preg_match_all('/`([^`\s]+)`/', $markdown, $matches)) {
            return [];
        }

        $paths = [];
        foreach ($matches[1] as $token) {
            if (str_contains($token, '…') || str_contains($token, '*') || str_contains($token, '<')) {
                continue;
            }

            $token = rtrim($token, '.,;:)');

            $prefixed = false;
            foreach (self::PATH_PREFIXES as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    $prefixed = true;

                    break;
                }
            }

            if (!$prefixed) {
                continue;
            }

            // `path.php:93` and `path.php:93,186` point at a line, and a line
            // number moves with every edit — the path is the claim.
            $token = (string) preg_replace('/:\d+(,\d+)*$/', '', $token);

            $checked = false;
            foreach (self::PATH_EXTENSIONS as $extension) {
                if (str_ends_with($token, $extension)) {
                    $checked = true;

                    break;
                }
            }

            if (!$checked) {
                continue;
            }

            $paths[$token] = true;
        }

        return array_keys($paths);
    }
}
