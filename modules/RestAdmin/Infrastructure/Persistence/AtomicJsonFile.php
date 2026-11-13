<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Persistence;

/**
 * AtomicJsonFile — a file replaced in one step, or not at all.
 *
 * A specification is small, but a half-written one is not a smaller
 * specification: the panel would fail to parse it and the operator would lose
 * every resource they had configured. So the bytes go to a sibling temporary
 * file first and `rename()` replaces the target — on the same filesystem that
 * is atomic, and a reader either sees the previous document or the new one.
 *
 * Two failure points are handled explicitly:
 *
 * - the temporary file cannot be written: nothing at the target changed;
 * - `rename()` fails: the temporary file is removed, so a directory of
 *   snapshots does not fill with `.tmp` debris.
 *
 * `json_encode` runs BEFORE the temporary file is opened, so a payload that
 * cannot be encoded (a resource handle, NAN) never touches the disk.
 */
final class AtomicJsonFile
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException when the payload cannot be encoded
     * @throws \RuntimeException when the file cannot be written or replaced
     */
    public function write(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create the directory for %s.', $path));
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (false === @file_put_contents($temporary, $json, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot write the temporary file for %s.', $path));
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot replace %s with its new contents.', $path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        $raw = @file_get_contents($path);

        if (false === $raw) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('%s does not hold a JSON object.', $path));
        }

        return $decoded;
    }
}
