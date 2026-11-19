<?php

declare(strict_types = 1);

namespace Tests\MariaDb;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MariaDbDatabaseTest — the engine seam against a real MariaDB server.
 *
 * MariaDB has no schema inside a database, so the isolation boundary is one
 * database per module (`<DB_DATABASE_PREFIX><module>`). The suite uses the same
 * migrated catalogue as the PostgreSQL suite and refuses to run without the
 * shared `DB_DSN`; the `mariadb` CI job runs it with `--fail-on-skipped`.
 *
 * Only kernel classes are named here. The suite lives under `tests/`, which
 * PHPStan analyses in a kernel-only checkout, so reaching for a module class
 * would make the analyser of a tree without that module fail.
 */
#[Group('mariadb')]
final class MariaDbDatabaseTest extends TestCase
{
    /**
     * Every table a migrated catalogue has, by the module database that owns it.
     *
     * @return array<string, list<string>>
     */
    private static function schemas(): array
    {
        return [
            'users' => ['users', 'sessions', 'password_reset_tokens', 'remember_tokens'],
            'audit' => ['audit_log'],
            'config' => ['settings', 'menu_items', 'design_tokens'],
            'queue' => ['jobs', 'failed_jobs'],
            'outbox' => ['outbox_messages'],
            'blog' => ['article_views'],
            'comments' => ['comments'],
            'forms' => ['forms', 'form_submissions'],
            'locales' => ['translations'],
            'media' => ['assets', 'media_folders'],
            'notifications' => ['notifications'],
            'questions' => ['questions'],
            'restpanel' => ['services', 'resources', 'fields', 'relations'],
        ];
    }

    /**
     * The metric windows, as the metric classes build them.
     *
     * @return list<array{string, string, string}>
     */
    private static function metricWindows(): array
    {
        return [
            ['users', 'users', 'created_at'],
            ['users', 'sessions', 'created_at'],
            ['audit', 'audit_log', 'created_at'],
            ['comments', 'comments', 'created_at'],
            ['forms', 'form_submissions', 'created_at'],
            ['blog', 'article_views', 'viewed_at'],
            ['media', 'assets', 'created_at'],
            ['outbox', 'outbox_messages', 'processed_at'],
            ['queue', 'jobs', 'created_at'],
        ];
    }

