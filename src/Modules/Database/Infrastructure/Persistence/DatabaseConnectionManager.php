<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Contracts\ConnectionInterface;

/**
 * DatabaseConnectionManager — per-module SQLite connection factory.
 *
 * Each module owns its own database file (users.db, config.db, ...),
 * mirroring the Nativa per-module isolation model. Connections are
 * created lazily and cached for the request/process lifetime.
 *
 * Two ways in:
 *
 *  - `connection($name)` returns the raw `\PDO`, the historical entry point
 *    every repository still uses;
 *  - `connect($name)` returns a `ConnectionInterface`. When
 *    `DB_<NAME>_READ` names one or more replica files, that is a
 *    ReadWriteConnection; otherwise it is the same handle behind PdoConnection.
 *
 * The read side is opt-in per module on purpose: a replica is a deployment
 * decision, and an unset variable must mean "no routing", not "route to an
 * empty list".
 */
final class DatabaseConnectionManager
{
    private static ?self $instance = null;

    /** @var array<string, \PDO> */
    private array $connections = [];

    /** @var array<string, ConnectionInterface> */
    private array $routed = [];

    /** @var array<string, ConnectionInterface> */
    private array $readConnections = [];

    /**
     * Optional PDO statement class override (e.g. the Debug module's query
     * logger). Applied to connections created AFTER this is set; default
     * null keeps stock PDO behaviour.
     */
    private ?string $statementClass = null;

    public function __construct(private readonly AppPaths $paths)
    {
    }

    /**
     * Get the shared instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(AppPaths::instance());
        }

        return self::$instance;
    }

    /**
     * Reset the singleton and close all open connections (test isolation).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Get (and lazily create) the PDO connection for a module database.
     *
     * @throws \RuntimeException when the connection cannot be established
     */
    public function connection(string $name): \PDO
    {
        return $this->connections[$name] ??= $this->createConnection($name);
    }

    /**
     * Get the connection contract for a module database.
     *
     * With `DB_<NAME>_READ` unset this is the plain handle, wrapped but not
     * rerouted. With it set, reads go to the listed replicas and writes to the
     * handle `connection()` returns, so both entry points share one primary.
     */
    public function connect(string $name): ConnectionInterface
    {
        return $this->routed[$name] ??= $this->createRouted($name);
    }

    /**
     * Reset routing state between requests and jobs.
     *
     * The sticky write and the `onPrimary()` depth belong to one unit of work.
     * In a long-lived worker they would otherwise outlive it: after the first
     * write every later read would go to the primary, correct but invisible.
     */
    public function resetRouting(): void
    {
        foreach ($this->routed as $connection) {
            if ($connection instanceof ReadWriteConnection) {
                $connection->resetSticky();
            }
        }
    }

    /**
     * Close a named connection so the next connection() call re-opens it.
     */
    public function close(string $name): void
    {
        unset($this->connections[$name], $this->routed[$name]);

        foreach (array_keys($this->readConnections) as $key) {
            if ($key === $name || str_starts_with($key, $name . ':')) {
                unset($this->readConnections[$key]);
            }
        }
    }

    /**
     * Names of the currently open connections.
     *
     * @return list<string>
     */
    public function openConnections(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Set a PDO statement class override (generic hook — the Debug module
     * installs its query-logging statement here). Connections created after
     * this call apply the class; existing connections must be closed first.
     */
    public function setStatementClass(?string $class): void
    {
        $this->statementClass = $class;
    }

    private function createRouted(string $name): ConnectionInterface
    {
        $replicas = $this->readReplicas($name);

        if ([] === $replicas) {
            return new PdoConnection($this->connection($name), $name);
        }

        return new ReadWriteConnection(
            new PdoConnection($this->connection($name), $name),
            $replicas,
            new ReplicaSelector($this->readStrategy(), $this->readWeights($name)),
        );
    }

    /**
     * The configured read replicas, opened read-only.
     *
     * @return list<ConnectionInterface>
     */
    private function readReplicas(string $name): array
    {
        $configured = $this->env('DB_' . strtoupper($name) . '_READ');

        if (null === $configured) {
            return [];
        }

        $replicas = [];
        foreach (explode(',', $configured) as $index => $path) {
            $path = trim($path);
            if ('' === $path) {
                continue;
            }

            $key = $name . ':' . $index;
            $this->readConnections[$key] ??= new PdoConnection(
                $this->openPdo($this->resolvePath($path), readOnly: true),
                $name . ':read' . ($index + 1),
            );

            $replicas[] = $this->readConnections[$key];
        }

        return $replicas;
    }

    private function readStrategy(): string
    {
        $strategy = $this->env('DB_READ_STRATEGY');

        return null === $strategy ? 'random' : strtolower($strategy);
    }

    /**
     * @return array<string, int>
     */
    private function readWeights(string $name): array
    {
        $configured = $this->env('DB_' . strtoupper($name) . '_READ_WEIGHTS');

        if (null === $configured) {
            return [];
        }

        $weights = [];
        foreach (explode(',', $configured) as $index => $weight) {
            $weight = trim($weight);
            if ('' === $weight) {
                continue;
            }

            $weights[$name . ':read' . ($index + 1)] = (int) $weight;
        }

        return $weights;
    }

    /**
     * Create a hardened SQLite PDO connection for a module database.
     */
    private function createConnection(string $name): \PDO
    {
        $path = $this->paths->database($name);
        $this->paths->ensureDir(dirname($path));

        return $this->openPdo($path, readOnly: false);
    }

    /**
     * Open and harden one SQLite handle.
     *
     * A read-only handle still opens the file read-write at the OS level, then
     * sets `query_only`: SQLite itself refuses every write from it. That is the
     * loud failure a misrouted write needs, without the URI handling `mode=ro`
     * would add.
     */
    private function openPdo(string $path, bool $readOnly): \PDO
    {
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (null !== $this->statementClass) {
            $options[\PDO::ATTR_STATEMENT_CLASS] = [$this->statementClass, []];
        }

        $pdo = new \PDO('sqlite:' . $path, null, null, $options);

        // Robustness + integrity pragmas for concurrent web requests.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // WAL improves concurrent read/write behaviour under FPM workers. It is
        // best-effort: a read-only or network filesystem refuses it and the
        // connection still works, so the refusal is logged rather than raised.
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException $refusal) {
            error_log(sprintf(
                '[db] %s: journal_mode WAL unavailable (%s), continuing without it',
                $path,
                $refusal->getMessage(),
            ));
        }

        if ($readOnly) {
            $pdo->exec('PRAGMA query_only = 1');
        }

        return $pdo;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || ':memory:' === $path) {
            return $path;
        }

        return $this->paths->join($path);
    }

    /**
     * Read an env var, treating an empty value as unset.
     */
    private function env(string $key): ?string
    {
        $value = getenv($key);

        if (false === $value || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }
}
