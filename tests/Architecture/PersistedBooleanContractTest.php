<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PersistedBooleanContractTest — the mechanical half of `docs/boolean-vocabulary.md`.
 *
 * The guide states the rule: a `bool` is fine inside a request, but a value
 * that crosses a process boundary must have a NAME. This test is what refuses
 * the next one that does not, in the three places a boolean can cross a
 * boundary in this tree:
 *
 *  1. a SQL schema — a column declared `BOOLEAN`, or an `INTEGER` named like a
 *     flag (`is_*`, `has_*`, `*_active`, `*_public`, `*_external`, …);
 *  2. a settings row — a seeded `settings` entry with `value_type = 'boolean'`,
 *     which stores `'1'`/`'0'` and needs the declaration to mean anything;
 *  3. a queued job — a `true`/`false` in a payload handed to `->push()`, or a
 *     `bool` in the declared shape of a `*Payload` builder.
 *
 * The columns and rows that exist TODAY are the ledger, not a debt: they are
 * named below with the reason they are allowed to stay, so the gate is about
 * the next one rather than a migration nobody asked for. The list may shrink;
 * adding to it is the conversation this test exists to force.
 *
 * ## What it reads, and what that means
 *
 * Sources are read as TEXT, the same way `SecurityEventPayloadContractTest`
 * reads emit sites: the shapes are SQL inside heredocs and arrays inside PHP,
 * and a tokenizer buys nothing here. Two consequences, stated rather than
 * discovered later:
 *
 *  - it sees a column DECLARATION, not a live schema. A table created at
 *    runtime outside these files would be missed until it is written down;
 *  - it sees a payload written as a literal or an array entry in the file that
 *    pushes the job. A boolean nested inside an untyped array assembled
 *    somewhere else is invisible to it.
 *
 * The scan is deliberately scoped to the CATALOGUE (`modules/`) plus the
 * kernel (`src/Modules/Core`, `Database`, `Tooling`, and `src/*.php`). The
 * installed slices under `src/Modules/<Name>/` are operator state and
 * gitignored; reading them would report every column twice and make the
 * verdict depend on which modules this workstation happens to have installed.
 *
 * @internal
 *
 * @coversNothing
 */
final class PersistedBooleanContractTest extends TestCase
{
    /**
     * The boolean columns this tree already stores, with the reason each one is
     * allowed to be a flag rather than a vocabulary.
     *
     * A method, not a class constant: `NameOwnershipContractTest` ratchets the
     * tree's `const` count, and a test's own lookup table is not a name
     * anybody else owns (the same choice `DocsCatalogContractTest` records).
     *
     * @return array<string, string> "table.column" => why it stays
     */
    private static function approvedColumns(): array
    {
        return [
            'users.is_active' => 'An account is enabled or disabled; no third state exists and the '
                . 'sign-in path branches on exactly this pair.',
            'settings.is_public' => 'A setting is readable by the public shell or it is not; the '
                . 'value has no other meaning at any call site.',
            'menu_items.external' => 'A menu link leaves the site or it does not; the renderer '
                . 'only picks the target attribute.',
            'menu_items.is_active' => 'A menu entry is shown or hidden; ordering is a separate '
                . 'column, so the boolean is not carrying the vocabulary.',
        ];
    }

    /**
     * Settings rows stored as `'1'`/`'0'` with `value_type = 'boolean'`.
     *
     * These are the declaration-plus-digit pattern the guide describes: the
     * digits are meaningless without the type beside them, which is why a new
     * one is a decision rather than a copy-paste.
     *
     * @return array<string, string> setting key => why it stays
     */
    private static function approvedSettingRows(): array
    {
        return [
            'registration_enabled' => 'The sign-up form is the only reader; it asks a yes/no '
                . 'question and shows or hides one route.',
            'maintenance_mode' => 'The front controller answers 503 or serves the page; the '
                . 'state is deliberately an operator switch, not a workflow.',
        ];
    }

    /**
     * A column name that reads like a flag: `is_active`, `has_icon`,
     * `menu_external`. A method rather than a constant for the same reason as
     * the ledgers above — see `approvedColumns()`.
     */
    private static function flagNamePattern(): string
    {
        return '/^(is|has|can|should|was|allow)_|_(enabled|active|public|visible|published|external)$/';
    }

