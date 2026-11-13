<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Storage\Providers;

/**
 * MediaProviderInterface — strategy pattern for media storage providers.
 *
 * LocalStorageProvider handles the filesystem; CloudinaryProvider handles
 * the remote CDN. The MediaService uses this interface so the upload/delete
 * logic stays provider-agnostic.
 */
interface MediaProviderInterface
{
    /**
     * Store an uploaded file.
     *
     * @param array<string, mixed> $file $_FILES entry
     *
     * @throws \RuntimeException on validation or storage failure
     *
     * @return array{path: string, url: string, size: int, mime_type: string}
     */
    public function store(array $file): array;

    /**
     * Delete a stored file by its relative path.
     */
    public function delete(string $path): bool;

    /**
     * Get the public URL for a stored file.
     */
    public function url(string $path): string;

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool;

    /**
     * Get the provider name (e.g. 'local', 'cloudinary').
     */
    public function getName(): string;
}
