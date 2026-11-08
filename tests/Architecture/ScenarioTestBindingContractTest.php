<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScenarioTestBindingContractTest — every canonical openspec scenario names the
 * test that holds it.
 *
 * Measured 2026-10-27: 9 scenarios in `openspec/specs/validation/spec.md`, none
 * of them carrying a machine-readable reference to the test that keeps them
 * true. Deleting or renaming such a test left `openspec validate --all` green,
 * because the validator checks the model, not the code — the same gap Lekalo
 * has with `"adapters":[]`, which three PHPUnit contracts close by reading a
 * class AS TEXT (`ModelPayloadContractTest` is the pattern). This is that same
 * move on the openspec side.
 *
 * The scope is the CANONICAL specs, and that is the third state of one rule
 * (`.gitignore` records all three): `/openspec/` was ignored, then the whole
 * tree was tracked so this check could run in CI at all, and now `specs/` is
 * tracked while `openspec/changes/` — proposals, drafts, and the verbatim
 * `.ai-factory/plans/` migrations in its archive — is local again. A delta is
 * therefore not readable here BY DESIGN: its scenarios are counted when the
 * change lands in `specs/`, which is what keeps this a gate rather than a
 * report whose result depends on which draft the checkout happens to carry.
 *
 * ## The convention
 *
 * A scenario's bullet list carries one or more of:
 *
 *     - PINS: <repo-relative test file>::<method>
 *     - PINS: none — <reason>
 *
 * Measured 2026-10-27 before this file was written: a temporary change whose
 * scenario carried a `PINS:` bullet passed `openspec validate --strict` with
 * exit 0, so this is a bullet the openspec validator ignores rather than a
 * forked grammar or a sidecar file. The probe directory was deleted after the
 * measurement.
 *
 * ## Why there is no skip guard
 *
 * Everything this check reads is tracked — the canonical specs and the
 * workflow — so it reads the same thing in CI as on a workstation, and it
 * never calls `markTestSkipped()`. The shape `TaxonomyRegistryTest` needs for a
 * module that may not be installed does not apply to a file in git: a moved or
 * deleted spec tree is a walk that finds nothing, and the floor below turns
 * that into a red check with the count in its message. (While the whole tree
 * was gitignored, four of these rules could only skip in CI and the `modules`
 * job had to exclude them by group to keep its no-skips rule; the exclusion
 * and the group are both gone.)
 *
 * ## The ratchet
 *
 * 10 scenarios are unpinned today and that number is held EXACTLY: more
 * unpinned scenarios than the allowance fail, and fewer fail too, because
 * lowering it is part of the same change as the annotation that lowered it.
 * A new canonical scenario can therefore never hide under the allowance — it
 * arrives without a pin and the count goes over.
 *
 * The tenth arrived with `media-preview-fetch`: "One folder answers with an
 * error" is the non-200 filter inside `CloudinaryApi::getMany()`, and no CI
 * run can make Cloudinary answer anything, because the fetch builds its own
 * URLs from the account's cloud name. The glyph fallback that filter feeds is
 * pinned (`MediaFolderPreviewTest::aFolderWithoutAnImageHasNoPreview`), so the
 * decision is written on the scenario rather than faked with a test that
 * asserts the filter by reading it. The phases of that work are
 * recorded in the change (`openspec/changes/scenario-test-binding/tasks.md`,
 * local like every change) and end at 0.
 *
 * The allowance is a method rather than a class constant for the reason
 * `SlovakProseContractTest` records: `NameOwnershipContractTest` ratchets the
 * tree's `const` count, and a gate's own lookup table is not a name anybody
 * else owns.
 *
 * @internal
 *
 * @coversNothing
 */
final class ScenarioTestBindingContractTest extends TestCase
{
    /**
     * Every scenario is either pinned or inside the allowance, and the
     * allowance is exact.
     */
    #[Test]
    public function everyScenarioNamesATest(): void
    {
        $scenarios = self::scenarios(self::root());

        self::assertGreaterThanOrEqual(
            9,
            count($scenarios),
            'The spec walk found ' . count($scenarios) . ' scenarios — the roots moved, a spec file was'
            . ' deleted, or the parser stopped matching, and this check would pass by reading almost'
            . ' nothing.',
        );

        $unpinned = self::exemptions($scenarios);
        $count = count($unpinned);
        $allowance = self::exemptionAllowance();
        $drift = $count > $allowance
            ? sprintf(
                '%d more than the allowance: pin the new scenario with `- PINS: <file>::<method>`, or'
                . ' raise exemptionAllowance() in the same change as the reason.',
                $count - $allowance,
            )
            : sprintf(
                '%d fewer than the allowance: they got pinned, so lower exemptionAllowance() here —'
                . ' it is the ratchet, and this is the revision that moves it.',
                $allowance - $count,
            );

        self::assertSame(
            $allowance,
            $count,
            sprintf(
                'Scenarios that carry no resolving pin: %d, allowance: %d — %s First unpinned: %s',
                $count,
                $allowance,
                $drift,
                implode(' | ', array_slice(self::describe($unpinned), 0, 5)),
            ),
        );
    }

