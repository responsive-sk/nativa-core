<?php

declare(strict_types = 1);

namespace Modules\Notifications\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Notifications\Application\Services\NotificationInbox;
use Modules\Notifications\Infrastructure\Persistence\Migrations\NotificationsDatabaseMigrator;
use Modules\Notifications\Infrastructure\Persistence\Statement;
use Modules\Notifications\Interfaces\HTTP\NotificationsServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NotificationInboxTest — the inbox is per user, and reading it is per user.
 *
 * The whole point of a notification row per recipient is that two moderators do
 * not share a read state, so every assertion here is about whose row it is: what
 * one user's list contains, what one user's count says, and whether an id
 * arriving from outside can clear somebody else's row. A fan-out that wrote one
 * row per EVENT would pass a naive test suite and fail the moment a second
 * moderator existed, which is exactly the bug this file exists to prevent.
 *
 * ## The database
 *
 * A throwaway file through `DB_NOTIFICATIONS`, schema built by this module's own
 * migrator — the pattern `MediaIndexRenderingTest` uses. The working tree's
 * `storage/data/notifications.db` is never opened.
 *
 * ## Why it lives here
 *
 * `modules/` is outside PHPStan's scope and this test names an optional module's
 * classes; a kernel-only checkout skips it (see phpunit.xml).
 */
