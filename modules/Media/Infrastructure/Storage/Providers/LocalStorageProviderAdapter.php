<?php

declare(strict_types = 1);

namespace Modules\Media\Infrastructure\Storage\Providers;

use Modules\Core\Infrastructure\Storage\LocalStorageProvider;

/**
 * LocalStorageProviderAdapter — wraps the existing LocalStorageProvider
 * behind the MediaProviderInterface so MediaService stays provider-agnostic.
 */
final readonly class LocalStorageProviderAdapter implements MediaProviderInterface
{
    public function __construct(
        private LocalStorageProvider $storage,
    ) {
    }

    public function store(array $file): array
    {
        return $this->storage->store($file);
    }

    public function delete(string $path): bool
    {
        return $this->storage->delete($path);
    }

    public function url(string $path): string
    {
        return $this->storage->url($path);
    }

    public function exists(string $path): bool
    {
        return $this->storage->exists($path);
    }

    public function getName(): string
    {
        return 'local';
    }
}
