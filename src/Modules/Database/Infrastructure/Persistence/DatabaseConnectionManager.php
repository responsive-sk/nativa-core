<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * DatabaseConnectionManager — per-module SQLite connection factory.
 *
 * Each module owns its own database file (users.db, config.db, ...),
 * mirroring the Nativa per-module isolation model. Connections are
 * created lazily and cached for the request/process lifetime.
 */
final class DatabaseConnectionManager
{
    private static ?self $instance = null;

    /** @var array<string, \PDO> */
    private array $connections = [];

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
     * Close a named connection so the next connection() call re-opens it.
     */
    public function close(string $name): void
    {
        unset($this->connections[$name]);
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

    /**
     * Create a hardened SQLite PDO connection for a module database.
     */
    private function createConnection(string $name): \PDO
    {
        $path = $this->paths->database($name);
        $this->paths->ensureDir(dirname($path));

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
                $name,
                $refusal->getMessage(),
            ));
        }

        return $pdo;
    }
}