    /**
     * No new boolean column reaches a schema without being named above.
     */
    #[Test]
    public function noNewBooleanColumnIsAddedToASchema(): void
    {
        $found = self::booleanColumns();

        self::assertNotSame(
            [],
            $found,
            'The schema scan found no boolean columns at all. The walk or the column pattern stopped'
            . ' working, and every approval below would then be vacuous while the suite stayed green.',
        );

        foreach ($found as $column => $where) {
            self::assertArrayHasKey(
                $column,
                self::approvedColumns(),
                sprintf(
                    '%s declares "%s", a boolean stored as a column. A value that outlives the process'
                    . ' must have a name: use the vocabulary column (status/kind/value_type) or an enum,'
                    . ' and read `docs/boolean-vocabulary.md` before adding it to the ledger. If the flag'
                    . ' really is the whole story, add "%s" to approvedColumns() with the reason — that'
                    . ' edit is the decision.',
                    $where,
                    $column,
                    $column,
                ),
            );
        }

        foreach (array_keys(self::approvedColumns()) as $approved) {
            self::assertArrayHasKey(
                $approved,
                $found,
                sprintf(
                    'The ledger approves "%s", but no schema declares it any more. The list may shrink:'
                    . ' remove the row instead of leaving an approval for a column nobody stores.',
                    $approved,
                ),
            );
        }
    }

    /**
     * No new settings row is stored as a boolean without being named above.
     */
    #[Test]
    public function noNewBooleanSettingRowIsAdded(): void
    {
        $found = self::booleanSettingRows();

        self::assertNotSame(
            [],
            $found,
            'No `value_type = \'boolean\'` seed was found. The pattern stopped matching — the seeds'
            . ' moved or changed shape — and this check is asserting nothing.',
        );

        foreach ($found as $key => $where) {
            self::assertArrayHasKey(
                $key,
                self::approvedSettingRows(),
                sprintf(
                    '%s seeds "%s" with value_type = \'boolean\'. That stores \'1\'/\'0\' plus a'
                    . ' declaration, which is a vocabulary in two pieces; a new one is a decision.'
                    . ' Add it to approvedSettingRows() with the reason, or store the state as a'
                    . ' named string.',
                    $where,
                    $key,
                ),
            );
        }

        foreach (array_keys(self::approvedSettingRows()) as $approved) {
            self::assertArrayHasKey(
                $approved,
                $found,
                sprintf(
                    'The ledger approves the settings row "%s", but no migration seeds it as a boolean'
                    . ' any more. Remove the row rather than leaving an approval for a value nobody stores.',
                    $approved,
                ),
            );
        }
    }

    /**
     * No boolean travels to a queued job.
     *
     * The payload is the one boundary this tree cannot re-read: the producer's
     * process is gone by the time the worker decodes the row. A `true` there
     * reaches the handler as `true`, with no declaration of what it meant —
     * exactly the shape the guide refuses for `kind` and the job result.
     */
    #[Test]
    public function noBooleanTravelsToAQueuedJob(): void
    {
        $pushes = self::pushSites();
        $payloadBuilders = self::payloadBuilders();

        self::assertGreaterThanOrEqual(
            2,
            count($pushes),
            'Fewer than two job producers were found — the `->push(` scan stopped seeing this tree,'
            . ' and this check would pass by scanning nothing.',
        );
        self::assertNotSame(
            [],
            $payloadBuilders,
            'No `*Payload` builder was found. The convention moved, and the declared-shape half of'
            . ' this check is asserting nothing.',
        );

        foreach ([...$pushes, ...$payloadBuilders] as $file => $source) {
            self::assertDoesNotMatchRegularExpression(
                '/\'[a-z_][a-z0-9_]*\'\s*=>\s*(true|false)\b/i',
                $source,
                sprintf(
                    '%s hands a boolean to a queued job (an array entry `key => true|false`).'
                    . ' A payload crosses a process boundary: name the state (`kind`, `status`, a'
                    . ' value_type) instead of storing a flag nobody can decode later.'
                    . ' See docs/boolean-vocabulary.md, question 2.',
                    $file,
                ),
            );
            self::assertDoesNotMatchRegularExpression(
                '/\[[\'"][a-z_][a-z0-9_]*[\'"]\]\s*=\s*(true|false)\b/i',
                $source,
                sprintf(
                    '%s assigns a boolean into a queued payload by key. The worker receives the'
                    . ' value without the decision that produced it — give the state a name.',
                    $file,
                ),
            );
        }

        foreach ($payloadBuilders as $file => $source) {
            self::assertDoesNotMatchRegularExpression(
                '/@return\s+array\{[^}]*\bbool\b/',
                $source,
                sprintf(
                    '%s declares a `bool` in a payload shape. The shape is the contract a producer'
                    . ' and a handler agree on; declaring a boolean there makes the payload the'
                    . ' least self-describing place in the system. Use a string vocabulary.',
                    $file,
                ),
            );
        }
    }

