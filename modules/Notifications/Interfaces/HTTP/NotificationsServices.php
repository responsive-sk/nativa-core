<?php

declare(strict_types = 1);

namespace Modules\Notifications\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Notifications\Application\Services\NotificationInbox;
use Modules\Notifications\Infrastructure\Persistence\Repositories\SqliteNotificationRepository;

/**
 * NotificationsServices — lazy cached factories for the inbox.
 *
 * A single factory rather than a container: this module has one service and one
 * database, and a static accessor is what every sibling module exposes
 * (`OutboxServices`, `QuestionsServices`, `LocalesServices`).
 *
 * `reset()` exists for the tests, which point `DB_NOTIFICATIONS` at a throwaway
 * file and must not inherit the previous connection.
 */
final class NotificationsServices
{
    private static ?NotificationInbox $inbox = null;

    public static function inbox(): NotificationInbox
    {
        return self::$inbox ??= new NotificationInbox(
            new SqliteNotificationRepository(
                DatabaseConnectionManager::instance()->connection('notifications'),
            ),
        );
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$inbox = null;
    }
}
