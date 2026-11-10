<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Infrastructure\Persistence\StatementIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * StatementIntentTest — the one place a prepared statement's side is guessed.
 *
 * The table is the contract: what is not in `READ_KEYWORDS` is a write, so a
 * mistake here costs a replica read, never a write sent to a read-only handle.
 */
final class StatementIntentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT * FROM users', false];

        yield 'select with newline' => ["\n  SELECT 1", false];

        yield 'lowercase select' => ['select count(*) from audit_log', false];

        yield 'leading line comment' => ["-- why\nSELECT 1", false];

        yield 'leading block comment' => ['/* why */ SELECT 1', false];

        yield 'parenthesised select' => ['(SELECT 1) UNION (SELECT 2)', false];

        yield 'explain' => ['EXPLAIN SELECT 1', false];

        yield 'values' => ['VALUES (1, 2)', false];

        yield 'insert' => ['INSERT INTO users (id) VALUES (1)', true];

        yield 'table update' => ['UPDATE users SET name = 1', true];

        yield 'row delete' => ['DELETE FROM users', true];

        yield 'replace' => ['REPLACE INTO users (id) VALUES (1)', true];

        yield 'create table' => ['CREATE TABLE t (id INTEGER)', true];

        yield 'alter table' => ['ALTER TABLE t ADD COLUMN x TEXT', true];

        yield 'cte may write' => ['WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x', true];

        yield 'pragma may assign' => ['PRAGMA journal_mode = WAL', true];

        yield 'call may write' => ['CALL rebuild()', true];

        yield 'vacuum' => ['VACUUM', true];

        yield 'empty statement' => ['   ', true];
    }

    #[Test]
    #[DataProvider('statements')]
    public function itClassifiesTheStatement(string $sql, bool $writes): void
    {
        self::assertSame($writes, StatementIntent::writes($sql));
        self::assertSame(!$writes, StatementIntent::reads($sql));
    }
}
