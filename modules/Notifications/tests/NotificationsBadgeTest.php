<?php

declare(strict_types = 1);

namespace Modules\Notifications\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Notifications\Application\Services\NotificationsBadge;
use Modules\Notifications\Infrastructure\Persistence\Migrations\NotificationsDatabaseMigrator;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NotificationsBadgeTest — the sidebar number belongs to one person.
 *
 * A badge on an admin item is rendered on every `/mark` page, so two things have
 * to be true at once: it shows THIS user's unread count (not the team's, not the
 * event count), and it never blanks or fatals the navigation when it cannot
 * answer. Both are asserted here, against a throwaway database.
 *
 * The session is written the way the kernel writes it — `$_SESSION['auth']['user_id']`
 * — because that is the key `SignedInUser` reads and `AuthService` writes. It is
 * saved and restored around each test: a test that leaked a session would sign
 * in every file that ran after it.
 */
final class NotificationsBadgeTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
            $this->markTestSkipped('Notifications is not installed.');
        }

        $this->session = $_SESSION ?? [];

        $this->tmpDir = sys_get_temp_dir() . '/nativa-notifications-badge-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_NOTIFICATIONS=' . $this->tmpDir . '/notifications.db');
        DatabaseConnectionManager::resetInstance();
        NotificationsServices::reset();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/notifications.db');
        (new NotificationsDatabaseMigrator($pdo))->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        if (is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
            putenv('DB_NOTIFICATIONS');
            DatabaseConnectionManager::resetInstance();
            NotificationsServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        $_SESSION = $this->session;

        parent::tearDown();
    }

    #[Test]
    public function itAnswersForTheInboxSurface(): void
    {
        self::assertSame(
            '/mark/notifications',
            (new NotificationsBadge())->navHref(),
            'the badge must key on the nav href, not the generated nav item id',
        );
    }

    #[Test]
    public function itCountsTheSignedInUsersUnreadRows(): void
    {
        $this->signIn('user-a');

        NotificationsServices::inbox()->push(['user-a', 'user-b'], 'comment', 'First', 'Body', '/mark/comments');
        NotificationsServices::inbox()->push(['user-a'], 'question', 'Second', 'Body', '/mark/questions');

        self::assertSame(2, (new NotificationsBadge())->count());
    }

    #[Test]
    public function anotherUsersUnreadRowsAreNotCounted(): void
    {
        $this->signIn('user-a');

        NotificationsServices::inbox()->push(['user-b'], 'comment', 'Theirs', 'Body', '/mark/comments');

        self::assertSame(0, (new NotificationsBadge())->count());
    }

    #[Test]
    public function withoutASessionItCountsNothing(): void
    {
        NotificationsServices::inbox()->push(['user-a'], 'comment', 'First', 'Body', '/mark/comments');

        $_SESSION = [];

        self::assertSame(
            0,
            (new NotificationsBadge())->count(),
            'a page reached without a session has no inbox to count',
        );
    }

    /**
     * The sidebar renders on every admin page, including one opened before this
     * module has been migrated. A missing table must answer zero, not blank the
     * navigation around it.
     */
    #[Test]
    public function aMissingTableAnswersZeroInsteadOfThrowing(): void
    {
        $this->signIn('user-a');

        putenv('DB_NOTIFICATIONS=' . $this->tmpDir . '/never-migrated.db');
        DatabaseConnectionManager::resetInstance();
        NotificationsServices::reset();

        self::assertSame(0, (new NotificationsBadge())->count());
    }

    private function signIn(string $userId): void
    {
        $_SESSION['auth']['user_id'] = $userId;
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
