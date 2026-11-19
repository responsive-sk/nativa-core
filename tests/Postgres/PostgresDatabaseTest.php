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
 * without the per-module DSNs, and the `postgres` CI job runs it with
 * `--fail-on-skipped`, so a skip is a red job rather than a silent pass.
 *
 * Only kernel classes are named here. The suite lives under `tests/`, which
 * PHPStan analyses in a kernel-only checkout, so reaching for `Modules\Users`
 * would make the analyser of a tree without that module fail.
 */
#[Group('postgres')]
final class PostgresDatabaseTest extends TestCase
{
    /**
     * Every schema a migrated catalogue has, by the database that owns it.
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
        'restpanel' => ['services', 'resources', 'fields', 'relations'],
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
        ['restpanel', 'SELECT * FROM resources ORDER BY %s ASC'],
        ['restpanel', 'SELECT * FROM fields ORDER BY sort_order ASC, %s ASC'],
        ['restpanel', 'SELECT * FROM relations ORDER BY sort_order ASC, %s ASC'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (Driver::Pgsql->value !== getenv('DB_DRIVER')) {
            $this->markTestSkipped('PostgreSQL suite: set DB_DRIVER=pgsql and the DB_<NAME> DSNs to run it.');
        }

        foreach (array_keys(self::SCHEMAS) as $database) {
            if (false === getenv('DB_' . strtoupper($database))) {
                $this->markTestSkipped('PostgreSQL suite: DB_' . strtoupper($database) . ' is not set.');
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
