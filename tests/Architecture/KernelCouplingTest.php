<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\KernelCoupling;

/**
 * KernelCouplingTest — the kernel's feature-module edges, asserted in both
 * directions.
 *
 * This tree is `Modules\Core` + `Modules\Database` and boots with no feature
 * module installed, so the invariant is one sentence: **no file under the
 * kernel names another module.** "The kernel is clean" and "the kernel is clean
 * apart from the two places we know about" are different statements and only
 * one can be true — which is why the edges are written down rather than
 * asserted as a count.
 *
 * The walk covers the kernel MODULES and the BOOTSTRAP FILES
 * (`src/http.php`, `src/init.php`, `src/composition.php`) — the sentence is
 * about the kernel, not about a directory. `src/http.php` used to carry the
 * tree's only `use Modules\Security\…`, outside every scan, while its own
 * header promised no such line exists (measured 2026-11-12); the list is
 * explicit so that file stays read.
 *
 * Ported from `../nativa-minimal/tests/Architecture/KernelCouplingTest.php`
 * (2026-10-15), which carried a 34-edge ledger from a tree still importing
 * Blog, Locales, Settings, Users, Security and Media from Core. This tree has
 * carried two (the Outbox pair in `RateLimitMiddleware`), so:
 *
 *  - `COUPLING_LEDGER` starts **empty** — the target state, not a transcription
 *    of a tree that no longer exists here. The first run of this test on an
 *    untouched checkout therefore FAILS with two `NEW EDGE` lines, which is the
 *    point: a guard that has never been seen failing has never been seen.
 *    Task 4 of the plan closes those two edges and turns it green.
 *  - `UNRESOLVED_EDGES_BASELINE` drops from 23 to 0 — no `M` (move) or `C`
 *    (contract) edge exists in this tree to leave unresolved.
 *  - The upstream `#[DataProvider]` over ledger rows is folded into the
 *    set-equality test. With an empty ledger it would supply zero cases, and
 *    `phpunit.xml` sets `failOnRisky="true"` — an empty test is a risky test.
 *    Folding keeps every assertion and always executes at least one.
 *  - The scan sanity check reads `allModulesImports()` instead of `edges()`.
 *    `edges()` filters the shared kernel out, so the moment the tree is truly
 *    clean it reports nothing — indistinguishable from a walker that stopped
 *    matching. The kernel's own ~141 self-imports are the signal that does not
 *    go quiet.
 *  - `.php-cs-fixer.dist.php` carries `fully_qualified_strict_types.import_symbols
 *    => false` — the return address for the bullet above. With `true` the fixer
 *    PULLS the two Outbox imports back into `RateLimitMiddleware`, which spells
 *    both names inline on purpose so this ledger has no edge to count: this test
 *    would go red on a style change nobody made here. That comment points at
 *    this test; this is where a reader standing in the test learns why the fixer
 *    is configured the way it is.
 *
 * Two failure modes this guard exists for, neither visible from a green suite:
 *
 *  1. **A new edge appears.** `use Modules\Blog\…` typed into a Core file looks
 *     like convenience and is a downgrade of the kernel's independence. The
 *     page still renders; the kernel-only claim just stops being true.
 *  2. **A ledger row goes stale.** A row whose file moved, or whose class was
 *     renamed, reads as policy while describing nothing.
 *
 * @internal
 *
 * @coversNothing
 */
final class KernelCouplingTest extends TestCase
{
    /**
     * The edges as decided, keyed by `path::Symbol` (the key `KernelCoupling`
     * produces), valued with the module the symbol belongs to, the answer, and
     * the reason it got that answer. Answers: `M` move the file to the module
     * that owns it, `C` keep the surface and invert the edge behind a kernel
     * port, `G` guard the call site so the surface exists without the module.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array COUPLING_LEDGER = [];

    /**
     * `M` + `C` edges still unresolved.
     *
     * A ratchet, not a target: shrinking it is the work, so a smaller number
     * passes without touching this constant. Growing it fails — and since the
     * set equality above already catches an edge nobody classified, the only
     * way this constant can be wrong is a ledger row added without asking why.
     */
    private const int UNRESOLVED_EDGES_BASELINE = 0;

