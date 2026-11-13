<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Users\Domain\Contracts\Mailer;
use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Notifications\Application\Services\NotificationInbox;
use Modules\Notifications\Infrastructure\Persistence\Migrations\NotificationsDatabaseMigrator;
use Modules\Notifications\Infrastructure\Persistence\Repositories\SqliteNotificationRepository;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;
use Modules\Users\Application\Services\AdminNotificationHandler;
use Modules\Users\Application\Services\AdminNotifier;
use Modules\Users\Application\Services\NotificationPayload;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminInboxWiringTest — who a `notify.admin` job is addressed to.
 *
 * `AdminNotificationTest` asserts the MAIL; this file asserts the other half of
 * the same job: the durable inbox row. The recipient list is Users' own answer
 * (`User::canAccessPanel()` — the admin role OR `mark.access`), so the fixtures
 * are four accounts that must come out differently:
 *
 * - the admin role, which bypasses permission checks;
 * - a role that carries `mark.access` explicitly;
 * - an account with neither, which must not be addressed at all;
 * - an inactive panel user, who must not be addressed either — a disabled
 *   account whose mailbox is closed and whose inbox nobody opens.
 *
 * Two failure orders are asserted as well, because both are decisions rather
 * than accidents: a mail the transport refuses still leaves its row, and an
 * inbox that cannot be written at all still lets the mail go. Between an inbox
 * row and the notification itself, the notification is the one the operator
 * cannot lose twice.
 *
 * Throwaway `users.db` + `notifications.db` through the env overrides, schema
 * built by the modules' own migrators.
 */
final class AdminInboxWiringTest extends TestCase
{
    private string $tmpDir = '';

    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Notifications'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-inbox-wiring-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        putenv('DB_NOTIFICATIONS=' . $this->tmpDir . '/notifications.db');
        DatabaseConnectionManager::resetInstance();
        NotificationsServices::reset();

        $usersPdo = new \PDO('sqlite:' . $this->tmpDir . '/users.db');
        (new UsersDatabaseMigrator($usersPdo))->run($this->quietOutput());

        (new NotificationsDatabaseMigrator(new \PDO('sqlite:' . $this->tmpDir . '/notifications.db')))
            ->run($this->quietOutput());

        $this->users = new UserRepository(new PdoConnection($usersPdo, 'users'));
        $this->seedAccounts(new PdoConnection($usersPdo, 'users'));
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
            putenv('DB_USERS');
            putenv('DB_NOTIFICATIONS');
            DatabaseConnectionManager::resetInstance();
            NotificationsServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function aCommentLandsInTheInboxOfEveryPanelUser(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer)->handle(
            NotificationPayload::comment('why-a-port', 'Eva', 'This helped, thanks.', '/mark/comments'),
        );

        $inbox = NotificationsServices::inbox();

