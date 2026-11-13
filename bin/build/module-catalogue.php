#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * module-catalogue.php — write or check `modules/catalogue.json`.
 *
 * The directory tree is the catalogue; this file is the generated,
 * machine-readable form a consumer can read without scanning every PHP file.
 * The build lives in Modules\Tooling\ModuleCatalogueIndex so the registry
 * install path can rewrite the same index after it adds a module.
 *
 * Usage:
 *   php bin/build/module-catalogue.php --check [--root=<dir>]
 *   php bin/build/module-catalogue.php --write [--root=<dir>]
 */

use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleCatalogueIndex;

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
$index = new ModuleCatalogueIndex($catalog);

try {
    $built = $index->build();
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}

$json = $index->encode($built);
$count = count($built['modules']);
$path = $root . '/modules/catalogue.json';

if ('write' === $options['mode']) {
    try {
        $index->write();
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
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
    $index->drift($built['modules'], $decoded),
));
