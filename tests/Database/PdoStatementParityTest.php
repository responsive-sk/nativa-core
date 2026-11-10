<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PdoStatementParityTest — the wrapper answers what the driver answers.
 *
 * The bug this file exists for shipped and passed the whole suite: PdoStatement
 * forwarded the contract's default `[]` to `PDOStatement::execute()`, and PDO
 * reads an array argument as "bind exactly these", so every `bindValue()` was
 * dropped. `/mark/audit` answered 500 with "datatype mismatch" and only the
 * booted-server gate noticed.
 *
 * The call sites that use `bindValue()` and nothing else are the exposure:
 * Audit (moved), Blog, Media, Queue, Forms. This file replays the binding
 * shape of each through the wrapper beside the same statements on a bare
 * `\PDO`, so a wrapper that diverges from the driver fails here instead of in
 * a request. The remaining modules still type-hint `\PDO`, which is why the
 * shapes are replayed rather than the methods called: a class that takes
 * `\PDO` cannot be handed a `PdoConnection` until its round moves it.
 *
 * The shapes stop here on purpose: this file ships in the kernel export, where
 * no module exists, so the end-to-end version of the Audit call site lives in
 * that module's own tests.
 */
final class PdoStatementParityTest extends TestCase
{
    #[Test]
    public function aBoundLimitReturnsTheSameRowsAsTheDriver(): void
    {
        // Audit's recent(), Media's all(), Queue's listFailed(), Forms'
        // recentPayloads(): one integer bound to LIMIT, then execute().
        $this->assertSameAsDriver(static function (\PDO|ConnectionInterface $db): array {
            $statement = self::prepared($db, 'SELECT id FROM rows ORDER BY id LIMIT :limit');
            $statement->bindValue(':limit', 2, \PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        });
    }

    #[Test]
    public function boundLimitAndOffsetReturnTheSameWindowAsTheDriver(): void
    {
        // Blog's search paging and Media's filtered listing: two integers.
        $this->assertSameAsDriver(static function (\PDO|ConnectionInterface $db): array {
            $statement = self::prepared($db, 'SELECT id FROM rows ORDER BY id LIMIT :limit OFFSET :offset');
            $statement->bindValue(':limit', 2, \PDO::PARAM_INT);
            $statement->bindValue(':offset', 2, \PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        });
    }

    #[Test]
    public function aBoundStringBesideABoundIntegerMatchesTheDriver(): void
    {
        // Queue's reserved listing (':queue' string, ':limit' int) and Forms'
        // recentPayloads (':id' string, ':limit' int).
        $this->assertSameAsDriver(static function (\PDO|ConnectionInterface $db): array {
            $statement = self::prepared($db, 'SELECT id FROM rows WHERE name = :name ORDER BY id LIMIT :limit');
            $statement->bindValue(':name', 'bee');
            $statement->bindValue(':limit', 1, \PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        });
    }

    #[Test]
    public function parametersBoundInALoopMatchTheDriver(): void
    {
        // Media's bindFilterParams(): the statement never names its filters, it
        // binds whatever the caller filtered on.
        $this->assertSameAsDriver(static function (\PDO|ConnectionInterface $db): array {
            $params = [':name' => 'cee', ':floor' => 2];
            $statement = self::prepared($db, 'SELECT id FROM rows WHERE name = :name AND id > :floor');

            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value);
            }

            $statement->execute();

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        });
    }

    #[Test]
    public function aScalarBoundThenFetchedAsAColumnMatchesTheDriver(): void
    {
        $this->assertSameAsDriver(static function (\PDO|ConnectionInterface $db): array {
            $statement = self::prepared($db, 'SELECT COUNT(*) FROM rows WHERE id > :floor');
            $statement->bindValue(':floor', 2, \PDO::PARAM_INT);
            $statement->execute();

            return [$statement->fetchColumn()];
        });
    }

    /**
     * Run one binding shape against a bare `\PDO` and against the wrapper over
     * an identical database, and fail when the two disagree.
     *
     * @param callable(\PDO|ConnectionInterface): array<mixed> $shape
     */
    private function assertSameAsDriver(callable $shape): void
    {
        $driver = $shape($this->pdo());
        $wrapped = $shape(new PdoConnection($this->pdo(), 'parity'));

        self::assertSame($driver, $wrapped, 'the wrapper must answer what the driver answers');
    }

    /**
     * `prepare()` on the union, narrowed once.
     *
     * `\PDO::prepare()` still advertises `PDOStatement|false`, and the point of
     * the comparison is that the shapes are the same code on both sides, so
     * the branch lives here rather than in every shape.
     */
    private static function prepared(\PDO|ConnectionInterface $db, string $sql): \PDOStatement|StatementInterface
    {
        if ($db instanceof \PDO) {
            $statement = $db->prepare($sql);

            if (false === $statement) {
                throw new \RuntimeException('Failed to prepare: ' . $sql);
            }

            return $statement;
        }

        return $db->prepare($sql);
    }

    /**
     * A fresh database per call, so the two halves of a comparison cannot
     * share state and a difference is the wrapper's, not the data's.
     */
    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE rows (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO rows (id, name) VALUES (1, 'ay'), (2, 'bee'), (3, 'cee'), (4, 'dee'), (5, 'ee')");

        return $pdo;
    }
}
