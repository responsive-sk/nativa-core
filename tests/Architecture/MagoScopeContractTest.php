<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\TestCase;

/**
 * MagoScopeContractTest — the spike measures the gate's tree, plus the
 * catalogue.
 *
 * `mago.toml` has always stated the rule: its `[source] paths` are
 * `phpstan.neon`'s `paths`, entry for entry, plus `modules/`. It also named this
 * file as the thing keeping that true — and this file did not exist (measured
 * 2026-11-12), so the sentence was a claim with nothing behind it. A spike that
 * measures a narrower tree is a spike that answers a different question, which
 * is exactly what happens if one config gains a path and the other does not.
 *
 * The two additions are deliberate and are asserted in both directions:
 *
 *  - `modules/` is in the mago scope only. It is shipped code an install runs,
 *    so lint and analyze read it; `phpstan.neon` leaves it out because until
 *    `module:add` copies a slice, the catalogue is text, and its verification is
 *    `module:show`'s closure scan. `src/Modules/KernelTheme` is the one tracked
 *    slice that is neither (see `KernelThemeBoundaryTest`).
 *
 * The parsers below are line scanners, not YAML/TOML readers: these two files
 * have one list each, in the one shape the comments around them describe, and a
 * shape change should fail here loudly rather than be absorbed by a dependency
 * the kernel does not carry. Entries are compared without a trailing separator:
 * `tests/` and `tests` name the same tree, and PHPUnit's `paths:` happens to
 * spell the first while mago's `paths` spells the second.
 *
 * @internal
 *
 * @coversNothing
 */
final class MagoScopeContractTest extends TestCase
{
    /**
     * The spike reads the gate's tree and one thing more.
     */
    public function testMagoMeasuresThePhpstanScopePlusTheCatalogue(): void
    {
        $phpstan = self::phpstanPaths();
        $mago = self::magoPaths();

        $expected = $phpstan;
        $expected[] = 'modules';

        sort($expected);
        sort($mago);

        self::assertSame(
            $expected,
            $mago,
            "mago.toml's scope drifted from phpstan.neon's:\n"
            . '  phpstan: ' . implode(', ', $phpstan) . "\n"
            . '  mago:    ' . implode(', ', $mago) . "\n\n"
            . 'The spike must measure the gate\'s tree plus `modules/` and nothing else. Add the path to '
            . 'both configs, or write down here why the readings legitimately differ.',
        );
    }

    /**
     * The catalogue stays out of the static-analysis gate.
     *
     * The reverse of the rule above, and the half that would be lost first: a
     * `modules` entry added to `phpstan.neon` "so it is covered too" would make
     * the gate's verdict depend on a tree PHPStan cannot execute.
     */
    public function testTheCatalogueStaysOutOfThePhpstanGate(): void
    {
        self::assertNotContains(
            'modules',
            self::phpstanPaths(),
            'the catalogue is text until module:add copies a slice; phpstan.neon leaves it out on purpose, '
            . 'and module:show\'s closure scan is its verification. Adding it here would analyse a tree '
            . 'the kernel does not run.',
        );
    }

    /**
     * The parsers must read something.
     *
     * Both rules compare lists extracted from files. A renamed key or a moved
     * block would leave a list empty, and an empty list compares equal to
     * nothing while the suite stays green — so the extraction proves itself:
     * 11 phpstan paths and 12 mago paths when this test was written.
     */
    public function testTheParsersReadBothConfigs(): void
    {
        self::assertGreaterThanOrEqual(
            10,
            \count(self::phpstanPaths()),
            'phpstan.neon\'s paths list was not read — the `paths:` block moved or its shape changed',
        );

        self::assertGreaterThanOrEqual(
            11,
            \count(self::magoPaths()),
            'mago.toml\'s `[source] paths` list was not read — the block moved or its shape changed',
        );
    }

    /**
     * `phpstan.neon`'s `paths:` entries, in file order.
     *
     * @return list<string>
     */
    private static function phpstanPaths(): array
    {
        $lines = file(self::config('phpstan.neon'), FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            return [];
        }

        $paths = [];
        $inside = false;

        foreach ($lines as $line) {
            if (!$inside) {
                if (1 === preg_match('/^\s*paths:\s*$/', $line)) {
                    $inside = true;
                }

                continue;
            }

            // Blank lines and comments between entries are part of the block,
            // not the end of it: `paths` carries three annotated groups.
            if ('' === trim($line) || 1 === preg_match('/^\s*#/', $line)) {
                continue;
            }

            // A list item, or the end of the block: the next key, or anything
            // that is not `- value`.
            if (1 === preg_match('/^\s+-\s+(.+?)\s*$/', $line, $match)) {
                $paths[] = rtrim(trim($match[1], '"\''), '/');

                continue;
            }

            break;
        }

        return $paths;
    }

    /**
     * `mago.toml`'s `[source] paths` entries, in file order.
     *
     * @return list<string>
     */
    private static function magoPaths(): array
    {
        $lines = file(self::config('mago.toml'), FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            return [];
        }

        $paths = [];
        $inside = false;

        foreach ($lines as $line) {
            if (!$inside) {
                $inside = 1 === preg_match('/^paths\s*=\s*\[/', $line);

                continue;
            }

            if (1 === preg_match_all('/"([^"]+)"/', $line, $matches)) {
                foreach ($matches[1] as $path) {
                    $paths[] = rtrim($path, '/');
                }
            }

            if (str_contains($line, ']')) {
                break;
            }
        }

        return $paths;
    }

    /**
     * A config file at the repository root.
     */
    private static function config(string $name): string
    {
        return AppPaths::instance()->join($name);
    }
}
