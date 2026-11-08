<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * DisposalRegistry — manages cleanup callbacks for resource disposal.
 *
 * Services can register disposal callbacks that run when the request
 * ends (clean or error). Follows the Thesis DIC disposal pattern,
 * adapted for Nativa's zero-dependency philosophy.
 *
 * Usage:
 *   DisposalRegistry::register('db', fn() => $pdo->close());
 *   DisposalRegistry::disposeAll(); // at end of request
 *
 * @see Thesis\Dic disposal pattern
 */
final class DisposalRegistry
{
    /** @var array<string, callable(?\Throwable): void> */
    private static array $disposers = [];

    /**
     * Register a disposal callback.
     *
     * @param string                      $id       Unique identifier for the disposer
     * @param callable(?\Throwable): void $disposer Callback that receives the error (null = clean)
     */
    public static function register(string $id, callable $disposer): void
    {
        self::$disposers[$id] = $disposer;
    }

    /**
     * Run all registered disposal callbacks.
     *
     * Best-effort: all disposers run even if one throws.
     * Errors are logged but not rethrown.
     *
     * @param \Throwable|null $error The error that triggered disposal (null = clean)
     */
    public static function disposeAll(\Throwable|null $error = null): void
    {
        foreach (self::$disposers as $id => $disposer) {
            try {
                $disposer($error);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[DISPOSAL] %s: %s in %s:%d',
                    $id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }

        self::$disposers = [];
    }

    /**
     * Check if any disposers are registered.
     */
    public static function hasDisposers(): bool
    {
        return [] !== self::$disposers;
    }

    /**
     * Get the number of registered disposers.
     */
    public static function count(): int
    {
        return count(self::$disposers);
    }

    /**
     * Reset all disposers (test isolation).
     */
    public static function reset(): void
    {
        self::$disposers = [];
    }
}
