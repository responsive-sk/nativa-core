<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MagoScopeContractTest — the spike measures the tree the gates measure, plus
 * the catalogue nothing else reads.
 *
 * `mago.toml` exists so `make mago-spike` reports numbers about *this* tree, and
 * a spike is only worth reading while its scope is the real one. The failure
 * this test exists for is quiet: drop `tests/` from `mago.toml` and every count
 * in `docs/mago-evaluation.md` improves — the analyzer's 1691 issues, the
 * formatter's 139 files — while the report keeps describing a narrower tree
 * than the one the catalogue and the kernel actually are. Nothing else in the
 * repo would notice.
 *
 * So the contract is stated once and checked in both directions:
 *
 *  - `mago.toml`'s `[source] paths` must cover `phpstan.neon`'s `paths`, entry
 *    for entry. `phpstan.neon` is the reference because it is the scope the
 *    project already defends in prose (installed module slices out, kernel
 *    tooling in) — a second list that agrees with it is a copy of a decision,
 *    not a new one.
 *  - the only path `mago.toml` may add is `modules`, the committed catalogue.
 *    That is a deliberate asymmetry, not an oversight: `phpstan.neon` leaves the
 *    catalogue out because it is a verbatim import from the showcase and its
 *    verification is `module:show`'s closure scan, while the linter and the
 *    analyzer read it because a finding there is a finding in code an install
 *    runs. Widening `phpstan` is a different decision with a different cost.
 *  - every path must exist, because a typo in `mago.toml` is silently an empty
 *    scope: Mago reports nothing about a directory it cannot find.
 *  - `php-version` must track the floor in `composer.json` (`^8.5` → `8.5`), so
 *    a PHP bump cannot leave the analyzer parsing an older language version
 *    than the gate runs.
 *
 * Deliberately parser-free: `phpstan.neon` is read for its `paths:` entries and
 * `mago.toml` for its `paths` array with two line scanners, no YAML or TOML
 * dependency added to a tree whose dev dependencies do not include one. If
 * either file is rewritten into a shape these scanners cannot read, the test
 * fails loudly rather than passing on an empty set.
 *
 * @internal
 *
 * @coversNothing
 */
final class MagoScopeContractTest extends TestCase
{
    #[Test]
    public function magoScopeCoversEveryPhpstanPath(): void
    {
        $phpstan = $this->phpstanPaths();
        $mago = $this->magoPaths();

        self::assertNotEmpty($phpstan, 'phpstan.neon paths came back empty — the scanner is broken, not the config.');
        self::assertNotEmpty($mago, 'mago.toml [source] paths came back empty — the scanner is broken, not the config.');

        sort($phpstan);
        sort($mago);

        self::assertSame(
            [],
            array_values(array_diff($phpstan, $mago)),
            'mago.toml [source] paths must include every phpstan.neon path. A spike over a narrower tree reports better numbers for the same code.',
        );
    }

    #[Test]
    public function magoScopeWidensThePhpstanScopeOnlyByTheCatalogue(): void
    {
        $phpstan = $this->phpstanPaths();
        $mago = $this->magoPaths();

        $extra = array_values(array_diff($mago, $phpstan));
        sort($extra);

        self::assertSame(
            ['modules'],
            $extra,
            'mago.toml may only add the committed catalogue (`modules`) to phpstan\'s scope. Any other extra path is a scope decision nobody made.',
        );
    }

    #[Test]
    public function everyMagoPathExists(): void
    {
        $root = $this->root();

        foreach ($this->magoPaths() as $path) {
            self::assertFileExists(
                $root . '/' . $path,
                sprintf('mago.toml names "%s", which does not exist — Mago would analyse nothing there.', $path),
            );
        }
    }

    #[Test]
    public function magoPhpVersionMatchesTheComposerFloor(): void
    {
        $constraint = $this->composerPhpConstraint();
        self::assertSame(
            1,
            preg_match('/^\^(\d+\.\d+)/', $constraint, $matches),
            sprintf('composer.json require.php is "%s", not a caret floor this test can read.', $constraint),
        );

        $floor = $matches[1] ?? null;
        self::assertIsString($floor);

        self::assertSame(
            $floor,
            $this->magoPhpVersion(),
            'mago.toml php-version must track composer.json require.php — otherwise the spike parses a language version the gate does not run.',
        );
    }

    /**
     * `phpstan.neon`'s `paths:` entries, in file order.
     *
     * The block is a YAML list indented under a `paths:` key; entries are the
     * lines that follow it with a `- ` marker, comments and blank lines between
     * them included. Scanning stops at the first line that is none of those,
     * which is where the next top-level key starts
     * (`reportUnmatchedIgnoredErrors`, `ignoreErrors`, …).
     *
     * @return list<string>
     */
    private function phpstanPaths(): array
    {
        $contents = file_get_contents($this->root() . '/phpstan.neon');
        self::assertIsString($contents);

        $paths = [];
        $inside = false;

        foreach (explode("\n", $contents) as $line) {
            if (!$inside) {
                $inside = 1 === preg_match('/^\s*paths:\s*$/', $line);

                continue;
            }

            if (1 === preg_match('/^\s+-\s+(\S+)\s*$/', $line, $matches)) {
                // `tests/` and `tests` are the same directory; compare on the
                // path, not on whether a trailing slash was typed.
                $paths[] = rtrim($matches[1], '/');

                continue;
            }

            // `phpstan.neon` carries prose comments inside the list (the reason
            // kernel tooling is in scope, why installed slices are out). They
            // are part of the block, not the start of the next key.
            if ('' === trim($line) || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            break;
        }

        return $paths;
    }

    /**
     * `mago.toml`'s `[source] paths` array, in file order.
     *
     * TOML allows the array on one line or spread over many; this scanner reads
     * every quoted string between the `paths = [` line and its closing `]`,
     * which covers both shapes without a TOML parser.
     *
     * @return list<string>
     */
    private function magoPaths(): array
    {
        $contents = file_get_contents($this->root() . '/mago.toml');
        self::assertIsString($contents);

        $paths = [];
        $inside = false;

        foreach (explode("\n", $contents) as $line) {
            if (!$inside) {
                $inside = 1 === preg_match('/^paths\s*=\s*\[/', $line);
                if (!$inside) {
                    continue;
                }
            }

            if (1 === preg_match_all('/"([^"]+)"/', $line, $matches)) {
                foreach ($matches[1] as $match) {
                    $paths[] = $match;
                }
            }

            if (str_contains($line, ']')) {
                break;
            }
        }

        return $paths;
    }

    private function magoPhpVersion(): string
    {
        $contents = file_get_contents($this->root() . '/mago.toml');
        self::assertIsString($contents);

        self::assertSame(
            1,
            preg_match('/^php-version\s*=\s*"([^"]+)"/m', $contents, $matches),
            'mago.toml has no php-version line — the analyzer would fall back to its own default.',
        );

        $version = $matches[1] ?? null;
        self::assertIsString($version);

        return $version;
    }

    private function composerPhpConstraint(): string
    {
        $contents = file_get_contents($this->root() . '/composer.json');
        self::assertIsString($contents);

        $composer = json_decode($contents, true);
        self::assertIsArray($composer);

        $require = $composer['require'] ?? null;
        self::assertIsArray($require);

        $constraint = $require['php'] ?? null;
        self::assertIsString($constraint);

        return $constraint;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
