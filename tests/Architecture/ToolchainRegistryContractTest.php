<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ToolchainRegistryContractTest — `toolchain.json` is the one list, and this is
 * the side that fails when it stops being true.
 *
 * Measured 2026-10-27, before this test: the tool pins and the gate list lived
 * on four sides of the repository. `1.58.2` was a constant in
 * `bin/build/mago-spike.sh` AND the cache key in three CI jobs; `v0.6.5` was in
 * the workflow and in a script comment; and the list of gates existed in the
 * workflow's header comment, `CONTRIBUTING.md`, the Makefile's `##@` blocks and
 * two documents under `docs/`. A version bumped in one place and not the other
 * produces two differently pinned tools in one run, and nothing said so.
 *
 * The registry fixes the ownership, not the duplication: `ci.yml` still spells
 * its cache keys, and this test is why that spelling cannot rot. Every rule
 * below is bidirectional — a fact missing from the registry fails, and a fact
 * in the registry that no longer exists fails too — because a list that only
 * grows is how a gate list becomes a lie.
 *
 * What it reads, and what it deliberately does not:
 *
 * - the Makefile's `target: ## help` lines, so a new target is a decision
 *   (register it, and say whether it can fail) rather than an addition;
 * - the workflow's top-level job keys, so a new CI job cannot arrive silently;
 * - the plain `make <target>` spelling inside a job, so a gate recorded as
 *   running in CI has to actually run there. YAML comments are stripped first,
 *   so commenting a gate out counts as not running it. A `$(MAKE)` or a script
 *   invocation is NOT seen — the workflow uses the plain spelling, and this
 *   note is the limitation rather than a claim of coverage;
 * - each tool's pin needles, substituted from the registry's own version,
 *   target and checksum, so the value is asserted where it is used instead of
 *   being copied into a second list.
 *
 * @internal
 *
 * @coversNothing
 */
final class ToolchainRegistryContractTest extends TestCase
{
    /**
     * The registry's own shape, so a typo in it fails as a typo rather than as
     * a confusing diff somewhere else.
     */
    #[Test]
    public function theRegistryIsWellFormed(): void
    {
        $registry = self::registry();
        $tiers = array_values(array_unique(array_column($registry['gates'], 'tier')));
        sort($tiers);

        // The vocabulary is a ratchet, not a list spelled out here: a typo in a
        // tier adds a sixth word and fails, and nothing in this file has to
        // repeat a name that belongs to the registry. (`kernel` is a theme name
        // owned by `ThemeResolver`, which is what a spelled-out list would
        // collide with — NameOwnershipContractTest's literal ceiling counts it.)
        self::assertCount(
            5,
            $tiers,
            sprintf(
                'The registry uses %d tiers: %s. Five is the vocabulary — a sixth word is either a'
                . ' typo in a `tier` or a new layer that needs a decision, and both should fail here.',
                count($tiers),
                implode(', ', $tiers),
            ),
        );

        foreach ($tiers as $tier) {
            self::assertNotSame(
                [],
                array_filter($registry['gates'], static fn (array $gate): bool => $tier === $gate['tier']),
                sprintf('No gate is registered under tier "%s" — an empty tier is a leftover name.', $tier),
            );
        }

        self::assertSame(
            count($registry['gates']),
            count(array_unique(array_column($registry['gates'], 'target'))),
            'A make target registered twice would let one of the two rows drift unnoticed.',
        );
        self::assertSame(
            count($registry['ci_jobs']),
            count(array_unique(array_column($registry['ci_jobs'], 'key'))),
            'A CI job registered twice would let one of the two rows drift unnoticed.',
        );
        self::assertSame(
            count($registry['tools']),
            count(array_unique(array_column($registry['tools'], 'name'))),
            'A tool registered twice would let one of the two pins drift unnoticed.',
        );

        foreach ($registry['tools'] as $tool) {
            self::assertSame(
                1,
                preg_match('/^[0-9a-f]{64}$/', $tool['sha256']),
                sprintf('The sha256 for %s is not 64 lowercase hex characters.', $tool['name']),
            );
        }

        self::assertNotSame(
            [],
            array_filter($registry['gates'], static fn (array $gate): bool => true === $gate['can_fail']),
            'A registry in which nothing can fail has stopped describing this tree.',
        );
    }