    /**
     * The scanner must find something.
     *
     * Every rule below compares against a scan. A walk that stopped matching —
     * a moved directory, a changed extension, a typo in the path — would leave
     * both sides empty, the set equality true, and the guard asserting nothing
     * while the suite stayed green. `allModulesImports()` is used rather than
     * `edges()` for exactly this: the kernel imports itself dozens of times, so
     * only a broken walker reads empty.
     */
    public function testTheScannerFindsTheKernelsImports(): void
    {
        $imports = KernelCoupling::allModulesImports();

        self::assertNotEmpty(
            $imports,
            'the scanner found no `Modules\\…` imports at all in the kernel '
            . '(src/Modules/{Core,Database,Tooling} plus the bootstrap files) — '
            . 'the walk or the tokenizer stopped working, and every rule below would then cover '
            . 'zero edges while the suite stayed green',
        );

        self::assertGreaterThan(
            60,
            \count($imports),
            'the scanner found ' . \count($imports) . ' imports where the kernel alone carries ~68 — '
            . 'the walk is traversing only part of the tree',
        );

        foreach ($imports as $key => $edge) {
            $inKernel = str_starts_with($edge['file'], 'src/Modules/')
                || \in_array($edge['file'], KernelCoupling::BOOTSTRAP_FILES, true);

            self::assertTrue(
                $inKernel,
                $key . ' resolved to a path outside src/Modules and the bootstrap list',
            );
        }
    }

    /**
     * Both directions at once: an unclassified edge, and a row whose edge is
     * gone. Row metadata rides along here so the test always carries at least
     * one assertion even when the ledger is empty.
     */
    public function testEveryEdgeIsClassifiedAndEveryRowNamesARealEdge(): void
    {
        $edges = KernelCoupling::edges();

        // The contract's type, not the current value's: a phpstan that reads
        // `COUPLING_LEDGER`'s literal `[]` narrows `$stale` and `$answer` to
        // *NEVER* and reports the two loops below as dead — correct for today's
        // row count, and exactly the narrowing that would stop analysing this
        // code the moment a row is added. The ledger is written to grow.
        /** @var array<string, array{string, string, string}> $ledger */
        $ledger = self::COUPLING_LEDGER;

        $unclassified = array_diff_key($edges, $ledger);
        $stale = array_diff_key($ledger, $edges);

        $problems = [];

        foreach ($unclassified as $key => $edge) {
            $problems[] = sprintf(
                'NEW EDGE   %s:%d imports %s — classify it (M move / C contract / G guard) in '
                . 'COUPLING_LEDGER with the reason, or remove the import',
                $edge['file'],
                $edge['line'],
                $edge['module'],
            );
        }

        foreach ($stale as $key => $row) {
            $problems[] = sprintf(
                'STALE ROW  %s is %s/%s but no such import exists — the file was moved, the class '
                . 'renamed, or the edge already resolved; delete the row',
                $key,
                $row[0],
                $row[1],
            );
        }

        // Row metadata: a wrong module, an answer outside M/C/G, or an empty
        // reason turns the ledger into decoration.
        foreach ($ledger as $key => $row) {
            [$module, $answer, $reason] = $row;

            if (!\in_array($answer, ['M', 'C', 'G'], true)) {
                $problems[] = sprintf(
                    'BAD ANSWER %s declares "%s", which is not M (move), C (contract) or G (guard)',
                    $key,
                    $answer,
                );
            }

            if ('' === trim($reason)) {
                $problems[] = sprintf(
                    'NO REASON  %s carries no reason — an answer nobody can argue with is an '
                    . 'answer nobody decided',
                    $key,
                );
            }

            $position = strpos($key, '::');
            $symbol = false === $position ? $key : substr($key, $position + 2);
            $owner = KernelCoupling::moduleOf($symbol);

            if ($module !== $owner) {
                $problems[] = sprintf(
                    'WRONG MODULE %s declares %s but the imported symbol belongs to %s',
                    $key,
                    $module,
                    $owner ?? '(nothing — not a Modules symbol)',
                );
            }
        }

        self::assertSame(
            [],
            $problems,
            "The kernel's coupling ledger has drifted:\n" . implode("\n", $problems)
            . "\n\nLedger: " . \count($ledger) . ' rows · scan: ' . \count($edges) . ' edges',
        );
    }

    /**
     * The number the change exists to move.
     *
     * `G` edges are permanent — a kernel middleware always guards its call
     * site — so progress is measured on `M` + `C` alone. This tree resolves
     * every feature-module import, so the baseline is zero: a new `M`/`C` row
     * is a decision to unwind, not a number to raise.
     */
    public function testTheUnresolvedEdgeCountOnlyShrinks(): void
    {
        $unresolved = array_filter(
            self::COUPLING_LEDGER,
            static fn (array $row): bool => \in_array($row[1], ['M', 'C'], true),
        );

        self::assertLessThanOrEqual(
            self::UNRESOLVED_EDGES_BASELINE,
            \count($unresolved),
            'The kernel gained unresolved edges (' . \count($unresolved) . ' against a baseline of '
            . self::UNRESOLVED_EDGES_BASELINE . '). Shrinking is the work; this number may fall '
            . 'freely but must never rise — resolve the edge instead of raising the constant.',
        );
    }
}
