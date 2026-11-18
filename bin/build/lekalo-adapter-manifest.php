<?php

declare(strict_types = 1);

/**
 * Recompute the Nativa target adapter's package manifest digests.
 *
 * The domain is the one upstream's golden test asserts
 * (`test-adapter-manifest-golden.mjs`, issue #32): the package's declared
 * files in their listed order, then the manifest's canonical bytes with
 * `packageDigest` zeroed and `manifestDigest` removed, each framed as
 * `path NUL <8-byte big-endian length> NUL bytes` and prefixed with its
 * own 8-byte length. `source.digest` is the asset-set digest and is part
 * of the domain, so it cannot be the package digest itself — a document
 * that hashes a digest of itself has no fixed point.
 *
 * Re-run after touching adapter.php, notice.md or the manifest:
 *
 *   php bin/build/lekalo-adapter-manifest.php
 *   php bin/build/lekalo-adapter-manifest.php --check
 */

$root = dirname(__DIR__, 2);
$dir = $root . '/adapters/php-nativa';
$manifestPath = $dir . '/adapter.manifest.json';
$check = in_array('--check', $argv, true);
$zero = 'sha256:' . str_repeat('0', 64);

/** Canonical JSON: byte-sorted object keys, compact, no trailing LF. */
function canon(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(canon(...), $value)) . ']';
        }
        $keys = array_keys($value);
        usort($keys, static fn ($a, $b): int => strcmp((string) $a, (string) $b));
        $members = [];
        foreach ($keys as $key) {
            $members[] = json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . ':' . canon($value[$key]);
        }

        return '{' . implode(',', $members) . '}';
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function withoutMember(mixed $value, string $member): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $next = [];
    foreach ($value as $key => $item) {
        if ($key === $member) {
            continue;
        }
        $next[$key] = is_array($item) ? withoutMember($item, $member) : $item;
    }

    return $next;
}

function digest(string $bytes): string
{
    return 'sha256:' . hash('sha256', $bytes);
}

function framed(string $path, string $bytes): string
{
    $part = $path . "\0" . pack('J', strlen($bytes)) . "\0" . $bytes;

    return pack('J', strlen($part)) . $part;
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest)) {
    fwrite(STDERR, "adapter.manifest.json is not a JSON object\n");
    exit(1);
}

// `integrity.files` is path-sorted: the package digest frames the files in
// their declared order, and the verifier requires that order to be the
// sorted one (`package_root_bytes`, ADR-0042).
$listed = array_map(static fn (array $file): string => $file['path'], $manifest['integrity']['files']);
usort($listed, strcmp(...));
$bytes = [];
foreach ($listed as $name) {
    $bytes[$name] = (string) file_get_contents($dir . '/' . $name);
}

$manifest['license']['fileDigest'] = digest($bytes['notice.md']);
$manifest['integrity']['files'] = array_map(
    static fn (string $name): array => [
        'path' => $name,
        'digest' => digest($bytes[$name]),
        'bytes' => strlen($bytes[$name]),
    ],
    $listed,
);

$snapshot = '';
foreach ($manifest['integrity']['files'] as $file) {
    $snapshot .= $file['path'] . ':' . $file['digest'] . "\n";
}
$manifest['source']['digest'] = digest($snapshot);
$manifest['integrity']['packageDigest'] = $zero;

$canonicalManifest = canon(withoutMember($manifest, 'manifestDigest'));
$domain = '';
foreach ($listed as $name) {
    $domain .= framed($name, $bytes[$name]);
}
$domain .= framed('adapter.manifest.json', $canonicalManifest);
$packageDigest = digest($domain);

$manifest['integrity']['packageDigest'] = $packageDigest;

ksort($manifest);
$rendered = json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

if ($check) {
    if ((string) file_get_contents($manifestPath) !== $rendered) {
        fwrite(STDERR, "adapter.manifest.json is stale; re-run without --check\n");
        exit(1);
    }
    fwrite(STDOUT, "adapter manifest: current ({$packageDigest})\n");
    exit(0);
}

file_put_contents($manifestPath, $rendered);
fwrite(STDOUT, "adapter manifest: {$packageDigest}\n");