    /**
     * Every `target: ## help` in the Makefile is registered, and every
     * registered target exists in the Makefile.
     */
    #[Test]
    public function everyMakeTargetIsRegisteredOnce(): void
    {
        $declared = self::makeTargets();
        $registered = array_column(self::registry()['gates'], 'target');
        sort($registered);

        self::assertSame(
            $declared,
            $registered,
            sprintf(
                "The Makefile and toolchain.json disagree about the targets.\n"
                . '  in the Makefile, not registered: %s' . "\n"
                . '  registered, not in the Makefile: %s' . "\n"
                . 'A new target is a decision: add it to the registry with the question it answers and'
                . ' whether it can fail, or say why it is not a gate.',
                [] === array_diff($declared, $registered) ? '(none)' : implode(', ', array_diff($declared, $registered)),
                [] === array_diff($registered, $declared) ? '(none)' : implode(', ', array_diff($registered, $declared)),
            ),
        );

        self::assertGreaterThanOrEqual(
            25,
            count($declared),
            'The Makefile walk found ' . count($declared) . ' targets — the help-comment shape moved and'
            . ' this check would pass by reading almost nothing.',
        );
    }

    /**
     * Every CI job is in the registry and every registered job exists.
     */
    #[Test]
    public function everyCiJobIsRegistered(): void
    {
        $inWorkflow = self::ciJobs();
        $registered = array_column(self::registry()['ci_jobs'], 'key');
        sort($registered);

        self::assertSame(
            $inWorkflow,
            $registered,
            sprintf(
                "The workflow and toolchain.json disagree about the jobs.\n"
                . '  in the workflow, not registered: %s' . "\n"
                . '  registered, not in the workflow: %s' . "\n"
                . 'Register the job and say whether it can fail a pull request. A gate that arrives'
                . ' unregistered is the one this file exists to catch.',
                [] === array_diff($inWorkflow, $registered) ? '(none)' : implode(', ', array_diff($inWorkflow, $registered)),
                [] === array_diff($registered, $inWorkflow) ? '(none)' : implode(', ', array_diff($registered, $inWorkflow)),
            ),
        );
    }

    /**
     * A gate recorded as running in CI has to run there, and a `make` call in
     * the workflow has to be recorded. One direction catches a promise nobody
     * keeps; the other catches a gate that arrived without a line in the
     * registry.
     */
    #[Test]
    public function theWorkflowRunsTheTargetsTheRegistryRecords(): void
    {
        $found = self::workflowMakeCalls();
        $recorded = [];

        foreach (self::registry()['gates'] as $gate) {
            foreach ($gate['ci'] as $job) {
                $recorded[$job['job']][] = $gate['target'];
            }
        }

        self::assertGreaterThanOrEqual(
            8,
            count(self::flatten($found)),
            'The workflow scan found ' . count(self::flatten($found)) . ' `make` calls — the spelling'
            . ' moved and this check would compare two empty lists.',
        );

        self::assertSame(
            self::normalise($recorded),
            self::normalise($found),
            "The workflow's `make` calls and the registry's `ci` entries disagree.\n"
            . '  recorded: ' . json_encode(self::normalise($recorded)) . "\n"
            . '  in the workflow: ' . json_encode(self::normalise($found)) . "\n"
            . 'A gate that is no longer wired to a job is a gate people believe is running.',
        );
    }

    /**
     * Every pin resolves where the tool is actually used.
     */
    #[Test]
    public function everyPinMatchesTheFileItPins(): void
    {
        foreach (self::registry()['tools'] as $tool) {
            self::assertNotSame(
                [],
                array_filter(
                    $tool['needles']['strings'],
                    static fn (string $needle): bool => str_contains($needle, '{{'),
                ),
                sprintf(
                    'The needles for %s are all literals, so they cannot drift with the registry value'
                    . ' and this rule measures nothing. Use {{version}}, {{target}} or {{sha256}}.',
                    $tool['name'],
                ),
            );

            foreach ($tool['needles']['strings'] as $needle) {
                $expected = str_replace(
                    ['{{version}}', '{{target}}', '{{sha256}}'],
                    [$tool['version'], $tool['target'], $tool['sha256']],
                    $needle,
                );

                $foundIn = array_values(array_filter(
                    $tool['needles']['files'],
                    static fn (string $file): bool => str_contains(
                        (string) @file_get_contents(self::root() . '/' . $file),
                        $expected,
                    ),
                ));

                self::assertNotSame(
                    [],
                    $foundIn,
                    sprintf(
                        '%s:\n  %s contains "%s" in none of: %s\nBump the registry OR the file, never'
                        . ' one of the two: two differently pinned tools in one run is the defect this'
                        . ' registry exists to stop.',
                        $tool['name'],
                        'expected the literal',
                        $expected,
                        implode(', ', $tool['needles']['files']),
                    ),
                );
            }

            if (null === $tool['cache']) {
                continue;
            }

            $cache = $tool['cache'];
            $keys = self::cacheKeys($cache['path'], $cache['key_prefix']);

            self::assertNotSame(
                [],
                $keys,
                sprintf('No `key: %s-…` lines in %s — either the cache went away or the prefix moved.', $cache['key_prefix'], $cache['path']),
            );

            foreach ($keys as $key) {
                self::assertSame(
                    sprintf('%s-%s-%s', $cache['key_prefix'], $tool['version'], $tool['target']),
                    $key,
                    sprintf(
                        'A cache key in %s names a different %s build than the registry does. The key'
                        . ' is how the workflow finds the binary it downloaded, so a stale one keeps a'
                        . ' pin alive that the registry has already moved past.',
                        $cache['path'],
                        $tool['name'],
                    ),
                );
            }
        }
    }

