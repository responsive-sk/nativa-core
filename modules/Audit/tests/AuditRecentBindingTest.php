<?php

declare(strict_types = 1);

namespace Modules\Audit\Tests;

use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Audit\Infrastructure\Persistence\Repositories\AuditLogRepository;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuditRecentBindingTest — the one bindValue() call site on the contract.
 *
 * `recent()` binds its LIMIT and then calls `execute()` with no arguments,
 * which is the shape a connection wrapper can silently break: forwarding an
 * empty array to PDO means "bind exactly these", so the limit arrives as
 * nothing and SQLite answers "datatype mismatch". That happened, and only the
 * booted-server gate noticed, because no test ran the statement through the
 * wrapper.
 *
 * Drives the INSTALLED copy, skips on a kernel-only tree.
 */
final class AuditRecentBindingTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Audit')) {
            $this->markTestSkipped('Audit is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/audit-recent-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new AuditDatabaseMigrator($this->pdo))->run($null);

        $this->pdo->exec(
            "INSERT INTO audit_log (id, actor_user_id, actor_name, action, target, detail, ip_address, created_at) VALUES"
            . " ('a1', 'u1', 'Admin', 'first', '/x', 'one', '127.0.0.1', '2026-01-01T00:00:00'),"
            . " ('a2', 'u1', 'Admin', 'second', '/x', 'two', '127.0.0.1', '2026-01-02T00:00:00')",
        );
    }

    protected function tearDown(): void
    {
        unset($this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function theBoundLimitSurvivesTheConnectionWrapper(): void
    {
        $entries = (new AuditLogRepository(new PdoConnection($this->pdo, 'audit')))->recent(1);

        self::assertCount(1, $entries, 'the limit is a bound integer and has to survive the wrapper');
        self::assertSame('second', $entries[0]->action(), 'recent() is newest first');
    }
}
