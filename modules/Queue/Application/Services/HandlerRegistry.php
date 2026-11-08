<?php

declare(strict_types = 1);

namespace Modules\Queue\Application\Services;

use Modules\Queue\Exceptions\UnknownHandlerException;

/**
 * HandlerRegistry — name → callable map for queued jobs.
 *
 * Handlers are plain callables taking the pushed args array and returning
 * anything (the return value is discarded by the worker; a job fails by
 * throwing). Unknown names throw UnknownHandlerException, which the
 * worker turns into a failed job — never a fatal.
 */
final class HandlerRegistry
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function register(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @throws UnknownHandlerException when nothing is registered under $name
     */
    public function run(string $name, array $args): mixed
    {
        $handler = $this->handlers[$name] ?? null;

        if (null === $handler) {
            throw UnknownHandlerException::for($name);
        }

        return $handler($args);
    }
}