    /**
     * The orderings that used to name `rowid`, as the repositories build them.
     *
     * @return list<array{string, string}>
     */
    private static function orderings(): array
    {
        return [
            ['audit', 'SELECT * FROM audit_log ORDER BY created_at DESC, %s DESC LIMIT 5'],
            ['forms', 'SELECT * FROM form_submissions ORDER BY created_at DESC, %s DESC LIMIT 5'],
            ['notifications', 'SELECT * FROM notifications ORDER BY created_at DESC, %s DESC LIMIT 5'],
            ['restpanel', 'SELECT * FROM resources ORDER BY %s ASC'],
            ['restpanel', 'SELECT * FROM fields ORDER BY sort_order ASC, %s ASC'],
            ['restpanel', 'SELECT * FROM relations ORDER BY sort_order ASC, %s ASC'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (Driver::MariaDb->value !== getenv('DB_DRIVER')) {
            $this->markTestSkipped('MariaDB suite: set DB_DRIVER=mariadb and DB_DSN to run it.');
        }

        if (false === getenv('DB_DSN')) {
            $this->markTestSkipped('MariaDB suite: set DB_DSN to the server DSN.');
        }

        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        DatabaseConnectionManager::resetInstance();
        parent::tearDown();
    }

    #[Test]
    public function everyModuleHasItsOwnDatabaseWithItsTables(): void
    {
        $databases = [];

        foreach (self::schemas() as $module => $tables) {
            $handle = DatabaseConnectionManager::instance()->connection($module);
            $databaseStatement = $handle->query('SELECT DATABASE()');
            self::assertInstanceOf(\PDOStatement::class, $databaseStatement);
            $database = $databaseStatement->fetchColumn();

            self::assertSame($this->expectedDatabase($module), $database);
            $databases[$database] = true;

            $probe = $handle->prepare(SqlDialect::forPdo($handle)->tableExists());

            foreach ($tables as $table) {
                $probe->execute([$table]);

                self::assertNotFalse(
                    $probe->fetchColumn(),
                    "{$database}.{$table} is missing; the MariaDB job must run php bin/cms migrate first.",
                );
            }
        }

        self::assertCount(count(self::schemas()), $databases);
    }

    #[Test]
    public function aNotificationRecipientIdIsWideEnoughForAUser(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('notifications');
        $statement = $handle->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        );

        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute(['notifications', 'notifiable_id']);
        $length = $statement->fetchColumn();

        self::assertNotFalse($length);
        self::assertGreaterThanOrEqual(32, (int) $length);
    }

    #[Test]
    public function aUserWriteAndACaseInsensitiveLookupUseTheMariaDbForms(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('users');
        $dialect = SqlDialect::forPdo($handle);
        $id = bin2hex(random_bytes(16));
        $email = 'Maria.Probe.' . bin2hex(random_bytes(4)) . '@Example.test';

        $handle->prepare(
            'INSERT INTO users (id, name, email, password, avatar, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, ' . $dialect->booleanLiteral(true) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        )->execute([$id, 'Maria Probe', $email, bin2hex(random_bytes(16))]);

        try {
            $select = $handle->prepare(
                'SELECT is_active FROM users WHERE ' . $dialect->noCaseEquals('email', ':email'),
            );
            $select->execute([':email' => strtolower($email)]);
            $row = $select->fetch();

            self::assertIsArray($row, 'the lowered lookup must find the row MariaDB stored');
            self::assertIsInt($row['is_active']);
            self::assertSame(1, $row['is_active']);
        } finally {
            $handle->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    #[Test]
    public function everyMetricWindowCompilesAndRuns(): void
    {
        foreach (self::metricWindows() as [$database, $table, $column]) {
            $handle = DatabaseConnectionManager::instance()->connection($database);
            $window = SqlDialect::forPdo($handle)->shiftedTimestamp(':interval');
            $statement = $handle->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} >= {$window}");
            $statement->execute([':interval' => '-7 days']);

            self::assertNotFalse($statement->fetchColumn(), "the {$table} window must run on MariaDB");
        }

        $handle = DatabaseConnectionManager::instance()->connection('users');
        $window = SqlDialect::forPdo($handle)->shiftedTimestamp("'-30 minutes'");
        $sessions = $handle->query("SELECT COUNT(*) FROM sessions WHERE last_activity > {$window}");
        self::assertInstanceOf(\PDOStatement::class, $sessions);
        $sessions->fetchAll();
    }

    #[Test]
    public function aBoundIntervalUsesOnePlaceholderAndTheRightUnit(): void
    {
        $dialect = SqlDialect::forDriver(Driver::MariaDb);

        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(:interval, ' ', 1) AS SIGNED) DAY)",
            $dialect->shiftedTimestamp(':interval'),
        );
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX('-30 minutes', ' ', 1) AS SIGNED) MINUTE)",
            $dialect->shiftedTimestamp("'-30 minutes'"),
        );
        self::assertSame(
            "DATE_ADD(NOW(), INTERVAL CAST(SUBSTRING_INDEX(CONCAT('-', ?), ' ', 1) AS SIGNED) DAY)",
            $dialect->shiftedTimestamp("'-' || ?"),
        );
    }

    #[Test]
    public function everyRowidTiebreakBecomesThePrimaryKey(): void
    {
        foreach (self::orderings() as [$database, $format]) {
            $handle = DatabaseConnectionManager::instance()->connection($database);
            $order = SqlDialect::forPdo($handle)->insertionOrder();
            $statement = $handle->query(sprintf($format, $order));

            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->fetchAll();
            self::assertSame('id', $order, 'MariaDB has no rowid, so the primary key is the tiebreak');
        }
    }

    #[Test]
    public function theDialectSeesMariaDbsBooleanAndSchemaMetadata(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('users');
        $dialect = SqlDialect::forPdo($handle);

        self::assertTrue($dialect->isMariaDb());
        self::assertSame('mariadb', DatabaseConnectionManager::instance()->connect('users')->driverName());
        self::assertFalse($dialect->supportsReturning($handle));
        self::assertContains('sessions', $dialect->tableNames($handle));
        self::assertSame('boolean', $dialect->columnTypes($handle, 'users')['is_active'] ?? null);
        self::assertContains('users', $dialect->foreignKeys($handle)['user_roles'] ?? []);
    }

    #[Test]
    public function insertIgnoreAndAnUpsertAreIdempotentOnTheRealServer(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('media');
        $dialect = SqlDialect::forPdo($handle);
        $path = 'probe-' . bin2hex(random_bytes(8));
        $quoted = $handle->quote($path);
        $insert = $dialect->insertOrIgnore('INSERT INTO media_folders (path) VALUES (' . $quoted . ')');

        $handle->exec($insert);
        $handle->exec($insert);

        $count = $handle->query('SELECT COUNT(*) FROM media_folders WHERE path = ' . $quoted);
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(1, (int) $count->fetchColumn());

        $handle->prepare('DELETE FROM media_folders WHERE path = ?')->execute([$path]);

        $config = DatabaseConnectionManager::instance()->connection('config');
        $configDialect = SqlDialect::forPdo($config);
        $key = 'probe_' . bin2hex(random_bytes(8));
        $upsert = $configDialect->upsert(
            'INSERT INTO settings (id, setting_key, setting_value, value_type, group_name, is_public, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            'setting_key',
            'setting_value = excluded.setting_value',
        );
        $statement = $config->prepare($upsert);

        try {
            $statement->execute([bin2hex(random_bytes(16)), $key, 'one', 'string', 'general', 0]);
            $statement->execute([bin2hex(random_bytes(16)), $key, 'two', 'string', 'general', 0]);

            $select = $config->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $select->execute([$key]);
            self::assertSame('two', $select->fetchColumn());
        } finally {
            $config->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        }
    }

    #[Test]
    public function theAuditTriggerRefusesAWriteOnMariaDb(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('audit');
        $id = bin2hex(random_bytes(16));

        $handle->prepare(
            'INSERT INTO audit_log (id, actor_user_id, actor_name, action) VALUES (?, ?, ?, ?)',
        )->execute([$id, 'probe', 'Probe', 'probe']);

        try {
            $handle->prepare('UPDATE audit_log SET action = ? WHERE id = ?')->execute(['changed', $id]);
            self::fail('audit_log is immutable, so the update had to be refused');
        } catch (\PDOException $failure) {
            self::assertStringContainsString('immutable', $failure->getMessage());
        }
    }

    private function expectedDatabase(string $module): string
    {
        $prefix = getenv('DB_DATABASE_PREFIX');

        return (false === $prefix || '' === $prefix ? 'nativa_' : $prefix) . $module;
    }
}
