<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Exceptions\ConstraintFailure;
use Modules\Database\Exceptions\ForeignKeyViolationException;
use Modules\Database\Exceptions\UniqueViolationException;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConstraintFailureTest — the driver's two constraint errors become domain
 * failures, and everything else passes through untouched.
 *
 * SQLite answers both with SQLSTATE 23000 and driver code 19, so the message
 * is what separates them there; PostgreSQL names them 23505 and 23503. The
 * PostgreSQL shapes are asserted from synthetic exceptions, because no server
 * is reachable in this suite, and the real SQLite shapes are executed.
 */
final class ConstraintFailureTest extends TestCase
{
    #[Test]
    public function aDirectDuplicateWriteRaisesTheUniqueViolation(): void
    {
        $connection = $this->connection();

        try {
            $connection->execute('INSERT INTO parent (id) VALUES (1)');
            self::fail('the duplicate insert had to raise');
        } catch (UniqueViolationException $violation) {
            self::assertStringContainsString('probe', $violation->getMessage());
            self::assertInstanceOf(\PDOException::class, $violation->getPrevious());
        }
    }

    #[Test]
    public function aPreparedDuplicateWriteRaisesTheSameFailure(): void
    {
        $connection = $this->connection();
        $statement = $connection->prepare('INSERT INTO parent (id) VALUES (?)');

        $statement->execute([2]);

        $raised = null;

        try {
            $statement->execute([2]);
        } catch (UniqueViolationException $violation) {
            $raised = $violation;
        }

        self::assertNotNull($raised, 'the duplicate prepared insert had to raise');
        self::assertStringContainsString('UNIQUE constraint failed', $raised->getMessage());
    }

    #[Test]
    public function anOrphanedReferenceRaisesTheForeignKeyViolation(): void
    {
        $connection = $this->connection();

        $this->expectException(ForeignKeyViolationException::class);

        $connection->execute('INSERT INTO child (id, parent_id) VALUES (10, 99)');
    }

    #[Test]
    public function thePostgresqlStatesAreClassifiedWithoutAServer(): void
    {
        $unique = self::synthetic('23505', 7, 'duplicate key value violates unique constraint "parent_pkey"');
        $foreignKey = self::synthetic('23503', 7, 'insert or update violates foreign key constraint "child_parent_fk"');

        self::assertInstanceOf(UniqueViolationException::class, UniqueViolationException::detect('probe', $unique));
        self::assertInstanceOf(ForeignKeyViolationException::class, ForeignKeyViolationException::detect('probe', $foreignKey));
        self::assertNull(ForeignKeyViolationException::detect('probe', $unique));
        self::assertNull(UniqueViolationException::detect('probe', $foreignKey));
    }

    #[Test]
    public function theMariaDbForeignKeyStatesAreClassifiedWithoutAServer(): void
    {
        $insert = self::synthetic('23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails');
        $delete = self::synthetic('23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails');

        self::assertInstanceOf(ForeignKeyViolationException::class, ForeignKeyViolationException::detect('probe', $insert));
        self::assertInstanceOf(ForeignKeyViolationException::class, ForeignKeyViolationException::detect('probe', $delete));
    }

    #[Test]
    public function aSerializationConflictIsNotAConstraintFailure(): void
    {
        $conflict = self::synthetic('40001', 7, 'could not serialize access');

        self::assertSame($conflict, ConstraintFailure::translate('probe', $conflict));
    }

    #[Test]
    public function anUnrecognisedFailureIsReturnedUnchanged(): void
    {
        $failure = new \PDOException('SQLSTATE[42S02]: Base table or view not found');

        self::assertSame($failure, ConstraintFailure::translate('probe', $failure));
    }

    private function connection(): PdoConnection
    {
        return new PdoConnection($this->pdo(), 'probe');
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $pdo->exec(
            'CREATE TABLE child ('
            . 'id INTEGER PRIMARY KEY, '
            . 'parent_id INTEGER NOT NULL REFERENCES parent(id)'
            . ')',
        );
        $pdo->exec('INSERT INTO parent (id) VALUES (1)');

        return $pdo;
    }

    private static function synthetic(string $sqlState, int $driverCode, string $message): \PDOException
    {
        $failure = new \PDOException(sprintf('SQLSTATE[%s]: %s', $sqlState, $message));
        $failure->errorInfo = [$sqlState, $driverCode, $message];

        return $failure;
    }
}