    /**
     * The registry, typed and validated. A malformed file fails here with the
     * key that is wrong rather than somewhere downstream with a notice.
     *
     * @return array{
     *     why: string,
     *     tools: list<array{
     *         name: string,
     *         version: string,
     *         target: string,
     *         sha256: string,
     *         needles: array{files: list<string>, strings: list<string>},
     *         cache: array{path: string, key_prefix: string}|null
     *     }>,
     *     gates: list<array{
     *         target: string,
     *         tier: string,
     *         can_fail: bool,
     *         answers: string,
     *         ci: list<array{job: string, note: string}>
     *     }>,
     *     ci_jobs: list<array{key: string, name: string, can_fail: bool}>
     * }
     */
    private static function registry(): array
    {
        $path = self::root() . '/toolchain.json';

        self::assertFileExists($path, 'toolchain.json is the registry this test exists for.');

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('toolchain.json is not a JSON object.');
        }

        self::assertSame(1, self::integer($decoded, 'schema_version', 'root'), 'toolchain.json has an unknown schema_version.');

        $tools = [];
        $gates = [];
        $jobs = [];

        foreach (self::listOf($decoded, 'tools') as $tool) {
            if (!is_array($tool)) {
                self::fail('tools: every entry has to be an object.');
            }

            $needles = $tool['needles'] ?? null;

            if (!is_array($needles)) {
                self::fail(sprintf('tools[%s]: needles has to be an object.', self::string($tool, 'name', 'tools')));
            }

            $cache = $tool['cache'] ?? null;

            if (null !== $cache && !is_array($cache)) {
                self::fail(sprintf('tools[%s]: cache has to be an object or absent.', self::string($tool, 'name', 'tools')));
            }

            $tools[] = [
                'name' => self::string($tool, 'name', 'tools'),
                'version' => self::string($tool, 'version', 'tools'),
                'target' => self::string($tool, 'target', 'tools'),
                'sha256' => self::string($tool, 'sha256', 'tools'),
                'needles' => [
                    'files' => self::listOfStrings($needles, 'files', 'tools[].needles'),
                    'strings' => self::listOfStrings($needles, 'strings', 'tools[].needles'),
                ],
                'cache' => null === $cache
                    ? null
                    : ['path' => self::string($cache, 'path', 'tools[].cache'), 'key_prefix' => self::string($cache, 'key_prefix', 'tools[].cache')],
            ];
        }

        foreach (self::listOf($decoded, 'gates') as $gate) {
            if (!is_array($gate)) {
                self::fail('gates: every entry has to be an object.');
            }

            $ci = [];
            $entries = $gate['ci'] ?? [];

            if (!is_array($entries)) {
                self::fail(sprintf('gates[%s]: "ci" has to be a list when it is there at all.', self::string($gate, 'target', 'gates')));
            }

            foreach (array_values($entries) as $entry) {
                if (!is_array($entry)) {
                    self::fail(sprintf('gates[%s]: every ci entry has to be an object.', self::string($gate, 'target', 'gates')));
                }

                $ci[] = ['job' => self::string($entry, 'job', 'gates[].ci'), 'note' => self::string($entry, 'note', 'gates[].ci')];
            }

            $gates[] = [
                'target' => self::string($gate, 'target', 'gates'),
                'tier' => self::string($gate, 'tier', 'gates'),
                'can_fail' => self::boolean($gate, 'can_fail', 'gates'),
                'answers' => self::string($gate, 'answers', 'gates'),
                'ci' => $ci,
            ];
        }

