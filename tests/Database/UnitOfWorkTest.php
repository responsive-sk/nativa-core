<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UnitOfWorkTest — nesting is a property of the database, not of the object.
 *
 * Every caller constructs its own `UnitOfWork`, so two instances over one
 * database are the normal case: `BulkDeleteAction` opens one, then
 * `UserRepository::delete()` opens another. The stack that decides "begin or
 * savepoint" therefore cannot live on the instance, and it cannot be keyed by
 * the handle either: `connect()` returns a `ReadWriteConnection` decorator,
 * and nothing guarantees that two callers hold the same decorator object.
 *
 * These cases pin the three ways that key can be wrong:
 *
 *  - two instances, same database  -> one stack (the second opens a savepoint);
 *  - nested failure                -> rolls back to the savepoint, outer work
 *                                     survives and commits;
 *  - two different databases       -> two stacks, which on separate handles
 *                                     means two real transactions.
 *
 * Both operands of every assertion are real: real SQLite, real transactions.
 * A keying mistake does not return a wrong number here, it raises
 * `PDOException: cannot start a transaction within a transaction`, which is the
 * failure this class exists to prevent.
 */
final class UnitOfWorkTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        UnitOfWork::reset();

        $this->pdo = $this->open();
    }

    protected function tearDown(): void
    {
        UnitOfWork::reset();

        parent::tearDown();
    }

    #[Test]
    public function twoInstancesOverOneDatabaseShareOneNestingStack(): void
    {
        $outer = new UnitOfWork(new PdoConnection($this->pdo, 'users'));
        $inner = new UnitOfWork(new PdoConnection($this->pdo, 'users'));

        self::assertNotSame($outer, $inner, 'the two wrappers are different objects on purpose');

        $outer->beginTransaction();
        self::assertSame(1, $outer->depth());

        // A handle-keyed stack would read this as depth 0 and call
        // beginTransaction() on a connection that already has one.
        $inner->beginTransaction();
        self::assertSame(2, $inner->depth(), 'the inner scope must see the outer one');
        self::assertSame(2, $outer->depth(), 'and the outer scope must see the inner one');

        $inner->rollback();
        self::assertSame(1, $outer->depth(), 'rolling the inner scope back leaves the outer one open');

        $outer->commit();
        self::assertSame(0, $outer->depth());
    }

    #[Test]
    public function aNestedFailureRollsBackOnlyItsOwnScope(): void
    {
        $outer = new UnitOfWork(new PdoConnection($this->pdo, 'users'));
        $inner = new UnitOfWork(new PdoConnection($this->pdo, 'users'));

        $outer->transaction(function (ConnectionInterface $db) use ($inner): void {
            $db->execute("INSERT INTO t (v) VALUES ('outer')");

            try {
                $inner->transaction(function (ConnectionInterface $innerDb): void {
                    $innerDb->execute("INSERT INTO t (v) VALUES ('inner')");

                    throw new \RuntimeException('the inner scope failed');
                });
            } catch (\RuntimeException) {
                // Expected: the inner scope rolled back to its savepoint.
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        self::assertSame(
            ['outer'],
            $this->values(),
            'the inner scope must roll back to its savepoint without discarding the outer scope\'s work',
        );
    }

    #[Test]
    public function differentDatabasesKeepDifferentStacks(): void
    {
        $users = new UnitOfWork(new PdoConnection($this->pdo, 'users'));
        $audit = new UnitOfWork(new PdoConnection($this->open(), 'audit'));

        $users->beginTransaction();
        $audit->beginTransaction();

        self::assertSame(1, $audit->depth(), 'a second database is not a nested scope of the first');
        self::assertSame(1, $users->depth());

        $audit->commit();
        $users->commit();
    }

    #[Test]
    public function depthIsZeroOutsideATransaction(): void
    {
        $uow = new UnitOfWork(new PdoConnection($this->pdo, 'users'));

        self::assertSame(0, $uow->depth());
        self::assertFalse($uow->inTransaction());

        // commit() and rollback() outside a transaction are no-ops, not errors:
        // a caller that unwinds after an earlier failure must not fail again.
        $uow->commit();
        $uow->rollback();

        self::assertSame(0, $uow->depth());
    }

    private function open(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE TABLE t (v TEXT NOT NULL)');

        return $pdo;
    }

    /**
     * @return list<string>
     */
    private function values(): array
    {
        $values = [];

        foreach ($this->pdo->query('SELECT v FROM t ORDER BY rowid') ?: [] as $row) {
            $value = is_array($row) ? ($row['v'] ?? null) : null;
            $values[] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }
}
