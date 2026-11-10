<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\StatementInterface;

/**
 * PdoStatement — the `\PDOStatement` a PdoConnection prepares, behind the
 * statement contract.
 *
 * Thin on purpose: it adds no behaviour, it only removes the `false` branch
 * PDO's signature still advertises. Nothing here decides routing; the
 * connection already did by returning this statement from one of its sides.
 *
 * @internal
 */
final readonly class PdoStatement implements StatementInterface
{
    public function __construct(
        private \PDOStatement $statement,
    ) {
    }

    public function execute(array $bindings = []): bool
    {
        // Passing `[]` is not the same as passing nothing: PDO reads an
        // argument as "bind exactly these", so an empty array would drop the
        // values a caller set with bindValue(). The contract's default means
        // "use what is already bound", which is PDO's null.
        return $this->statement->execute([] === $bindings ? null : $bindings);
    }

    public function fetch(int $mode = \PDO::FETCH_ASSOC): array|false
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->statement->fetch($mode);

        return $row;
    }

    /**
     * @return array<mixed>
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC, mixed ...$args): array
    {
        return $this->statement->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->statement->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        return $this->statement->bindValue($param, $value, $type);
    }
}
