<?php

declare(strict_types = 1);

namespace Modules\Audit\Tests;

use Modules\Audit\Application\Services\AuditService;
use Modules\Audit\Application\Services\SecurityEventHandler;
use Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator;
use Modules\Audit\Infrastructure\Persistence\Repositories\AuditLogRepository;
use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Outbox\Interfaces\HTTP\SecurityEventServices;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SecurityEventHandlerTest — the audit row a security event becomes.
 *
 * The viewer has an "Admin" and a "Target" column, and they are different
 * facts. `security.user.changed` carries the affected user's id in its payload,
 * so treating that id as the actor wrote the same hash into both columns. The
 * account and role emitters attach the signed-in administrator separately; this
 * test pins that separation, and the authz-denial fallback that still reads its
 * actor from the payload.
 *
 * Drives the INSTALLED copy, skips on a kernel-only tree. Throwaway SQLite file,
 * never a real audit database.
 */
final class SecurityEventHandlerTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Audit')) {
            $this->markTestSkipped('Audit is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/audit-handler-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new AuditDatabaseMigrator($this->pdo))->run($null);
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
    public function userChangedKeepsTheAdministratorApartFromTheAffectedUser(): void
    {
        $targetId = str_repeat('a', 32);

        $message = OutboxMessage::create(
            SecurityEvents::USER_CHANGED,
            ['userId' => $targetId, 'operation' => SecurityEventServices::OP_UPDATE, 'ip' => '198.51.100.9'],
            [
                'actor_id'   => 'admin-1',
                'actor_name' => 'Admin Example',
                'target'     => 'jane@example.com',
            ],
        );

        $this->handler()->handle($message);

        $row = $this->row();

        self::assertSame('admin-1', $row['actor_user_id']);
        self::assertSame('Admin Example', $row['actor_name'], 'The Admin column must name the person, not the target id.');
        self::assertSame('jane@example.com', $row['target'], 'The Target column must be readable.');
        self::assertSame('User update', $row['detail']);
        self::assertSame('198.51.100.9', $row['ip_address']);
    }

    #[Test]
    public function roleChangedUsesTheSameAttribution(): void
    {
        $roleId = str_repeat('b', 32);

        $message = OutboxMessage::create(
            SecurityEvents::ROLE_CHANGED,
            ['roleId' => $roleId, 'operation' => SecurityEventServices::OP_PERMISSIONS, 'ip' => '198.51.100.10'],
            [
                'actor_id'   => 'admin-1',
                'actor_name' => 'Admin Example',
                'target'     => 'Editor',
            ],
        );

        $this->handler()->handle($message);

        $row = $this->row();

        self::assertSame('admin-1', $row['actor_user_id']);
        self::assertSame('Admin Example', $row['actor_name']);
        self::assertSame('Editor', $row['target']);
        self::assertSame('Role permissions', $row['detail']);
    }

    #[Test]
    public function authzDenialStillReadsItsActorFromThePayload(): void
    {
        $message = OutboxMessage::create(
            SecurityEvents::AUTHZ_DENIED,
            [
                'path'     => '/mark/users',
                'required' => Permissions::USERS,
                'userId'   => 'u-42',
                'roles'    => [],
                'ip'       => '198.51.100.11',
            ],
        );

        $this->handler()->handle($message);

        $row = $this->row();

        self::assertSame('u-42', $row['actor_user_id']);
        self::assertSame('u-42', $row['actor_name']);
        self::assertSame('/mark/users', $row['target']);
    }

    private function handler(): SecurityEventHandler
    {
        return new SecurityEventHandler(new AuditService(
            new AuditLogRepository(new PdoConnection($this->pdo, 'audit')),
        ));
    }

    /**
     * @return array{actor_user_id: string, actor_name: string, target: string, detail: string, ip_address: string}
     */
    private function row(): array
    {
        $row = (new PdoConnection($this->pdo, 'audit'))->query(
            'SELECT actor_user_id, actor_name, target, detail, ip_address FROM audit_log',
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'The handler wrote no audit row.');

        /** @var array{actor_user_id: string, actor_name: string, target: string, detail: string, ip_address: string} $row */
        return $row;
    }
}
