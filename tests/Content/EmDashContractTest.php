<?php

declare(strict_types = 1);

namespace Tests\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EmDashContractTest, the `docs/` half of the em dash ban.
 *
 * `ProseRhythmContractTest` holds the em dash out of the fifteen wrapped files,
 * which are the articles and the two guides that were converted. The rest of
 * `docs/` never was, so the ban there is a ratchet rather than a cliff: this
 * file freezes the count per document, a count may fall and never rise, and a
 * document that is not in the table must arrive at zero.
 *
 * Fenced blocks and inline code are stripped before the count, because a
 * document that quotes the character (`—`) or shows a tool's output is not
 * writing with it. That is the same line the rhythm check draws.
 *
 * The measurement this table was frozen from, 2026-11-08: 1 083 occurrences in
 * 24 files, `docs/kernel-status/07-ci-modules-gate.md` carrying 224 of them.
 * `kernel-status/10` was converted the same day and its row is gone, because a
 * document that is not in the table is held to zero.
 * That is why the gate starts here instead of at zero: a check that is red on
 * the tree it ships cannot be told apart from one that is broken.
 *
 * @internal
 *
 * @coversNothing
 */
final class EmDashContractTest extends TestCase
{
    /**
     * The frozen count per document. Lower a row when a document is converted,
     * or drop it entirely when the count reaches zero; never raise one.
     *
     * @return array<string, int>
     */
    private static function baseline(): array
    {
        return [
            'docs/boolean-vocabulary.md' => 17,
            'docs/clanky/2026-10-16-model-bez-vazby.md' => 28,
            'docs/clanky/2026-10-16-posledna-evolucia.md' => 34,
            'docs/clanky/2026-10-17-siesta-funkcia-portu.md' => 39,
            'docs/intro.md' => 6,
            'docs/kernel-shape.md' => 23,
            'docs/kernel-status.md' => 34,
            'docs/kernel-status/01-import-and-edge-cuts.md' => 94,
            'docs/kernel-status/02-admin-flow-and-self-hosting.md' => 68,
            'docs/kernel-status/03-content-themes-and-the-admin-merge.md' => 76,
            'docs/kernel-status/04-kernel-contracts.md' => 28,
            'docs/kernel-status/05-security-findings-7-to-9.md' => 47,
            'docs/kernel-status/06-auth-discovery-and-mail-transport.md' => 26,
            'docs/kernel-status/07-ci-modules-gate.md' => 224,
            'docs/kernel-status/08-docs-surface-and-reader-layout.md' => 18,
            'docs/kernel-status/09-clickhouse-sqlite-bridge.md' => 16,
            'docs/mago-evaluation.md' => 79,
            'docs/modules.md' => 140,
            'docs/name-ownership.md' => 36,
            'docs/pages.md' => 9,
            'docs/prose/docs-freshness.prose.md' => 3,
            'docs/themes.md' => 21,
            'docs/tree-history.md' => 16,
        ];
    }

    #[Test]
    public function noDocumentAddsAnEmDash(): void
    {
        $baseline = self::baseline();
        $files = self::documents();
        $problems = [];

        // A walk that finds nothing would pass by reading nothing.
        self::assertGreaterThan(20, count($files), 'The docs walk found no documents, so this check reads nothing.');

        foreach ($files as $file) {
            $found = self::emDashesIn($file);
            $allowed = $baseline[$file] ?? 0;

            if ($found <= $allowed) {
                continue;
            }

            $problems[] = sprintf(
                '%s: %d em dashes, the baseline allows %d. Write a line break instead, or the number may only fall.',
                $file,
                $found,
                $allowed,
            );
        }

        self::assertSame([], $problems, "The em dash is banned in prose, and the count may only fall:\n" . implode("\n", $problems));
    }

    #[Test]
    public function everyFrozenRowStillNamesADocument(): void
    {
        $files = array_flip(self::documents());
        $stale = [];

        foreach (array_keys(self::baseline()) as $file) {
            if (!isset($files[$file])) {
                $stale[] = $file;
            }
        }

        self::assertSame([], $stale, "A frozen count for a document that is gone is a row nobody measures:\n" . implode("\n", $stale));
    }

    /**
     * Every Markdown document under `docs/`, relative to the project root.
     *
     * @return list<string>
     */
    private static function documents(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        /** @var list<string> $paths */
        $paths = glob($root . '/docs/**/*.md', GLOB_BRACE | GLOB_NOSORT) ?: [];
        // `glob()` with `**` is not recursive on every platform this runs on,
        // so the walk is done once more by hand and the two are merged.
        $paths = array_merge($paths, self::walk($root . '/docs'));

        foreach ($paths as $path) {
            $relative = str_replace($root . '/', '', $path);

            if (str_ends_with($relative, '.md')) {
                $found[$relative] = $relative;
            }
        }

        $files = array_values($found);
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function walk(string $directory): array
    {
        $files = [];
        $entries = @scandir($directory) ?: [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $files = array_merge($files, self::walk($path));

                continue;
            }

            if (str_ends_with($entry, '.md')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Em dashes in one document's prose: fenced blocks and inline code are
     * stripped first, because there the character is data rather than writing.
     *
     * Named `emDashesIn` and not `count`: `TestCase::count()` is final, so a test
     * that declares its own `count()` is a compile-time fatal, and with Xdebug
     * 3.5.3 loaded that fatal can take the whole run down with no output at all
     * (measured on PHP 8.5.12, reported to the Xdebug tracker). Any other name is
     * a legal private helper.
     */
    private static function emDashesIn(string $file): int
    {
        $content = (string) @file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $content = (string) preg_replace('/^```.*?^```$/msu', '', $content);
        $content = (string) preg_replace('/`[^`]*`/u', '', $content);

        // A docblock or a comment inside a fenced sample is already gone; what
        // is left past this point is prose, and the dash in it is the defect.
        return substr_count($content, '—');
    }
}