    /**
     * A pin is a test that exists. Anything else is a claim nobody checks.
     */
    #[Test]
    public function aPinResolvesToRealFileAndMethod(): void
    {
        $problems = [];

        foreach (self::scenarios(self::root()) as $scenario) {
            foreach ($scenario['pins'] as $pin) {
                if (null !== self::exemptionReason($pin)) {
                    continue;
                }

                foreach (self::pinProblems(self::root(), $pin) as $problem) {
                    $problems[] = sprintf('%s (%s) — "%s": %s', $scenario['scenario'], $scenario['file'], $pin, $problem);
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            "A pin must resolve to a file under tests/ or modules/*/tests/ and to a method in it.\n"
            . implode("\n", $problems),
        );

        self::assertNotSame(
            [],
            self::pinProblems(self::root(), 'src/Modules/Core/Infrastructure/Http/Request.php::bodyString'),
            'A pin outside the two test roots has to be refused — a production method is not a test,'
            . ' and accepting it would turn the binding into a comment that happens to compile.',
        );
        self::assertNotSame(
            [],
            self::pinProblems(self::root(), 'tests/Architecture/ScenarioTestBindingContractTest.php::aMethodThatDoesNotExist'),
            'A pin naming a method that is not in the file has to be refused — this is the shape a'
            . ' renamed or deleted test leaves behind.',
        );
    }

    /**
     * `PINS: none` is a decision with a written reason, and its count is part
     * of the ratchet rather than a way around it.
     */
    #[Test]
    public function anExemptionCarriesAReasonAndIsCounted(): void
    {
        self::assertNull(
            self::exemptionReason('modules/Validation/tests/ValidatorTest.php::emptyPasswordReportsOnce'),
            'A test reference is not an exemption.',
        );
        self::assertSame(
            '',
            self::exemptionReason('none'),
            'A bare `none` is a silence: the reason is what makes it a decision.',
        );
        self::assertSame(
            'not annotated yet — phase 1',
            self::exemptionReason('none — not annotated yet — phase 1'),
        );

        $scenarios = self::scenarios(self::root());
        $unreasoned = [];

        foreach ($scenarios as $scenario) {
            foreach ($scenario['pins'] as $pin) {
                if ('' === self::exemptionReason($pin)) {
                    $unreasoned[] = $scenario['file'] . ' — ' . $scenario['scenario'];
                }
            }
        }

        self::assertSame(
            [],
            $unreasoned,
            'These scenarios say `PINS: none` with no reason. Write the decision, or write the test:'
            . "\n" . implode("\n", $unreasoned),
        );

        // The allowance cannot hide a NEW scenario: one more unpinned scenario
        // pushes the count over, whatever headroom the phases left.
        $oneMore = [...$scenarios, [
            'file' => 'openspec/specs/example/spec.md',
            'scenario' => 'A scenario that arrived without a pin',
            'pins' => [],
        ]];

        self::assertGreaterThan(
            self::exemptionAllowance(),
            count(self::exemptions($oneMore)),
            'A new unpinned scenario has to fail on the count, so the allowance cannot be used as'
            . ' headroom for work that has not been done.',
        );
    }

    /**
     * The binding promised to cost no CI job. The job count is a ratchet, so
     * the promise is checkable rather than remembered.
     */
    #[Test]
    public function theBindingNeedsNoNewGate(): void
    {
        $workflow = (string) file_get_contents(self::root() . '/.github/workflows/ci.yml');
        $at = strpos($workflow, "\njobs:\n");

        self::assertNotFalse($at, 'The workflow no longer has a top-level `jobs:` block — this check reads it.');

        $matched = [];
        $jobs = preg_match_all('/^  [a-z][a-z0-9_-]*:$/m', substr($workflow, $at), $matched);

        self::assertIsInt($jobs, 'The job keys are not where this check looks for them.');
        self::assertSame(
            16,
            $jobs,
            'CI runs 16 jobs. The 16th is the optional ClickHouse smoke (`continue-on-error`), not'
            . ' a gate this binding rides in — it was added with its pin in `toolchain.json`. Grow the'
            . ' toolchain on purpose and raise this number in that change, not silently here.',
        );
    }

    /**
     * How many scenarios may stay unbound. Exact, and deliberately a method
     * (see the class docblock).
     */
    private static function exemptionAllowance(): int
    {
        return 10;
    }

    /**
     * @param list<array{file: string, scenario: string, pins: list<string>}> $scenarios
     *
     * @return list<string>
     */
    private static function describe(array $scenarios): array
    {
        return array_map(
            static fn (array $scenario): string => $scenario['file'] . ' — ' . $scenario['scenario'],
            $scenarios,
        );
    }

    /**
     * Every scenario in the canonical specs, with the `PINS:` lines it carries.
     *
     * @return list<array{file: string, scenario: string, pins: list<string>}>
     */
    private static function scenarios(string $root): array
    {
        $found = [];

        foreach (self::specFiles($root) as $path) {
            $relative = str_replace($root . '/', '', $path);
            $scenario = null;
            $pins = [];

            foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (1 === preg_match('/^#### Scenario:\s*(.+?)\s*$/u', $line, $matched)) {
                    if (null !== $scenario) {
                        $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
                    }

                    $scenario = $matched[1];
                    $pins = [];

                    continue;
                }

                if (null === $scenario) {
                    continue;
                }

                if (str_starts_with($line, '#')) {
                    $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
                    $scenario = null;
                    $pins = [];

                    continue;
                }

                if (1 === preg_match('/^\s*-\s*PINS:\s*(.+?)\s*$/u', $line, $matched)) {
                    $pins[] = $matched[1];
                }
            }

            if (null !== $scenario) {
                $found[] = ['file' => $relative, 'scenario' => $scenario, 'pins' => $pins];
            }
        }

        return $found;
    }

    /**
     * `null` when the value is a test reference; otherwise the reason — an
     * empty string when `none` is written without one.
     */
    private static function exemptionReason(string $value): ?string
    {
        if (1 !== preg_match('/^none\b(.*)$/us', trim($value), $matched)) {
            return null;
        }

        return 1 === preg_match('/^—\s*(.+)$/u', trim($matched[1]), $reason) ? trim($reason[1]) : '';
    }

    /**
     * Unpinned scenarios and explicit `none` scenarios — the ones the
     * allowance counts.
     *
     * @param list<array{file: string, scenario: string, pins: list<string>}> $scenarios
     *
     * @return list<array{file: string, scenario: string, pins: list<string>}>
     */
    private static function exemptions(array $scenarios): array
    {
        $exempt = [];

        foreach ($scenarios as $scenario) {
            if ([] === $scenario['pins']) {
                $exempt[] = $scenario;

                continue;
            }

            foreach ($scenario['pins'] as $pin) {
                if (null !== self::exemptionReason($pin)) {
                    $exempt[] = $scenario;

                    break;
                }
            }
        }

        return $exempt;
    }

    /**
     * Everything wrong with one pin, read as text. Empty means it resolves.
     *
     * @return list<string>
     */
    private static function pinProblems(string $root, string $pin): array
    {
        $at = strrpos($pin, '::');

        if (false === $at || '' === substr($pin, 0, $at) || '' === substr($pin, $at + 2)) {
            return ['not `<repo-relative test file>::<method>`'];
        }

        $path = substr($pin, 0, $at);
        $method = substr($pin, $at + 2);

        if (!str_starts_with($path, 'tests/') && 1 !== preg_match('#^modules/[^/]+/tests/#', $path)) {
            return [sprintf('"%s" is outside tests/ and modules/*/tests/ — a pin names a test', $path)];
        }

        if (!is_file($root . '/' . $path)) {
            return [sprintf('%s does not exist', $path)];
        }

        if (!str_contains((string) file_get_contents($root . '/' . $path), 'function ' . $method . '(')) {
            return [sprintf('%s has no function %s()', $path, $method)];
        }

        return [];
    }

    /**
     * The canonical specs — deliberately NOT the change deltas. Those are local
     * (`.gitignore`), and reading them would make this check's result depend on
     * the drafts a checkout happens to carry, which is the defect
     * `ModelCatalogueContractTest` was fixed for.
     *
     * @return list<string>
     */
    private static function specFiles(string $root): array
    {
        $files = glob($root . '/openspec/specs/*/spec.md') ?: [];

        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
