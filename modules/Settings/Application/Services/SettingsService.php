<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\Services;

use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;

/**
 * SettingsService — read-only query facade over SettingsRepository.
 *
 * Add a small in-memory cache so layouts can query settings repeatedly
 * without hitting the database on every access.
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->load()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->load());
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * Public (non-sensitive) settings for frontend rendering.
     *
     * @return array<string, mixed>
     */
    public function public(): array
    {
        return $this->repository->publicOnly();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return $this->cache ??= $this->repository->all();
    }
}
