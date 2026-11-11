<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Contracts\ConnectionInterface;

/**
 * DatabaseConnectionManager — per-module connection factory.
 *
 * Each module owns its own database: a SQLite file (users.db, config.db, ...)
 * by default, or a server database when `DB_<NAME>_DSN` names one. Connections
 * are created lazily and cached for the request/process lifetime.
 *
 * Two ways in:
 *
 *  - `connection($name)` returns the raw `\PDO`, the historical entry point
 *    every repository still uses;
 *  - `connect($name)` returns a `ConnectionInterface`. When
 *    `DB_<NAME>_READ` or `DB_<NAME>_READ_DSN` names one or more replicas, that
 *    is a ReadWriteConnection; otherwise it is the same handle behind
 *    PdoConnection.
 *
 * The read side is opt-in per module on purpose: a replica is a deployment
 * decision, and an unset variable must mean "no routing", not "route to an
 * empty list".
 *
 * ## Configuration
 *
 *  - `DB_<NAME>_DSN`       primary DSN; unset means `sqlite:` plus the module file
 *  - `DB_<NAME>_USER`      user for a server DSN (falls back to `DB_USER`)
 *  - `DB_<NAME>_PASSWORD`  password for a server DSN (falls back to `DB_PASSWORD`)
 *  - `DB_<NAME>_READ`      comma-separated SQLite replica files
 *  - `DB_<NAME>_READ_DSN`  comma-separated replica DSNs, same credentials
 *  - `DB_READ_STRATEGY` and `DB_<NAME>_READ_WEIGHTS` as before
 *
 * A credential pair belongs to the module, not to a single DSN, because a
 * replica of a module database normally accepts the same user.
 *
 * SQLite-specific hardening (busy timeout, foreign keys, WAL, `query_only`)
 * applies only to a `sqlite:` DSN. A server connection is hardened by its
 * server, and a MariaDB/MySQL replica is made read-only through the grant it
 * connects with: there is no session-scoped `query_only` to set.
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
     * With no replica configured this is the plain handle, wrapped but not
     * rerouted. With one configured, reads go to the replicas and writes to the
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
     *
     * A transaction left open by a job that threw is rolled back here too, so
     * the next unit of work does not inherit it.
     */
    public function resetRouting(): void
    {
        foreach ($this->routed as $connection) {
            if ($connection instanceof ReadWriteConnection) {
                $connection->reset();
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
     * `DB_<NAME>_READ` keeps naming SQLite files, so a deployment that routed
     * reads before this DSN seam still routes them. `DB_<NAME>_READ_DSN` adds
     * server replicas; both lists are appended in that order.
     *
     * @return list<ConnectionInterface>
     */
    private function readReplicas(string $name): array
    {
        $upper = strtoupper($name);
        $dsns = [];

        foreach ($this->entries($this->env('DB_' . $upper . '_READ')) as $path) {
            $dsns[] = 'sqlite:' . $this->resolvePath($path);
        }

        foreach ($this->entries($this->env('DB_' . $upper . '_READ_DSN')) as $dsn) {
            $dsns[] = $dsn;
        }

        $replicas = [];
        foreach ($dsns as $index => $dsn) {
            $key = $name . ':' . $index;
            $this->readConnections[$key] ??= new PdoConnection(
                $this->openPdo($dsn, true, ...$this->credentials($name)),
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
     * Create the primary handle for a module database.
     */
    private function createConnection(string $name): \PDO
    {
        $dsn = $this->dsn($name);

        if (str_starts_with($dsn, 'sqlite:')) {
            $this->paths->ensureDir(dirname(substr($dsn, \strlen('sqlite:'))));
        }

        return $this->openPdo($dsn, false, ...$this->credentials($name));
    }

    /**
     * Open and, for SQLite, harden one handle.
     *
     * A read-only SQLite handle still opens the file read-write at the OS
     * level, then sets `query_only`: SQLite itself refuses every write from it.
     * That is the loud failure a misrouted write needs, without the URI
     * handling `mode=ro` would add. A server driver has no such switch, so its
     * read-only-ness is the grant the DSN connects with.
     */
    private function openPdo(string $dsn, bool $readOnly, ?string $user = null, #[\SensitiveParameter] ?string $password = null): \PDO
    {
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (null !== $this->statementClass) {
            $options[\PDO::ATTR_STATEMENT_CLASS] = [$this->statementClass, []];
        }

        $pdo = new \PDO($dsn, $user, $password, $options);

        if (!str_starts_with($dsn, 'sqlite:')) {
            return $pdo;
        }

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
                $dsn,
                $refusal->getMessage(),
            ));
        }

        if ($readOnly) {
            $pdo->exec('PRAGMA query_only = 1');
        }

        return $pdo;
    }

    /**
     * The DSN for a module database: configured, or the SQLite file.
     */
    private function dsn(string $name): string
    {
        return $this->env('DB_' . strtoupper($name) . '_DSN')
            ?? 'sqlite:' . $this->paths->database($name);
    }

    /**
     * The user and password for a module DSN.
     *
     * A server DSN names a host and a database, never credentials; the two env
     * vars are separate for that reason. A module may override them, and the
     * global pair is the usual case: every module database of one deployment
     * normally accepts the same user. SQLite ignores both, so two unset vars
     * are the normal case there.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function credentials(string $name): array
    {
        $upper = strtoupper($name);

        return [
            $this->env('DB_' . $upper . '_USER') ?? $this->env('DB_USER'),
            $this->env('DB_' . $upper . '_PASSWORD') ?? $this->env('DB_PASSWORD'),
        ];
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || ':memory:' === $path) {
            return $path;
        }

        return $this->paths->join($path);
    }

    /**
     * A comma-separated env value, blanks dropped.
     *
     * @return list<string>
     */
    private function entries(?string $configured): array
    {
        if (null === $configured) {
            return [];
        }

        $entries = [];
        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            if ('' !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
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
