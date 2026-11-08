<?php

declare(strict_types = 1);

namespace Modules\Health\Checks;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;

/**
 * DatabaseHealthCheck — every module SQLite database answers `SELECT 1`.
 *
 * marko's version probes ONE connection. Ours cannot: the kernel gives each
 * module its own file (`users.db`, `blog.db`, `config.db`, …), so "the
 * database is up" is a claim about a set, and one broken file is a broken
 * site even while eleven others answer. The check therefore takes the names and
 * probes all of them, and reports the COUNT rather than the file that failed —
 * a payload reachable by any anonymous caller does not get to name the path it
 * could not read.
 *
 * The connection factory is injectable for one reason: a test can then assert
 * both answers (a live PDO, a throwing one) without creating files under
 * `storage/data`. Production passes nothing and the module's own
 * `DatabaseConnectionManager` is used, so the check opens exactly the
 * connections the app opens — same PRAGMAs, same `DB_<NAME>` overrides.
 */
final class DatabaseHealthCheck implements HealthCheckInterface
{
    /**
     * @param list<string>                 $connections module database names (users, blog, …)
     * @param (\Closure(string): \PDO)|null $connect     connector override for tests
     */
    public function __construct(
        private readonly array $connections,
        private readonly ?\Closure $connect = null,
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        if ([] === $this->connections) {
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Degraded,
                message: 'no module database found to probe',
                duration: microtime(true) - $start,
            );
        }

        /** @var \Closure(string): \PDO $connect */
        $connect = $this->connect ?? static fn (string $name): \PDO => DatabaseConnectionManager::instance()->connection($name);

        try {
            foreach ($this->connections as $name) {
                $statement = $connect($name)->query('SELECT 1');

                if (false === $statement) {
                    throw new \RuntimeException('SELECT 1 returned no statement');
                }
            }
        } catch (\Throwable $failure) {
            // The message counts what was configured, not what succeeded: an
            // anonymous caller learns "something does not answer", and the
            // exception carries which file and why into storage/logs.
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Unhealthy,
                message: sprintf('one of %d module databases did not answer', count($this->connections)),
                duration: microtime(true) - $start,
                exception: $failure,
            );
        }

        return new HealthResult(
            name: $this->getName(),
            status: HealthStatus::Healthy,
            message: sprintf('%d module databases answered', count($this->connections)),
            duration: microtime(true) - $start,
        );
    }
}