        self::assertSame(1, $inbox->unreadCount('user-admin'), 'the admin role bypasses the permission check');
        self::assertSame(1, $inbox->unreadCount('user-writer'), 'a role carrying mark.access is a panel user');
        self::assertSame(0, $inbox->unreadCount('user-outsider'), 'an account with no panel access is not a recipient');
        self::assertSame(0, $inbox->unreadCount('user-disabled'), 'a disabled account is not a recipient');
        self::assertSame(1, $inbox->unreadCount('user-admin'), 'and the mail is still sent');
        self::assertCount(1, $mailer->calls);
    }

    #[Test]
    public function theRowCarriesTheMailWordingAndTheProducersPanelPath(): void
    {
        $this->handler($this->recorder())->handle(
            NotificationPayload::comment('why-a-port', 'Eva', 'This helped, thanks.', '/mark/comments'),
        );

        $row = NotificationsServices::inbox()->recent('user-admin')[0];

        self::assertSame('comment', $row->kind());
        self::assertSame('New comment awaiting moderation', $row->title());
        self::assertStringContainsString('Article: why-a-port', $row->body());
        self::assertStringContainsString('From: Eva', $row->body());
        self::assertStringContainsString('This helped, thanks.', $row->body());
        self::assertStringNotContainsString(
            'Moderate:',
            $row->body(),
            'the inbox row carries the root-relative path; only the mail gets an absolute link',
        );
        self::assertSame('/mark/comments', $row->href());
    }

    #[Test]
    public function aQuestionIsAddressedTheSameWay(): void
    {
        $this->handler($this->recorder())->handle(
            NotificationPayload::question('article', 'why-a-port', 'Peter', 'What about the second port?', '/mark/moderation'),
        );

        $row = NotificationsServices::inbox()->recent('user-writer')[0];

        self::assertSame('question', $row->kind());
        self::assertSame('New question awaiting moderation', $row->title());
        self::assertSame('/mark/moderation', $row->href());
    }

    #[Test]
    public function anUnknownKindWritesNoRow(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer)->handle(['kind' => 'telegram']);

        self::assertSame([], $mailer->calls);
        self::assertSame(0, NotificationsServices::inbox()->total('user-admin'));
    }

    #[Test]
    public function aMailTheTransportRefusesStillLeavesItsRow(): void
    {
        $mailer = $this->recorder(false);

        $sent = $this->handler($mailer)->handle(
            NotificationPayload::comment('why-a-port', 'Eva', 'Hello.', '/mark/comments'),
        );

        self::assertFalse($sent);
        self::assertSame(
            1,
            NotificationsServices::inbox()->unreadCount('user-admin'),
            'the inbox is the record that survives a transport that refuses the message',
        );
    }

    /**
     * The inbox is a second channel, not a precondition: a tree whose
     * notifications table was never migrated still tells the operator.
     */
    #[Test]
    public function anInboxThatCannotBeWrittenDoesNotCostTheMail(): void
    {
        $mailer = $this->recorder();
        $unmigrated = new NotificationInbox(
            new SqliteNotificationRepository(
                new PdoConnection(new \PDO('sqlite:' . $this->tmpDir . '/never-migrated.db'), 'notifications'),
            ),
        );

        $sent = (new AdminNotificationHandler(
            new AdminNotifier($mailer, 'owner@example.test', 'https://app.example.test'),
            $unmigrated,
        ))->handle(NotificationPayload::comment('why-a-port', 'Eva', 'Hello.', '/mark/comments'));

        self::assertTrue($sent);
        self::assertCount(1, $mailer->calls);
    }

    private function handler(Mailer $mailer): AdminNotificationHandler
    {
        return new AdminNotificationHandler(
            new AdminNotifier($mailer, 'owner@example.test', 'https://app.example.test'),
            NotificationsServices::inbox(),
        );
    }

    /**
     * Four accounts that differ only in what the panel gate says about them.
     *
     * Roles and permissions are built through the repository's own `ensure*`
     * methods, so the pivot rows carry the ids the schema expects rather than
     * ones this test guessed.
     */
    private function seedAccounts(ConnectionInterface $db): void
    {
        $roles = new RoleRepository($db);

        $adminRole = $roles->ensureRole('role-admin', 'admin', 'Super-user');
        $writerRole = $roles->ensureRole('role-writer', 'writer', 'Moderator');
        $markAccess = $roles->ensurePermission('perm-mark', Permissions::MARK_ACCESS, 'Open the panel', 'panel');

        Statement::prepare($db, 'INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
            ->execute([$writerRole, $markAccess]);

        foreach ([
            ['user-admin', 'Ada', 'ada@example.test'],
            ['user-writer', 'Wanda', 'wanda@example.test'],
            ['user-outsider', 'Otto', 'otto@example.test'],
            ['user-disabled', 'Dana', 'dana@example.test'],
        ] as [$id, $name, $email]) {
            $this->users->insert([
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'password' => password_hash('secret-1234', PASSWORD_DEFAULT),
            ]);
        }

        $this->users->assignRole('user-admin', $adminRole);
        $this->users->assignRole('user-writer', $writerRole);
        $this->users->assignRole('user-disabled', $adminRole);

        Statement::prepare($db, 'UPDATE users SET is_active = 0 WHERE id = ?')->execute(['user-disabled']);
    }

    private function recorder(bool $accepts = true): object
    {
        return new class ($accepts) implements Mailer {
            /** @var list<array{string, string, string}> */
            public array $calls = [];

            public function __construct(private readonly bool $accepts)
            {
            }

            public function send(string $to, string $subject, string $text): bool
            {
                $this->calls[] = [$to, $subject, $text];

                return $this->accepts;
            }
        };
    }

    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
