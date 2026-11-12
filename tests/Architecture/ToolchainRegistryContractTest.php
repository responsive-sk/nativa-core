<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\TestCase;

/**
 * ToolchainRegistryContractTest — the registry, against the files it describes.
 *
 * `bin/ops/toolchain-report.php` names this test as "the machine side of the
 * same file — it fails when the registry and the Makefile, the workflow or a
 * pin's own script drift apart in either direction", and `toolchain.json`'s
 * `why` says the gates it carries are the exported Makefile's targets. Neither
 * sentence was true of this tree (measured 2026-11-12): the test did not exist,
 * and `"ci_jobs": []` made `make gates` print "0 CI jobs" while the workflow ran
 * nine. Nothing would have noticed a target shipped without a registration, or
 * a job calling a recipe the export does not carry.
 *
 * Four relations, each a set equality or a derivation instead of a count, so a
 * failure prints the drift rather than a number:
 *
 *  1. the Makefile's documented targets == the registry's `gates[].target`;
 *  2. every `make <target>` in the workflow is one of those targets;
 *  3. the registry's `ci_jobs` == the workflow's jobs, and a job's `can_fail`
 *     is *derived* from the targets it runs — a job is a gate when it runs at
 *     least one gate. Writing that flag out a second time is how the two lists
 *     start disagreeing;
 *  4. every pin's needles resolve in the pin's own script: `version`, `target`
 *     and `sha256` rendered into the needle templates.
 *
 * Not asserted on purpose: the tier vocabulary. `toolchain-report.php` groups
 * the tiers in the registry's own order and spells none of its own; a test with
 * a hard-coded list would be the second copy that comment avoids.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolchainRegistryContractTest extends TestCase
{
    /**
     * Both lists describe the same targets, in both directions.
     *
     * A target in the Makefile and not in the registry is a gate nobody can
     * find from `make gates`; a registry row with no target is a question
     * nothing answers.
     */
    public function testTheRegistryAndTheMakefileCarryTheSameTargets(): void
    {
        $makefile = self::makefileTargets();
        $registry = self::registeredTargets();

        self::assertSame(
            self::sorted($makefile),
            self::sorted($registry),
            "The Makefile and toolchain.json disagree about which targets exist:\n"
            . '  only in Makefile: ' . implode(', ', array_diff($makefile, $registry)) . "\n"
            . '  only in registry: ' . implode(', ', array_diff($registry, $makefile)) . "\n\n"
            . 'Every documented target is registered with the question it answers; every row names a '
            . 'target the export ships.',
        );
    }

    /**
     * A row that says nothing is not a registration.
     *
     * `answers` is the reason the registry exists — `make gates` prints it
     * verbatim — and a duplicate target would print one question twice while
     * the second row's `can_fail` silently won.
     */
    public function testEveryGateSaysWhatItAnswers(): void
    {
        $problems = [];
        $seen = [];

        foreach (self::registry()['gates'] ?? [] as $gate) {
            $target = $gate['target'];

            if ('' === trim($target)) {
                $problems[] = 'a row has an empty target';
            } elseif (isset($seen[$target])) {
                $problems[] = $target . ' is registered twice';
            }

            $seen[$target] = true;

            if ('' === trim($gate['tier'])) {
                $problems[] = $target . ' declares no tier';
            }

            if ('' === trim($gate['answers'])) {
                $problems[] = $target . ' answers no question';
            }
        }

        self::assertSame(
            [],
            $problems,
            "toolchain.json has rows that are decoration:\n" . implode("\n", $problems),
        );
    }

    /**
     * A job may only call a recipe the export carries.
     *
     * The workflow's header states this as the exporter's assertion. The
     * exporter is not in this tree; this is the same sentence, checked on what
     * it left behind.
     */
    public function testEveryMakeCallInTheWorkflowIsAShippedTarget(): void
    {
        $shipped = self::makefileTargets();
        $called = self::workflowTargets();
        $unknown = array_values(array_diff($called, $shipped));

        self::assertNotEmpty($called, 'no `make <target>` was read from the workflow — the parser found nothing');
        self::assertSame(
            [],
            $unknown,
            'The workflow calls recipes this tree does not ship: ' . implode(', ', $unknown)
            . "\n\nA red gate on a fresh clone teaches a reader that the gates do not hold.",
        );
    }

    /**
     * The registry names every job, and each job's nature comes from its work.
     *
     * `make gates` prints `ci_jobs` as the CI side of the same list a reader
     * just saw; a job missing here is invisible from the registry, and a
     * `can_fail` that does not follow from the targets the job runs is the
     * duplicated flag this test exists to replace.
     */
    public function testTheRegistryNamesEveryWorkflowJob(): void
    {
        $jobs = self::workflowJobs();
        $declared = [];

        foreach (self::registry()['ci_jobs'] ?? [] as $job) {
            $declared[$job['key']] = $job;
        }

        $problems = [];

        foreach ($jobs as $key => $job) {
            if (!isset($declared[$key])) {
                $problems[] = sprintf('JOB NOT REGISTERED  %s (%s)', $key, $job['name']);

                continue;
            }

            $row = $declared[$key];

            if ($job['name'] !== $row['name']) {
                $problems[] = sprintf(
                    'NAME DRIFT  %s: workflow says "%s", registry says "%s"',
                    $key,
                    $job['name'],
                    $row['name'],
                );
            }

            if ([] === $job['make']) {
                $problems[] = $key . ' runs no make target — the registry cannot say what it is';
            }

            $derived = self::jobRunsAGate($job['make'], self::gateCanFail());

            if ($derived !== $row['can_fail']) {
                $problems[] = sprintf(
                    'CAN_FAIL  %s is declared %s but %s a gate target (%s)',
                    $key,
                    $row['can_fail'] ? 'true' : 'false',
                    $derived ? 'runs' : 'runs no',
                    implode(', ', $job['make']),
                );
            }
        }

        foreach (array_diff_key($declared, $jobs) as $key => $row) {
            $problems[] = sprintf('STALE JOB  %s is registered but no such job exists', $key);
        }

        self::assertSame(
            [],
            $problems,
            "toolchain.json's ci_jobs drifted from the workflow:\n" . implode("\n", $problems)
            . "\n\nRegistry: " . \count($declared) . ' jobs · workflow: ' . \count($jobs) . ' jobs',
        );
    }

    /**
     * A pin resolves in the script that installs it.
     *
     * `toolchain.json` keeps a tool only when its pin resolves in this tree, and
     * each pin carries needles: the templates that must appear in the script.
     * A version bumped in the registry and not in `mago-spike.sh` — or the
     * other way — is two pins, one of which is a reading nobody compared.
     */
    public function testEveryPinResolvesInItsOwnScript(): void
    {
        $problems = [];

        foreach (self::registry()['tools'] ?? [] as $tool) {
            $name = $tool['name'];

            if (1 !== preg_match('/^[0-9a-f]{64}$/', $tool['sha256'])) {
                $problems[] = $name . ': sha256 is not 64 lowercase hex characters';
            }

            $files = $tool['needles']['files'] ?? [];
            $needles = $tool['needles']['strings'] ?? [];

            if ([] === $files || [] === $needles) {
                $problems[] = $name . ': no needles, so nothing ties the pin to the script that installs it';

                continue;
            }

            foreach ($files as $file) {
                $path = AppPaths::instance()->join($file);
                $contents = is_file($path) ? (string) file_get_contents($path) : null;

                if (null === $contents) {
                    $problems[] = $name . ' names ' . $file . ', which does not exist';

                    continue;
                }

                foreach ($needles as $needle) {
                    $rendered = str_replace(
                        ['{{version}}', '{{target}}', '{{sha256}}'],
                        [$tool['version'], $tool['target'], $tool['sha256']],
                        $needle,
                    );

                    if (!str_contains($contents, $rendered)) {
                        $problems[] = sprintf('%s: %s does not contain `%s`', $name, $file, $rendered);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            "A pin in toolchain.json does not resolve in this tree:\n" . implode("\n", $problems),
        );
    }

    /**
     * The parsers must read the files they judge.
     *
     * Every rule above compares sets produced by line scanners. A renamed key or
     * a moved block leaves both sides empty and the comparison true — the guard
     * would assert nothing while the suite stayed green.
     */
    public function testTheParsersFindTheFiles(): void
    {
        self::assertGreaterThanOrEqual(15, \count(self::makefileTargets()), 'Makefile targets were not read');
        self::assertGreaterThanOrEqual(15, \count(self::registeredTargets()), 'toolchain.json gates were not read');
        self::assertGreaterThanOrEqual(8, \count(self::workflowJobs()), 'workflow jobs were not read');
        self::assertGreaterThanOrEqual(8, \count(self::workflowTargets()), 'workflow make calls were not read');
        self::assertNotEmpty(self::registry()['tools'] ?? [], 'toolchain.json tools were not read');
    }

    /**
     * Targets the Makefile documents (`##` is `make help`'s only input).
     *
     * @return list<string>
     */
    private static function makefileTargets(): array
    {
        $targets = [];

        foreach (self::lines('Makefile') as $line) {
            if (1 === preg_match('/^([a-z][a-z0-9-]*):[^=]*##/', $line, $match)) {
                $targets[] = $match[1];
            }
        }

        return $targets;
    }

    /**
     * `target => can_fail` for every registered gate.
     *
     * @return array<string, bool>
     */
    private static function gateCanFail(): array
    {
        $map = [];

        foreach (self::registry()['gates'] ?? [] as $gate) {
            $map[$gate['target']] = $gate['can_fail'];
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function registeredTargets(): array
    {
        return array_keys(self::gateCanFail());
    }

    /**
     * Every `make <target>` the workflow invokes, on any line.
     *
     * @return list<string>
     */
    private static function workflowTargets(): array
    {
        $targets = [];

        foreach (self::lines('.github/workflows/ci.yml') as $line) {
            if (preg_match_all('/\bmake\s+([a-z][a-z0-9-]*)/', $line, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $target) {
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * The workflow's jobs: key, display name, and the targets each runs.
     *
     * A line scanner rather than a YAML reader: the workflow has one `jobs:`
     * block, job keys sit at two spaces, `name` at four, and everything deeper
     * belongs to steps. A shape change fails the sanity test loudly instead of
     * being absorbed by a dependency the kernel does not carry.
     *
     * @return array<string, array{name: string, make: list<string>}>
     */
    private static function workflowJobs(): array
    {
        $names = [];
        $makes = [];
        $inside = false;
        $current = null;

        foreach (self::lines('.github/workflows/ci.yml') as $line) {
            if (!$inside) {
                $inside = 1 === preg_match('/^jobs:\s*$/', $line);

                continue;
            }

            if (1 === preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match)) {
                $current = $match[1];
                $names[$current] = $current;
                $makes[$current] = [];

                continue;
            }

            if (1 === preg_match('/^[a-z]/', $line)) {
                break; // the next top-level key ends the block
            }

            if (null === $current) {
                continue;
            }

            if (1 === preg_match('/^    name:\s*(.+?)\s*$/', $line, $match)) {
                $names[$current] = trim($match[1], '"\'');
            }

            if (preg_match_all('/\bmake\s+([a-z][a-z0-9-]*)/', $line, $matches) > 0) {
                foreach ($matches[1] as $target) {
                    $makes[$current][] = $target;
                }
            }
        }

        $jobs = [];

        foreach ($names as $key => $name) {
            $jobs[$key] = ['name' => $name, 'make' => $makes[$key] ?? []];
        }

        return $jobs;
    }

    /**
     * Whether a job's targets include at least one gate.
     *
     * @param list<string>        $targets
     * @param array<string, bool> $canFail
     */
    private static function jobRunsAGate(array $targets, array $canFail): bool
    {
        foreach ($targets as $target) {
            if (true === ($canFail[$target] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * A tracked file's lines, or none if it is absent.
     *
     * @return list<string>
     */
    private static function lines(string $file): array
    {
        $path = AppPaths::instance()->join($file);
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

        return false === $lines ? [] : $lines;
    }

    /**
     * The registry as data.
     *
     * @return array{
     *     why?: string,
     *     tools?: list<array{name: string, version: string, target: string, sha256: string, needles?: array{files?: list<string>, strings?: list<string>}}>,
     *     gates?: list<array{target: string, tier: string, can_fail: bool, answers: string}>,
     *     ci_jobs?: list<array{key: string, name: string, can_fail: bool}>
     * }
     */
    private static function registry(): array
    {
        $raw = json_decode((string) file_get_contents(AppPaths::instance()->join('toolchain.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw, 'toolchain.json is not a JSON object');

        /** @var array{
         *     why?: string,
         *     tools?: list<array{name: string, version: string, target: string, sha256: string, needles?: array{files?: list<string>, strings?: list<string>}}>,
         *     gates?: list<array{target: string, tier: string, can_fail: bool, answers: string}>,
         *     ci_jobs?: list<array{key: string, name: string, can_fail: bool}>
         * } $raw
         */
        return $raw;
    }
}
