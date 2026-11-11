<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Exceptions\TransactionConflictException;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Database\Support\RecordingConnection;

/**
 * TransactionRetryTest — the retry is the contract's, and it is narrow.
 *
 * Three things can go wrong with a retry loop, and each one is a case here:
 * it can retry a failure nobody identified (a duplicate write), it can replay
 * a nested scope whose outer transaction already did the work, or it can loop
 * without ever giving up. The counters on the recording connection are what
 * make the first two observable.
 *
 * The classification half is tested against real `\PDOException` objects,
 * because the SQLSTATE and the message are the whole input: SQLite says
 * `database is locked` where PostgreSQL says 40001.
 */
final class TransactionRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UnitOfWork::reset();
    }

    protected function tearDown(): void
    {
        UnitOfWork::reset();

        parent::tearDown();
    }

    #[Test]
    public function aConflictRunsTheWholeTransactionAgain(): void
    {
        $connection = new RecordingConnection();

        $runs = 0;
        $result = $connection->transaction(function (ConnectionInterface $db) use (&$runs): string {
            $runs++;

            if (1 === $runs) {
                throw new TransactionConflictException('deadlock found when trying to get lock');
            }

            return 'committed';
        }, attempts: 3, backoff: 0);

        self::assertSame('committed', $result);
        self::assertSame(2, $runs, 'the second attempt runs the callback from the start');
        self::assertSame(2, $connection->begins, 'each attempt is its own transaction');
        self::assertSame(1, $connection->commits);
        self::assertSame(1, $connection->rollbacks, 'the failed attempt is rolled back before the next');
    }

    #[Test]
    public function aDriverConflictIsClassifiedAndRetried(): void
    {
        $connection = new RecordingConnection();

        $runs = 0;
        $connection->transaction(function () use (&$runs): void {
            $runs++;

            if (1 === $runs) {
                // What SQLite raises when the write lock is taken, which is a
                // serialization failure with no SQLSTATE of its own.
                throw new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }
        }, attempts: 2, backoff: 0);

        self::assertSame(2, $runs);
        self::assertSame(1, $connection->commits);
    }

    #[Test]
    public function anotherFailureIsNotRetried(): void
    {
        $connection = new RecordingConnection();

        $runs = 0;
        $failure = new \RuntimeException('no such column: v');

        $caught = null;

        try {
            $connection->transaction(function () use (&$runs, $failure): void {
                $runs++;

                throw $failure;
            }, attempts: 5, backoff: 0);
        } catch (\RuntimeException $failureThatReachedTheCaller) {
            $caught = $failureThatReachedTheCaller;
        }

        self::assertSame($failure, $caught);

        self::assertSame(1, $runs, 'an unidentified failure is not worth running again');
        self::assertSame(0, $connection->commits);
        self::assertSame(1, $connection->rollbacks);
    }

    #[Test]
    public function theLastConflictIsRethrownAndNothingIsCommitted(): void
    {
        $connection = new RecordingConnection();

        $runs = 0;
        $thrown = null;

        try {
            $connection->transaction(function () use (&$runs): void {
                $runs++;

                throw new TransactionConflictException('serialization failure');
            }, attempts: 3, backoff: 0);
        } catch (TransactionConflictException $conflict) {
            $thrown = $conflict;
        }

        self::assertInstanceOf(TransactionConflictException::class, $thrown);
        self::assertSame('serialization failure', $thrown->getMessage());

        self::assertSame(3, $runs);
        self::assertSame(3, $connection->begins);
        self::assertSame(3, $connection->rollbacks);
        self::assertSame(0, $connection->commits);
    }

    #[Test]
    public function aNestedCallNeitherStartsNorRetriesATransaction(): void
    {
        $connection = new RecordingConnection();
        $connection->beginTransaction();

        $runs = 0;
        $thrown = null;

        try {
            $connection->transaction(function () use (&$runs): void {
                $runs++;

                throw new TransactionConflictException('deadlock');
            }, attempts: 3, backoff: 0);
        } catch (TransactionConflictException $conflict) {
            $thrown = $conflict;
        }

        self::assertInstanceOf(
            TransactionConflictException::class,
            $thrown,
            'the nested conflict reaches the outer transaction',
        );

        self::assertSame(1, $runs, 'a nested scope runs once: the outer transaction owns the retry');
        self::assertSame(1, $connection->begins, 'no transaction was opened on top of the open one');
        self::assertSame(0, $connection->rollbacks, 'and the outer transaction is left to decide');
    }

    #[Test]
    public function theBackoffSeesTheAttemptThatJustFailed(): void
    {
        $connection = new RecordingConnection();

        /** @var list<int> $attempts */
        $attempts = [];
        $thrown = null;

        try {
            $connection->transaction(
                function (): void {
                    throw new TransactionConflictException('deadlock');
                },
                attempts: 3,
                backoff: function (int $attempt, TransactionConflictException $conflict) use (&$attempts): int {
                    $attempts[] = $attempt;

                    return 0;
                },
            );
        } catch (TransactionConflictException $conflict) {
            $thrown = $conflict;
        }

        self::assertInstanceOf(TransactionConflictException::class, $thrown);
        self::assertSame([1, 2], $attempts, 'no wait after the last attempt');
    }

    #[Test]
    public function attemptsBelowOneAreRejectedBeforeAnythingRuns(): void
    {
        $connection = new RecordingConnection();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $connection->transaction(static fn (): null => null, attempts: 0);
        } finally {
            self::assertSame(0, $connection->begins, 'nothing is opened for an argument that cannot work');
        }
    }

    #[Test]
    public function sqliteBusyAndPostgresSerializationAreBothConflicts(): void
    {
        $busy = TransactionConflictException::detect(
            'users',
            new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        );
        $serialization = TransactionConflictException::detect(
            'users',
            new \PDOException('SQLSTATE[40001]: Serialization failure: 7 could not serialize access'),
        );
        $deadlock = TransactionConflictException::detect(
            'users',
            new \PDOException('SQLSTATE[40P01]: Deadlock detected: 7 deadlock detected'),
        );
        $notAConflict = TransactionConflictException::detect(
            'users',
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed'),
        );

        self::assertInstanceOf(TransactionConflictException::class, $busy);
        self::assertInstanceOf(TransactionConflictException::class, $serialization);
        self::assertInstanceOf(TransactionConflictException::class, $deadlock);
        self::assertNull($notAConflict, 'a constraint violation is the caller\'s bug, not a conflict');
        self::assertStringContainsString('"users"', $busy->getMessage());
    }

    #[Test]
    public function aRealConnectionCommitsTheAttemptThatSucceeded(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v TEXT)');

        $connection = new PdoConnection($pdo, 'users');

        $runs = 0;
        $connection->transaction(function (ConnectionInterface $db) use (&$runs): void {
            $db->execute("INSERT INTO t (v) VALUES ('attempt')");
            $runs++;

            if (1 === $runs) {
                throw new TransactionConflictException('deadlock');
            }
        }, attempts: 2, backoff: 0);

        self::assertSame(2, $runs);

        $count = $pdo->query('SELECT COUNT(*) FROM t');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn(), 'the rolled-back attempt left nothing behind');
        self::assertFalse($pdo->inTransaction());
    }

    #[Test]
    public function unitOfWorkRetriesTheOutermostScopeAndLeavesTheDepthClean(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (v TEXT)');

        $unitOfWork = new UnitOfWork(new PdoConnection($pdo, 'users'));

        $runs = 0;
        $unitOfWork->transaction(function (ConnectionInterface $db) use (&$runs): void {
            $db->execute("INSERT INTO t (v) VALUES ('attempt')");
            $runs++;

            if (1 === $runs) {
                throw new TransactionConflictException('database is locked');
            }
        }, attempts: 2, backoff: 0);

        self::assertSame(2, $runs);
        self::assertSame(0, $unitOfWork->depth(), 'the retried scope unwinds its depth entry');
        self::assertFalse($pdo->inTransaction());
    }

}
