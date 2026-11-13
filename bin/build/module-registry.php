#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * module-registry.php — package the catalogue as a static module registry.
 *
 * The output is a directory that can be published anywhere static:
 *
 *   registry.json
 *   archives/<Name>.tar.gz
 *
 * An archive contains the module's files at its root, exactly the shape
 * ModuleRegistry stages before calling ModuleInstaller. The index carries the
 * dependency list and the SHA-256, so a consumer never has to trust a downloaded
 * file without checking it.
 *
 * This is a snapshot builder, not a version solver. Every module is packaged
 * from the same catalogue revision; a release registry that versions modules
 * independently needs its own metadata on top of this shape.
 *
 * Usage:
 *   php bin/build/module-registry.php --out=<dir> [--root=<dir>] [--force]
 */

use Modules\Tooling\ModuleCatalog;
use Modules\Tooling\ModuleInstaller;

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, 'module-registry: ' . $message . PHP_EOL);
    exit(1);
}

function usage(): void
{
    fwrite(STDOUT, <<<'TEXT'
Usage: php bin/build/module-registry.php --out=<dir> [--root=<dir>] [--force]

  --out=    directory to write registry.json and archives/ into
  --root=   catalogue tree to package (default: this repository)
  --force   replace a non-empty output directory

TEXT);
}

/**
 * @param list<string> $argv
 *
 * @return array{root: string, out: string, force: bool}
 */
function options(array $argv): array
{
    $root = dirname(__DIR__, 2);
    $out = null;
    $force = false;

    foreach ($argv as $argument) {
        if ('--help' === $argument || '-h' === $argument) {
            usage();
            exit(0);
        }

        if ('--force' === $argument) {
            $force = true;

            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));

            continue;
        }

        if (str_starts_with($argument, '--out=')) {
            $out = substr($argument, strlen('--out='));

            continue;
        }

        fail(sprintf('unknown argument: %s (see --help)', $argument));
    }

    if (null === $out || '' === $out) {
        fail('--out=<dir> is required');
    }

    return ['root' => $root, 'out' => $out, 'force' => $force];
}

/**
 * @throws \RuntimeException when a dependency is not catalogued
 *
 * @return list<string>
 */
function dependencies(ModuleCatalog $catalog, string $name): array
{
    $known = array_flip($catalog->catalogued());
    $requires = [];

    foreach ($catalog->manifest($name)->closure() as $dependency) {
        if ($dependency === $name || in_array($dependency, ModuleCatalog::KERNEL_MODULES, true)) {
            continue;
        }

        if (!isset($known[$dependency])) {
            throw new \RuntimeException(sprintf(
                "Module '%s' requires '%s', which is not catalogued.",
                $name,
                $dependency,
            ));
        }

        $requires[$dependency] = true;
    }

    $requires = array_keys($requires);
    sort($requires);

    return $requires;
}

function archive(ModuleCatalog $catalog, string $name, string $archives): string
{
    $source = $catalog->cataloguePath($name);

    if (null === $source) {
        throw new \RuntimeException(sprintf("Module '%s' has no catalogue path.", $name));
    }

    $tarPath = $archives . '/' . $name . '.tar';
    $archivePath = $tarPath . '.gz';

    if (is_file($tarPath) && !@unlink($tarPath)) {
        throw new \RuntimeException(sprintf('Cannot replace %s.', $tarPath));
    }

    if (is_file($archivePath) && !@unlink($archivePath)) {
        throw new \RuntimeException(sprintf('Cannot replace %s.', $archivePath));
    }

    $tar = new \PharData($tarPath);
    $prefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = 0;

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        /** @var \SplFileInfo $file */
        if ($file->isLink()) {
            throw new \RuntimeException(sprintf("Module '%s' contains a symlink: %s", $name, $file->getPathname()));
        }

        if (!$file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($prefix));

        $tar->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        $files++;
    }

    if (0 === $files) {
        unset($tar);
        @unlink($tarPath);

        throw new \RuntimeException(sprintf("Module '%s' has no files to package.", $name));
    }

    $tar->compress(\Phar::GZ);
    unset($tar);
    @unlink($tarPath);

    if (!is_file($archivePath)) {
        throw new \RuntimeException(sprintf('Archive was not written: %s', $archivePath));
    }

    return $archivePath;
}

$options = options(array_slice($argv, 1));
$root = realpath($options['root']);

if (false === $root) {
    fail(sprintf('root does not exist: %s', $options['root']));
}

if (!is_dir($root . '/modules')) {
    fail(sprintf('no modules/ catalogue under %s', $root));
}

$out = str_starts_with($options['out'], '/')
    ? $options['out']
    : getcwd() . '/' . $options['out'];

if (is_dir($out) && [] !== array_diff(scandir($out) ?: [], ['.', '..'])) {
    if (!$options['force']) {
        fail(sprintf('output directory is not empty: %s (pass --force to replace it)', $out));
    }

    try {
        ModuleInstaller::deleteTree($out);
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
    }
}

if (file_exists($out) && !is_dir($out)) {
    fail(sprintf('output path is a file: %s', $out));
}

if (!is_dir($out . '/archives') && !mkdir($out . '/archives', 0o775, true) && !is_dir($out . '/archives')) {
    fail(sprintf('cannot create %s/archives', $out));
}

require_once dirname(__DIR__, 2) . '/src/init.php';

$catalog = new ModuleCatalog($root . '/modules', $root . '/src/Modules');
$modules = [];

try {
    foreach ($catalog->catalogued() as $name) {
        $archivePath = archive($catalog, $name, $out . '/archives');

        $modules[$name] = [
            'requires' => dependencies($catalog, $name),
            'archive'  => 'archives/' . basename($archivePath),
            'sha256'   => (string) hash_file('sha256', $archivePath),
        ];
    }
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}

ksort($modules);

$json = json_encode(
    ['schema_version' => 1, 'modules' => $modules],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);

if (false === $json || false === @file_put_contents($out . '/registry.json', $json . PHP_EOL)) {
    fail(sprintf('cannot write %s/registry.json', $out));
}

printf(
    'Wrote %s/registry.json (%d module%s, %d archive%s).%s',
    $out,
    count($modules),
    1 === count($modules) ? '' : 's',
    count($modules),
    1 === count($modules) ? '' : 's',
    PHP_EOL,
);