        foreach (self::listOf($decoded, 'ci_jobs') as $job) {
            if (!is_array($job)) {
                self::fail('ci_jobs: every entry has to be an object.');
            }

            $jobs[] = [
                'key' => self::string($job, 'key', 'ci_jobs'),
                'name' => self::string($job, 'name', 'ci_jobs'),
                'can_fail' => self::boolean($job, 'can_fail', 'ci_jobs'),
            ];
        }

        return ['why' => self::string($decoded, 'why', 'root'), 'tools' => $tools, 'gates' => $gates, 'ci_jobs' => $jobs];
    }

    /**
     * Every `target: ## help` in the Makefile, sorted.
     *
     * @return list<string>
     */
    private static function makeTargets(): array
    {
        $targets = preg_match_all(
            '/^([a-z][a-z0-9-]*):.*##/m',
            (string) file_get_contents(self::root() . '/Makefile'),
            $matched,
        );

        self::assertIsInt($targets, 'The Makefile is unreadable.');
        sort($matched[1]);

        /** @var list<string> $names */
        $names = $matched[1];

        return $names;
    }

    /**
     * The workflow's top-level job keys, sorted.
     *
     * @return list<string>
     */
    private static function ciJobs(): array
    {
        $keys = [];
        $jobs = false;

        foreach (explode("\n", self::workflow()) as $line) {
            if ('jobs:' === $line) {
                $jobs = true;

                continue;
            }

            if (false === $jobs) {
                continue;
            }

            if (1 === preg_match('/^  ([a-z][a-z0-9_-]*):$/', $line, $matched)) {
                $keys[] = $matched[1];
            }
        }

        self::assertNotSame([], $keys, 'The workflow has no top-level jobs: block.');
        sort($keys);

        return $keys;
    }

    /**
     * `job => [target, …]` for the plain `make <target>` spelling.
     *
     * @return array<string, list<string>>
     */
    private static function workflowMakeCalls(): array
    {
        $calls = [];
        $job = null;

        foreach (explode("\n", self::workflow()) as $line) {
            if (1 === preg_match('/^  ([a-z][a-z0-9_-]*):$/', $line, $matched)) {
                $job = $matched[1];
            }

            if (null === $job) {
                continue;
            }

            // A commented-out call is not a call: the gate is no longer wired
            // to the job, and that is exactly what this rule is for.
            $at = strpos($line, '#');

            if (false !== $at) {
                $line = substr($line, 0, $at);
            }

            preg_match_all('/(?:^|[\s;&|(])make\s+([a-z][a-z0-9-]*)/', $line, $matched);

            foreach ($matched[1] as $target) {
                $calls[$job][] = $target;
            }
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private static function cacheKeys(string $path, string $prefix): array
    {
        preg_match_all(
            sprintf('/^\s*key:\s*(%s-[A-Za-z0-9._-]+)\s*$/m', preg_quote($prefix, '/')),
            (string) file_get_contents(self::root() . '/' . $path),
            $matched,
        );

        /** @var list<string> $keys */
        $keys = $matched[1];

        return $keys;
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(self::root() . '/.github/workflows/ci.yml');
    }

    /**
     * @param array<string, list<string>> $calls
     *
     * @return array<string, list<string>>
     */
    private static function normalise(array $calls): array
    {
        ksort($calls);

        return array_map(
            static function (array $targets): array {
                $targets = array_values(array_unique($targets));
                sort($targets);

                return $targets;
            },
            $calls,
        );
    }

    /**
     * @param array<string, list<string>> $calls
     *
     * @return list<string>
     */
    private static function flatten(array $calls): array
    {
        $target = [];

        foreach ($calls as $targets) {
            foreach ($targets as $name) {
                $target[] = $name;
            }
        }

        return $target;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            self::fail(sprintf('%s: "%s" has to be a non-empty string.', $where, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolean(array $data, string $key, string $where): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            self::fail(sprintf('%s: "%s" has to be true or false.', $where, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function integer(array $data, string $key, string $where): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value)) {
            self::fail(sprintf('%s: "%s" has to be an integer.', $where, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    private static function listOf(array $data, string $key, string $where = 'root'): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || [] === $value) {
            self::fail(sprintf('%s: "%s" has to be a non-empty list.', $where, $key));
        }

        return array_values($value);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function listOfStrings(array $data, string $key, string $where): array
    {
        $strings = [];

        foreach (self::listOf($data, $key, $where) as $entry) {
            if (!is_string($entry) || '' === $entry) {
                self::fail(sprintf('%s: "%s" has to hold non-empty strings.', $where, $key));
            }

            $strings[] = $entry;
        }

        return $strings;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
