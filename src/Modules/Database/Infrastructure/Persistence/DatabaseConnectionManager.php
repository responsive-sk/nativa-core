<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Contracts\ConnectionInterface;

/**
 * DatabaseConnectionManager — per-module connection factory.
 *
 * SQLite keeps the per-module file model (users.db, config.db, ...). PostgreSQL
 * uses one database for the whole tree: `DB_DSN` is the shared DSN and each
 * module gets its own schema, named after the module and selected by
 * `search_path`. MariaDB has no separate schema layer, so there `DB_DSN` is a
 * server DSN and each module gets its own database named
 * `<DB_DATABASE_PREFIX><module>` (default prefix `nativa_`), created on first
 * connection. A per-module `DB_<NAME>` DSN remains as a test/legacy override.
 * Connections are created lazily and cached for the request/process lifetime.
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
            $driver = $this->driver();
            $target = Driver::Sqlite === $driver ? $this->resolvePath($path) : $path;
            $shared = $this->env('DB_DSN');
            $override = $this->env('DB_' . strtoupper($name));
            $schema = Driver::Pgsql === $driver && null !== $shared && null === $override
                ? $this->schemaName($name)
                : null;

            if (Driver::MariaDb === $driver) {
                $target = DsnBuilder::withCharset($target);

                if (null !== $shared && null === $override) {
                    $target = DsnBuilder::withDatabase($target, $this->mariaDbDatabase($name));
                }
            }

            $this->readConnections[$key] ??= new PdoConnection(
                $this->openPdo($target, readOnly: true, schema: $schema),
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
     * Create a hardened connection for a module database.
     */
    private function createConnection(string $name): \PDO
    {
        $driver = $this->driver();

        if (Driver::Sqlite === $driver) {
            $path = $this->paths->database($name);
            $this->paths->ensureDir(dirname($path));

            return $this->openPdo($path, readOnly: false);
        }

        $shared = $this->env('DB_DSN');
        $override = $this->env('DB_' . strtoupper($name));

        if (null === $shared && null === $override) {
            throw new \RuntimeException(sprintf(
                'DB_DSN or DB_%s must hold the %s DSN when DB_DRIVER=%s',
                strtoupper($name),
                $this->driverName(),
                $this->driverName(),
            ));
        }

        if (Driver::MariaDb === $driver) {
            if (null !== $override) {
                return $this->openPdo(DsnBuilder::withCharset($override), readOnly: false);
            }

            $database = $this->mariaDbDatabase($name);
            $this->ensureMariaDbDatabase($shared, $database);

            return $this->openPdo(
                DsnBuilder::withDatabase($shared, $database),
                readOnly: false,
            );
        }

        $target = $shared ?? $override;
        $schema = null === $shared ? null : $this->schemaName($name);

        return $this->openPdo($target, readOnly: false, schema: $schema);
    }

    /**
     * The configured driver: `DB_DRIVER`, defaulting to SQLite.
     *
     * Read on every call so a test can reconfigure the process and a
     * long-lived worker cannot outlive a changed environment.
     */
    public function driverName(): string
    {
        return $this->driver()->value;
    }

    private function driver(): Driver
    {
        $configured = $this->env('DB_DRIVER');

        return Driver::fromName($configured ?? Driver::Sqlite->value);
    }

    /**
     * Open and harden one handle for the configured driver.
     *
     * The SQLite path keeps the exact statements it always ran, including the
     * refused-WAL log line: every existing deployment is that path. PostgreSQL
     * and MariaDB get their session settings in `SessionSettings`, and a
     * read-only handle refuses writes at the session level rather than at a
     * file mode. A shared PostgreSQL DSN also gets its module schema created
     * on the primary handle before `search_path` is selected; MariaDB creates
     * its module database before the handle is opened.
     */
    private function openPdo(string $target, bool $readOnly, ?string $schema = null): \PDO
    {
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (null !== $this->statementClass) {
            $options[\PDO::ATTR_STATEMENT_CLASS] = [$this->statementClass, []];
        }

        $driver = $this->driver();
        $pdo = new \PDO(DsnBuilder::build($driver, $target), null, null, $options);

        if (null !== $schema && !$readOnly) {
            $this->ensureSchema($pdo, $schema);
        }

        SessionSettings::apply($pdo, $driver, $readOnly, $target, $schema);

        return $pdo;
    }

    /**
     * The PostgreSQL schema that holds one module's tables.
     *
     * Module names are already lower-case identifiers; the check keeps a typo
     * or an external caller from turning a connection name into SQL.
     */
    private function schemaName(string $name): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \RuntimeException(sprintf('Invalid module schema name "%s".', $name));
        }

        return $name;
    }

    /**
     * Create the module schema on the primary handle.
     *
     * One database holds every module, so the schema is the isolation boundary.
     * It is created before `search_path` is selected, and only on the primary:
     * a read-only replica must already have the schema the primary created.
     */
    private function ensureSchema(\PDO $pdo, string $schema): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');

        if (false !== $statement) {
            $statement->execute([$schema]);

            if (false !== $statement->fetchColumn()) {
                return;
            }
        }

        $quoted = SqlDialect::forDriver(Driver::Pgsql)->quoteIdentifier($schema);
        $pdo->exec('CREATE SCHEMA IF NOT EXISTS ' . $quoted);
    }

    /**
     * The MariaDB database that holds one module's tables.
     *
     * MariaDB has no schema inside a database, so the module boundary is a
     * database of its own. The prefix keeps a shared server from colliding
     * with an unrelated `users` database and is operator-configurable.
     */
    private function mariaDbDatabase(string $module): string
    {
        $prefix = $this->env('DB_DATABASE_PREFIX') ?? 'nativa_';

        if ('' !== $prefix && 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) {
            throw new \RuntimeException(sprintf('Invalid DB_DATABASE_PREFIX "%s".', $prefix));
        }

        $database = $prefix . $module;

        if (strlen($database) > 64 || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $database)) {
            throw new \RuntimeException(sprintf('Invalid MariaDB module database name "%s".', $database));
        }

        return $database;
    }

    /**
     * Create the module database on a connection that has none selected.
     *
     * `DB_DSN` is a server DSN for MariaDB; a `dbname` in it is ignored on
     * this path because the module database is derived from the prefix. The
     * role therefore needs CREATE on the server.
     */
    private function ensureMariaDbDatabase(string $dsn, string $database): void
    {
        $pdo = $this->openPdo(
            DsnBuilder::withCharset(DsnBuilder::withoutDatabase($dsn)),
            readOnly: false,
        );

        $quoted = SqlDialect::forDriver(Driver::MariaDb)->quoteIdentifier($database);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
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
