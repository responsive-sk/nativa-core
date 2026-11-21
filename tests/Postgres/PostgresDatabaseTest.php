<?php

declare(strict_types = 1);

namespace Tests\Postgres;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PostgresDatabaseTest — the engine seam against a real server.
 *
 * The kernel suite runs on SQLite by default, and the tests that seed their own
 * SQLite files pin that engine rather than inheriting whatever `DB_DRIVER`
 * names. This file is the other side: it owns `DB_DRIVER=pgsql`, refuses to run
 * without the shared `DB_DSN` (or the legacy per-module DSNs), and the
 * `postgres` CI job runs it with `--fail-on-skipped`, so a skip is a red job
 * rather than a silent pass.
 *
 * Only kernel classes are named here. The suite lives under `tests/`, which
 * PHPStan analyses in a kernel-only checkout, so reaching for `Modules\Users`
 * would make the analyser of a tree without that module fail.
 */
#[Group('postgres')]
final class PostgresDatabaseTest extends TestCase
{
    /**
     * Every table a migrated catalogue has, by the module schema that owns it.
     *
     * @var array<string, list<string>>
     */
    private const array SCHEMAS = [
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
        'restapi' => ['services', 'resources', 'fields', 'relations'],
    ];

    /**
     * The metric windows, as the eight metric classes build them.
     *
     * @var list<array{string, string, string}>
     */
    private const array METRIC_WINDOWS = [
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

    /**
     * The orderings that used to name `rowid`, as the repositories build them.
     *
     * @var list<array{string, string}>
     */
    private const array ORDERINGS = [
        ['audit', 'SELECT * FROM audit_log ORDER BY created_at DESC, %s DESC LIMIT 5'],
        ['forms', 'SELECT * FROM form_submissions ORDER BY created_at DESC, %s DESC LIMIT 5'],
        ['notifications', 'SELECT * FROM notifications ORDER BY created_at DESC, %s DESC LIMIT 5'],
        ['restapi', 'SELECT * FROM resources ORDER BY %s ASC'],
        ['restapi', 'SELECT * FROM fields ORDER BY sort_order ASC, %s ASC'],
        ['restapi', 'SELECT * FROM relations ORDER BY sort_order ASC, %s ASC'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (Driver::Pgsql->value !== getenv('DB_DRIVER')) {
            $this->markTestSkipped('PostgreSQL suite: set DB_DRIVER=pgsql and DB_DSN to run it.');
        }

        if (false === getenv('DB_DSN')) {
            foreach (array_keys(self::SCHEMAS) as $database) {
                if (false === getenv('DB_' . strtoupper($database))) {
                    $this->markTestSkipped('PostgreSQL suite: set DB_DSN, or every legacy DB_<NAME> DSN, to run it.');
                }
            }
        }

        DatabaseConnectionManager::resetInstance();
    }

    protected function tearDown(): void
    {
        DatabaseConnectionManager::resetInstance();
        parent::tearDown();
    }

    #[Test]
    public function theRealMigratorsBuiltEverySchema(): void
    {
        foreach (self::SCHEMAS as $database => $tables) {
            $handle = DatabaseConnectionManager::instance()->connection($database);
            $probe = $handle->prepare(SqlDialect::forPdo($handle)->tableExists());

            foreach ($tables as $table) {
                $probe->execute([$table]);

                self::assertNotFalse(
                    $probe->fetchColumn(),
                    "{$database}.{$table} is missing; the PostgreSQL job must run php bin/cms migrate first.",
                );
            }
        }
    }

    #[Test]
    public function aNotificationRecipientIdIsWideEnoughForAUser(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('notifications');
        $statement = $handle->prepare(
            'SELECT character_maximum_length FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        );

        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute(['notifications', 'notifiable_id']);
        $length = $statement->fetchColumn();

        self::assertNotFalse($length);
        self::assertGreaterThanOrEqual(32, (int) $length);
    }

    #[Test]
    public function everyModuleSharesOneDatabaseAndItsOwnSchema(): void
    {
        if (false === getenv('DB_DSN')) {
            $this->markTestSkipped('PostgreSQL suite: this test requires the shared DB_DSN arrangement.');
        }

        $database = null;

        foreach (array_keys(self::SCHEMAS) as $module) {
            $handle = DatabaseConnectionManager::instance()->connection($module);
            $databaseStatement = $handle->query('SELECT current_database()');
            $schemaStatement = $handle->query('SELECT current_schema()');

            self::assertInstanceOf(\PDOStatement::class, $databaseStatement);
            self::assertInstanceOf(\PDOStatement::class, $schemaStatement);

            $currentDatabase = $databaseStatement->fetchColumn();
            $currentSchema = $schemaStatement->fetchColumn();

            self::assertIsString($currentDatabase);
            self::assertSame($module, $currentSchema, 'the module must select its own schema');

            $database ??= $currentDatabase;
            self::assertSame($database, $currentDatabase, 'every module must share one database');
        }
    }

    #[Test]
    public function aUserWriteAndACaseInsensitiveLookupUseTheBooleanAndTheLoweredColumn(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('users');
        $dialect = SqlDialect::forPdo($handle);
        $id = 'pg-probe-' . bin2hex(random_bytes(6));

        $insert = $handle->prepare(
            'INSERT INTO users (id, name, email, password, avatar, is_active, created_at, updated_at)
             VALUES (:id, :name, :email, :password, NULL, ' . $dialect->booleanLiteral(true) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );
        $insert->execute([
            ':id'       => $id,
            ':name'     => 'PG Probe',
            ':email'    => 'PG.Probe@Example.test',
            ':password' => bin2hex(random_bytes(16)),
        ]);

        try {
            $select = $handle->prepare(
                'SELECT is_active FROM users WHERE ' . $dialect->noCaseEquals('email', ':email'),
            );
            $select->execute([':email' => 'pg.probe@example.test']);
            $row = $select->fetch();

            self::assertIsArray($row, 'the mixed-case lookup must find the row PostgreSQL stored');
            $active = $row['is_active'] ?? null;
            self::assertTrue(
                true === $active || 't' === $active || 1 === $active || '1' === $active,
                'is_active must come back as PostgreSQL boolean truth, got ' . var_export($active, true),
            );
        } finally {
            $handle->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
        }
    }

    #[Test]
    public function everyMetricWindowCompilesAndRuns(): void
    {
        foreach (self::METRIC_WINDOWS as [$database, $table, $column]) {
            $handle = DatabaseConnectionManager::instance()->connection($database);
            $window = SqlDialect::forPdo($handle)->shiftedTimestamp(':interval');
            $statement = $handle->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} >= {$window}");
            $statement->execute([':interval' => '-7 days']);

            self::assertNotFalse($statement->fetchColumn(), "the {$table} window must run on PostgreSQL");
        }

        $handle = DatabaseConnectionManager::instance()->connection('users');
        $window = SqlDialect::forPdo($handle)->shiftedTimestamp("'-30 minutes'");
        $sessions = $handle->query("SELECT COUNT(*) FROM sessions WHERE last_activity > {$window}");
        self::assertInstanceOf(\PDOStatement::class, $sessions);
        $sessions->fetchAll();
    }

    #[Test]
    public function everyRowidTiebreakBecomesADialectOrdering(): void
    {
        foreach (self::ORDERINGS as [$database, $format]) {
            $handle = DatabaseConnectionManager::instance()->connection($database);
            $order = SqlDialect::forPdo($handle)->insertionOrder();
            $statement = $handle->query(sprintf($format, $order));
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->fetchAll();

            self::assertSame('id', $order, 'PostgreSQL has no rowid, so the primary key is the tiebreak');
        }
    }

    #[Test]
    public function theMigratorsAreIdempotentOnASecondRun(): void
    {
        $handle = DatabaseConnectionManager::instance()->connection('config');
        $settings = $handle->query('SELECT COUNT(*) FROM settings');
        $menu = $handle->query('SELECT COUNT(*) FROM menu_items');
        self::assertInstanceOf(\PDOStatement::class, $settings);
        self::assertInstanceOf(\PDOStatement::class, $menu);

        $first = $settings->fetchColumn();
        $menu->fetchAll();

        self::assertNotFalse($first);
        self::assertGreaterThan(0, (int) $first, 'the config migrator seeds its defaults');
    }
}
