<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Database\Exceptions\ReadException;

/**
 * FailoverStatement — a prepared read whose replica is not chosen yet.
 *
 * `prepare()` cannot both return a statement and fail over: the throw happens
 * at `execute()`, when the replica was chosen minutes or lines earlier. So the
 * prepare itself is deferred to `execute()`, which is the moment a failure is
 * visible. A dead replica then costs one attempt instead of the whole read.
 *
 * Bindings are replayed rather than left on the PDO handle: the retry prepares
 * a new statement on another connection, which knows nothing about what the
 * first one bound. A statement executed twice keeps the handle it was given and
 * only re-enters the failover loop when that handle fails.
 *
 * Accessors before `execute()` raise. PDO would raise the same way; doing it
 * here names the statement and keeps the failure in this class.
 *
 * @internal
 */
final class FailoverStatement implements StatementInterface
{
    private ?StatementInterface $statement = null;

    /**
     * @var array<string|int, array{mixed, int}>
     */
    private array $bound = [];

    /**
     * @param \Closure(): list<ConnectionInterface> $readOrder
     */
    public function __construct(
        private readonly \Closure $readOrder,
        private readonly string $database,
        private readonly string $sql,
    ) {
    }

    public function execute(array $bindings = []): bool
    {
        if (null !== $this->statement) {
            try {
                return $this->run($this->statement, $bindings);
            } catch (\PDOException) {
                // The handle went away between two runs. Prepare again, which
                // is the path a first execute takes anyway.
                $this->statement = null;
            }
        }

        $failures = [];

        foreach (($this->readOrder)() as $replica) {
            try {
                $statement = $replica->prepare($this->sql);
                $result = $this->run($statement, $bindings);

                $this->statement = $statement;

                return $result;
            } catch (\PDOException $failure) {
                $failures[] = $replica->name() . ': ' . $failure->getMessage();
            }
        }

        throw ReadException::allReplicasFailed($this->database, $failures);
    }

    public function fetch(int $mode = \PDO::FETCH_ASSOC): array|false
    {
        return $this->executed()->fetch($mode);
    }

    /**
     * @return array<mixed>
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC, mixed ...$args): array
    {
        return $this->executed()->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->executed()->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->executed()->rowCount();
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type];

        return $this->statement?->bindValue($param, $value, $type) ?? true;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    private function run(StatementInterface $statement, array $bindings): bool
    {
        foreach ($this->bound as $param => [$value, $type]) {
            $statement->bindValue($param, $value, $type);
        }

        return $statement->execute($bindings);
    }

    private function executed(): StatementInterface
    {
        if (null === $this->statement) {
            throw new \LogicException('The statement has not been executed: ' . $this->sql);
        }

        return $this->statement;
    }
}