    /**
     * Every boolean column in the scanned sources: "table.column" => file:line.
     *
     * A column counts when its SQL type is `BOOLEAN`, or when it is `INTEGER`
     * and its name reads like a flag. The second half is not redundant: this
     * tree already stores two flags as `INTEGER DEFAULT 0|1`, which is the
     * exact drift a type-only check misses.
     *
     * `ALTER TABLE … ADD COLUMN` is read too — a column added later is still a
     * column, and a schema check that only reads `CREATE TABLE` would let the
     * next one in through a migration helper.
     *
     * @return array<string, string>
     */
    private static function booleanColumns(): array
    {
        $columns = [];

        foreach (self::sources() as $file => $source) {
            $table = '';
            $lineNumber = 0;

            foreach (explode("\n", $source) as $line) {
                $lineNumber++;

                if (1 === preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+([a-z_][a-z0-9_]*)/i', $line, $matched)) {
                    $table = strtolower($matched[1]);
                }

                if (1 === preg_match(
                    '/ALTER TABLE\s+([a-z_][a-z0-9_]*)\s+ADD COLUMN\s+([a-z_][a-z0-9_]*)\s+(BOOLEAN|INTEGER)\b/i',
                    $line,
                    $altered,
                )) {
                    $name = strtolower($altered[2]);

                    if (self::isBooleanColumn($name, strtoupper($altered[3]))) {
                        $columns[strtolower($altered[1]) . '.' . $name] = $file . ':' . $lineNumber;
                    }

                    continue;
                }

                if ('' === $table || 1 !== preg_match(
                    '/^\s*([a-z_][a-z0-9_]*)\s+(BOOLEAN|INTEGER)\b/i',
                    $line,
                    $matched,
                )) {
                    continue;
                }

                $name = strtolower($matched[1]);

                if (self::isBooleanColumn($name, strtoupper($matched[2]))) {
                    $columns[$table . '.' . $name] = $file . ':' . $lineNumber;
                }
            }
        }

        return $columns;
    }

    private static function isBooleanColumn(string $name, string $type): bool
    {
        return 'BOOLEAN' === $type || 1 === preg_match(self::flagNamePattern(), $name);
    }

    /**
     * `settings` rows seeded with `value_type = 'boolean'`: key => file.
     *
     * @return array<string, string>
     */
    private static function booleanSettingRows(): array
    {
        $rows = [];

        foreach (self::sources() as $file => $source) {
            if (0 === preg_match_all(
                '/\[\s*\'([a-z_][a-z0-9_]*)\'\s*,\s*\'[^\']*\'\s*,\s*\'boolean\'/',
                $source,
                $matched,
            )) {
                continue;
            }

            foreach ($matched[1] as $key) {
                $rows[$key] = $file;
            }
        }

        return $rows;
    }

    /**
     * Production files that hand a payload to the queue: path => source.
     *
     * @return array<string, string>
     */
    private static function pushSites(): array
    {
        $sites = [];

        foreach (self::sources() as $file => $source) {
            if (str_contains($source, '->push(')) {
                $sites[$file] = $source;
            }
        }

        return $sites;
    }

    /**
     * Files that build a job payload by name (`NotificationPayload`, …):
     * path => source. The convention is the class name, stated here because a
     * payload builder that does not follow it is invisible to this half.
     *
     * @return array<string, string>
     */
    private static function payloadBuilders(): array
    {
        $builders = [];

        foreach (self::sources() as $file => $source) {
            if (str_ends_with($file, 'Payload.php')) {
                $builders[$file] = $source;
            }
        }

        return $builders;
    }

    /**
     * The committed production sources this test reads.
     *
     * Catalogue + kernel + the three `src/` root scripts. Installed slices
     * under `src/Modules/<Name>/` are skipped on purpose: operator state,
     * gitignored, and a copy of a catalogue file — reading both would double
     * every row and make a green run depend on what is installed.
     *
     * @return array<string, string> repository-relative path => contents
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];

        foreach (['modules', 'src/Modules/Core', 'src/Modules/Database', 'src/Modules/Tooling'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                if (!str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                    continue;
                }

                $relative = str_replace($root . '/', '', $path);
                $contents = @file_get_contents($path);

                if (false !== $contents) {
                    $sources[$relative] = $contents;
                }
            }
        }

        foreach (glob($root . '/src/*.php') ?: [] as $file) {
            $contents = @file_get_contents($file);

            if (false !== $contents) {
                $sources[str_replace($root . '/', '', $file)] = $contents;
            }
        }

        self::assertGreaterThanOrEqual(
            100,
            count($sources),
            'The source walk returned ' . count($sources) . ' files — the roots moved, and every check'
            . ' in this test would pass by reading almost nothing.',
        );

        return $sources;
    }
}
