#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * toolchain-report.php — the toolchain registry, printed for a human.
 *
 * `toolchain.json` is the one place that says what this tree is checked with:
 * the tool pins, every make target with the question it answers and whether it
 * can fail, and every CI job. `tests/Architecture/ToolchainRegistryContractTest`
 * is the machine side of the same file — it fails when the registry and the
 * Makefile, the workflow or a pin's own script drift apart in either direction.
 *
 * This script is the reading side, and it is deliberately dumb: it prints the
 * registry and computes nothing of its own, so the list a reader sees and the
 * list the test enforces cannot disagree. Exit status is always 0 — a registry
 * that is wrong is the test's verdict, not this command's.
 *
 * Usage: make gates   (or: php bin/ops/toolchain-report.php)
 */

$root = dirname(__DIR__, 2);
$path = $root . '/toolchain.json';

if (!is_file($path)) {
    fwrite(STDERR, "toolchain.json is missing at {$path}\n");

    exit(1);
}

/** @var array{why?: string, tools?: list<array{name: string, version: string, target: string, sha256: string}>, gates?: list<array{target: string, tier: string, can_fail: bool, answers: string}>, ci_jobs?: list<array{key: string, name: string, can_fail: bool}>} $registry */
$registry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$gates = $registry['gates'] ?? [];
$tools = $registry['tools'] ?? [];
$jobs = $registry['ci_jobs'] ?? [];

$failing = count(array_filter($gates, static fn (array $gate): bool => true === $gate['can_fail']));

printf(
    "Toolchain registry — %d tools, %d make targets (%d of them can fail), %d CI jobs.\n",
    count($tools),
    count($gates),
    $failing,
    count($jobs),
);

foreach ($tools as $tool) {
    printf(
        "\ntool %s: %s (%s), sha256 %s…\n",
        $tool['name'],
        $tool['version'],
        $tool['target'],
        substr($tool['sha256'], 0, 12),
    );
}

print "\nGATES\n";
printf("%-18s %-10s %-7s %s\n", 'target', 'tier', 'fails', 'answers');

// Grouped by the order the registry itself lists the tiers, so the reading
// order is the registry's order and this script spells no vocabulary of its
// own — a hand-written tier list here would be one more copy to keep in sync.
$grouped = [];

foreach ($gates as $gate) {
    $grouped[$gate['tier']][] = $gate;
}

foreach ($grouped as $rows) {
    foreach ($rows as $gate) {
        printf(
            "%-18s %-10s %-7s %s\n",
            $gate['target'],
            $gate['tier'],
            true === $gate['can_fail'] ? 'yes' : 'no',
            $gate['answers'],
        );
    }
}

print "\nCI JOBS\n";

foreach ($jobs as $job) {
    printf("%-18s %-6s %s\n", $job['key'], true === $job['can_fail'] ? 'gate' : 'report', $job['name']);
}
