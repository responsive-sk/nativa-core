<?php

declare(strict_types = 1);

namespace Tests\Database;

use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PdoStatementTest — the contract's `execute([])` means "use what is bound".
 *
 * PDO treats an argument as "bind exactly these", so forwarding the contract's
 * default empty array would silently drop every `bindValue()`. That is not a
 * theoretical edge: the audit log's `recent()` binds `LIMIT :limit` that way,
 * and the first request to `/mark/audit` failed with "datatype mismatch"
 * because the limit arrived as nothing at all.
 */
final class PdoStatementTest extends TestCase
{
    #[Test]
    public function aValueBoundWithBindValueSurvivesExecuteWithoutArguments(): void
    {
        $connection = $this->connection();
        $statement = $connection->prepare('SELECT value FROM probe ORDER BY rowid LIMIT :limit');

        $statement->bindValue(':limit', 1, \PDO::PARAM_INT);
        $statement->execute();

        self::assertSame(['one'], array_column($statement->fetchAll(), 'value'));
    }

    #[Test]
    public function executeStillBindsTheArgumentsItIsGiven(): void
    {
        $connection = $this->connection();
        $statement = $connection->prepare('SELECT value FROM probe WHERE value = ?');

        $statement->execute(['two']);

        self::assertSame(['two'], array_column($statement->fetchAll(), 'value'));
    }

    private function connection(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE probe (value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO probe (value) VALUES ('one'), ('two')");

        return new PdoConnection($pdo, 'probe');
    }
}
