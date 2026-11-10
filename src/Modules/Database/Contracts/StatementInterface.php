<?php

declare(strict_types = 1);

namespace Modules\Database\Contracts;

/**
 * StatementInterface — the slice of `\PDOStatement` this codebase uses.
 *
 * Measured on the tree that introduced it: `fetchColumn` (56 call sites),
 * `fetchAll` (49, 11 of them `FETCH_COLUMN`), `fetch` (36), `rowCount` (16),
 * `bindValue` (15). Nothing else (`fetchObject`, `bindParam`, `closeCursor`)
 * appears, so the contract stops there instead of mirroring PDO.
 *
 * `FETCH_*` constants stay PDO's: the call sites pass them as integers and an
 * enum would be a rename that has nothing to do with routing. It is a known
 * leak, not an oversight.
 */
interface StatementInterface
{
    /**
     * @param array<string|int, mixed> $bindings
     */
    public function execute(array $bindings = []): bool;

    /**
     * @return array<string, mixed>|false
     */
    public function fetch(int $mode = \PDO::FETCH_ASSOC): array|false;

    /**
     * `\PDOStatement::fetchAll()` returns a list for the row modes and a map
     * for `FETCH_KEY_PAIR`/`FETCH_UNIQUE`, so the shape depends on `$mode`
     * rather than on this signature.
     *
     * @return array<mixed>
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC, mixed ...$args): array;

    public function fetchColumn(int $column = 0): mixed;

    public function rowCount(): int;

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool;
}