final class NotificationInboxTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
            $this->markTestSkipped('Notifications is not installed.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-notifications-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_NOTIFICATIONS=' . $this->tmpDir . '/notifications.db');
        DatabaseConnectionManager::resetInstance();
        NotificationsServices::reset();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/notifications.db');
        (new NotificationsDatabaseMigrator($pdo))->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, so nothing here
        // assumes the module is installed or the tmp dir exists.
        if (is_dir(APP_BASE_PATH . '/src/Modules/Notifications')) {
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
    public function pushWritesOneRowPerRecipient(): void
    {
        $written = $this->inbox()->push(
            ['user-a', 'user-b'],
            'comment',
            'New comment awaiting moderation',
            "Article: why-a-port\nFrom: Eva",
            '/mark/comments',
        );

        self::assertSame(2, $written);
        self::assertSame(1, $this->inbox()->total('user-a'), 'the recipient owns a row of their own');
        self::assertSame(1, $this->inbox()->total('user-b'));
        self::assertSame(0, $this->inbox()->total('user-c'), 'a user who was not a recipient has an empty inbox');
    }

    /**
     * The recipient list comes from a user query, so it can legitimately repeat
     * an id or carry a blank one; two identical rows for one person is a badge
     * saying "2" for one event.
     */
    #[Test]
    public function pushDropsBlankAndDuplicateRecipients(): void
    {
        $written = $this->inbox()->push(['user-a', 'user-a', ''], 'comment', 'Title', 'Body', '/mark/comments');

        self::assertSame(1, $written);
        self::assertSame(1, $this->inbox()->total('user-a'));
    }

    #[Test]
    public function theInboxIsNewestFirst(): void
    {
        $this->pushAt('user-a', 'Oldest', '2026-01-01 08:00:00');
        $this->pushAt('user-a', 'Newest', '2026-01-01 12:00:00');
        $this->pushAt('user-a', 'Middle', '2026-01-01 10:00:00');

        self::assertSame(
            ['Newest', 'Middle', 'Oldest'],
            array_map(static fn ($notification): string => $notification->title(), $this->inbox()->recent('user-a')),
        );
    }

    #[Test]
    public function recentHonoursLimitAndOffset(): void
    {
        foreach (['One', 'Two', 'Three'] as $index => $title) {
            $this->pushAt('user-a', $title, '2026-01-0' . ($index + 1) . ' 08:00:00');
        }

        self::assertSame(
            ['Three'],
            array_map(static fn ($notification): string => $notification->title(), $this->inbox()->recent('user-a', 1)),
        );
        self::assertSame(
            ['Two'],
            array_map(static fn ($notification): string => $notification->title(), $this->inbox()->recent('user-a', 1, 1)),
        );
    }

    #[Test]
    public function oneUsersInboxIsInvisibleToAnother(): void
    {
        $this->inbox()->push(['user-a'], 'comment', 'For A', 'Body', '/mark/comments');
        $this->inbox()->push(['user-b'], 'question', 'For B', 'Body', '/mark/questions');

        self::assertSame(
            ['For A'],
            array_map(static fn ($notification): string => $notification->title(), $this->inbox()->recent('user-a')),
        );
        self::assertSame(1, $this->inbox()->unreadCount('user-a'));
        self::assertSame(1, $this->inbox()->unreadCount('user-b'));
    }

    /**
     * The id in the mark-read form comes from the page, which means a hand-edited
     * form can name any row. Scoping the UPDATE on the notifiable is what turns
     * that into "nothing happened".
     */
    #[Test]
    public function markReadClearsOnlyItsOwnersRow(): void
    {
        $this->inbox()->push(['user-a', 'user-b'], 'comment', 'One event', 'Body', '/mark/comments');

        $rowForA = $this->inbox()->recent('user-a')[0];

        self::assertFalse(
            $this->inbox()->markRead($rowForA->id(), 'user-b'),
            'another user must not be able to clear a row from an id in a form',
        );
        self::assertSame(1, $this->inbox()->unreadCount('user-a'));
        self::assertSame(1, $this->inbox()->unreadCount('user-b'));

        self::assertTrue($this->inbox()->markRead($rowForA->id(), 'user-a'));
        self::assertSame(0, $this->inbox()->unreadCount('user-a'));
        self::assertSame(1, $this->inbox()->unreadCount('user-b'), 'the other recipient still has work waiting');
    }

    #[Test]
    public function markingTheSameRowTwiceChangesNothing(): void
    {
        $this->inbox()->push(['user-a'], 'comment', 'One event', 'Body', '/mark/comments');
        $row = $this->inbox()->recent('user-a')[0];

        self::assertTrue($this->inbox()->markRead($row->id(), 'user-a'));
        self::assertFalse($this->inbox()->markRead($row->id(), 'user-a'), 'already read is not a change');
        self::assertSame(0, $this->inbox()->unreadCount('user-a'));
    }

    #[Test]
    public function markAllReadClearsOnlyThatInbox(): void
    {
        $this->inbox()->push(['user-a', 'user-b'], 'comment', 'First', 'Body', '/mark/comments');
        $this->inbox()->push(['user-a', 'user-b'], 'question', 'Second', 'Body', '/mark/questions');

        self::assertSame(2, $this->inbox()->markAllRead('user-a'));
        self::assertSame(0, $this->inbox()->unreadCount('user-a'));
        self::assertSame(2, $this->inbox()->total('user-a'), 'marking read keeps the history');
        self::assertSame(2, $this->inbox()->unreadCount('user-b'));

        self::assertSame(0, $this->inbox()->markAllRead('user-a'), 'a second sweep has nothing left to clear');
    }

    #[Test]
    public function aRowCarriesTheLineTheInboxRenders(): void
    {
        $this->inbox()->push(
            ['user-a'],
            'comment',
            'New comment awaiting moderation',
            "Article: why-a-port\nFrom: Eva\n\nThis helped, thanks.",
            '/mark/comments',
        );

        $row = $this->inbox()->recent('user-a')[0];

        self::assertSame('comment', $row->kind());
        self::assertSame('New comment awaiting moderation', $row->title());
        self::assertStringContainsString('From: Eva', $row->body());
        self::assertSame('/mark/comments', $row->href(), 'the inbox links to the panel path the producer owns');
        self::assertFalse($row->isRead());
        self::assertTrue($row->belongsTo('user-a'));
        self::assertFalse($row->belongsTo('user-b'));
    }

    /**
     * The facade is what the page and the badge actually call, so its wiring is
     * asserted once: the env override reaches the same file this test migrated.
     */
    #[Test]
    public function theServiceFactoryReadsTheConfiguredDatabase(): void
    {
        NotificationsServices::inbox()->push(['user-a'], 'comment', 'Through the facade', 'Body', '/mark/comments');

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/notifications.db');
        $count = Statement::query($pdo, 'SELECT COUNT(*) FROM notifications WHERE notifiable_id = \'user-a\'')->fetchColumn();

        self::assertSame(1, (int) $count);
    }

    private function inbox(): NotificationInbox
    {
        return NotificationsServices::inbox();
    }

    /**
     * Insert at a chosen timestamp: `create()` stamps "now", and ordering is the
     * one thing this file cannot assert against a clock with one-second
     * resolution.
     */
    private function pushAt(string $notifiableId, string $title, string $createdAt): void
    {
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/notifications.db');
        $stmt = Statement::prepare($pdo,
            'INSERT INTO notifications (id, notifiable_id, kind, title, body, href, read_at, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
        );
        $stmt->execute([bin2hex(random_bytes(13)), $notifiableId, 'comment', $title, 'Body', '/mark/comments', $createdAt]);
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
