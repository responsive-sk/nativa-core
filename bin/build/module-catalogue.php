#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * module-catalogue.php — write or check `modules/catalogue.json`.
 *
 * The directory tree is still the catalogue: a module is a directory under
 * `modules/<Name>/` (or `modules/Themes/<Name>/`), and `module:add` copies it.
 * This file is the machine-readable form of that tree for a consumer that
 * should not have to scan every PHP file: name, source path and the direct
 * dependency closure. Later it is what a release registry can serve.
 *
 * Generated, never edited. `--check` is the gate; `--write` is how a module or
 * an import changes it. The output is deterministic — sorted module names,
 * sorted dependencies, no timestamp — so a no-op run is a no-op diff.
 *
 * The dependency rule is deliberately strict: every scanned or declared
 * dependency must also be catalogued (kernel modules are the exception). A
 * catalogue entry that imports a module nobody can install is incomplete, and
 * this is the earliest place to say so.
 *
 * Usage:
 *   php bin/build/module-catalogue.php --check [--root=<dir>]
 *   php bin/build/module-catalogue.php --write [--root=<dir>]
 */

use Modules\Tooling\ModuleCatalog;

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, 'module-catalogue: ' . $message . PHP_EOL);
    exit(1);
}

function usage(): void
{
    fwrite(STDOUT, <<<'TEXT'
Usage: php bin/build/module-catalogue.php --check [--root=<dir>]
       php bin/build/module-catalogue.php --write [--root=<dir>]

  --check   exit 1 when modules/catalogue.json is missing or stale
  --write   regenerate modules/catalogue.json
  --root=   tree to read (default: the repository containing this script)

TEXT);
}

/**
 * @param list<string> $argv
 *
 * @return array{mode: string, root: string}
 */
function options(array $argv): array
{
    $mode = 'check';
    $modeSet = false;
    $root = dirname(__DIR__, 2);

    foreach ($argv as $argument) {
        if ('--help' === $argument || '-h' === $argument) {
            usage();
            exit(0);
        }

        if ('--write' === $argument || '--check' === $argument) {
            $next = '--write' === $argument ? 'write' : 'check';

            if ($modeSet && $mode !== $next) {
                fail('choose either --write or --check, not both');
            }

            $mode = $next;
            $modeSet = true;

            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));

            if ('' === $root) {
                fail('--root= needs a directory');
            }

            continue;
        }

        fail(sprintf('unknown argument: %s (see --help)', $argument));
    }

    return ['mode' => $mode, 'root' => $root];
}

/**
 * @throws \RuntimeException when a module cannot be described or its dependency
 *                           is not catalogued
 *
 * @return array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>}
 */
function buildIndex(ModuleCatalog $catalog, string $root): array
{
    $known = array_flip($catalog->catalogued());
    $modules = [];

    foreach ($catalog->catalogued() as $name) {
        $manifest = $catalog->manifest($name);
        $requires = [];

        foreach ($manifest->closure() as $dependency) {
            if ($dependency === $name || in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)) {
                continue;
            }

            if (!isset($known[$dependency])) {
                throw new \RuntimeException(sprintf(
                    "Module '%s' requires '%s', which is not catalogued. Add it to modules/ or remove the import.",
                    $name,
                    $dependency,
                ));
            }

            $requires[$dependency] = true;
        }

        $requires = array_keys($requires);
        sort($requires);

        $source = $catalog->cataloguePath($name);

        if (null === $source) {
            throw new \RuntimeException(sprintf("Module '%s' has no catalogue path.", $name));
        }

        $relative = relativePath($root, $source);

        $modules[$name] = [
            'source' => $relative,
            'requires' => $requires,
        ];
    }

    ksort($modules);

    return [
        'schema_version' => 1,
        'modules' => $modules,
    ];
}

/**
 * A catalogue path as the index records it: root-relative, slash-separated,
 * and with no trailing slash. The path is read from the catalogue, not built
 * from the name, so a grouped module keeps its group.
 */
function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);

    if (!str_starts_with($path, $root)) {
        fail(sprintf('%s is outside the catalogue root %s', $path, $root));
    }

    return substr($path, strlen($root));
}

/**
 * @param array{schema_version: int, modules: array<string, array{source: string, requires: list<string>}>} $index
 */
function encodeIndex(array $index): string
{
    $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (false === $json) {
        fail('the index could not be encoded as JSON');
    }

    return $json . PHP_EOL;
}

/**
 * The human half of a stale index: which names were added, removed, or changed
 * in a way that would make a consumer read the wrong graph.
 *
 * @param array<string, array{source: string, requires: list<string>}> $expected
 * @param mixed                                                        $current
 */
function describeDrift(array $expected, mixed $current): string
{
    if (!is_array($current) || !isset($current['modules']) || !is_array($current['modules'])) {
        return 'the file is not a catalogue index';
    }

    $currentModules = $current['modules'];
    $expectedNames = array_keys($expected);
    $currentNames = array_keys($currentModules);

    $missing = array_values(array_diff($expectedNames, $currentNames));
    $extra = array_values(array_diff($currentNames, $expectedNames));
    $changed = [];

    foreach (array_intersect($expectedNames, $currentNames) as $name) {
        if (($currentModules[$name] ?? null) !== $expected[$name]) {
            $changed[] = $name;
        }
    }

    $parts = [];

    if ([] !== $missing) {
        $parts[] = 'missing: ' . implode(', ', $missing);
    }

    if ([] !== $extra) {
        $parts[] = 'extra: ' . implode(', ', $extra);
    }

    if ([] !== $changed) {
        $parts[] = 'changed: ' . implode(', ', $changed);
    }

    return [] === $parts ? 'the file differs from the generated index' : implode('; ', $parts);
}

$options = options(array_slice($argv, 1));
$root = realpath($options['root']);

if (false === $root) {
    fail(sprintf('root does not exist: %s', $options['root']));
}

if (!is_dir($root . '/modules')) {
    fail(sprintf('no modules/ catalogue under %s', $root));
}

require_once dirname(__DIR__, 2) . '/src/init.php';

$catalog = new ModuleCatalog($root . '/modules', $root . '/src/Modules');

try {
    $index = buildIndex($catalog, $root);
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}

$json = encodeIndex($index);
$path = $root . '/modules/catalogue.json';
$count = count($index['modules']);

if ('write' === $options['mode']) {
    if (false === @file_put_contents($path, $json)) {
        fail(sprintf('cannot write %s', $path));
    }

    printf('Wrote modules/catalogue.json (%d module%s).%s', $count, 1 === $count ? '' : 's', PHP_EOL);

    exit(0);
}

$current = @file_get_contents($path);

if (false === $current) {
    fail(sprintf(
        'modules/catalogue.json is missing (%d module%s expected). Run: php bin/build/module-catalogue.php --write',
        $count,
        1 === $count ? '' : 's',
    ));
}

if ($current === $json) {
    printf('modules/catalogue.json is up to date (%d module%s).%s', $count, 1 === $count ? '' : 's', PHP_EOL);

    exit(0);
}

$decoded = json_decode($current, true);

fail(sprintf(
    'modules/catalogue.json is stale (%s). Run: php bin/build/module-catalogue.php --write',
    describeDrift($index['modules'], $decoded),
));
