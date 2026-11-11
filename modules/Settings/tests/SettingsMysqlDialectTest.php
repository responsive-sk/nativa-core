<?php

declare(strict_types = 1);

namespace Modules\Settings\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use Modules\Settings\Infrastructure\Persistence\Repositories\SettingsRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SettingsMysqlDialectTest — the schema and the two upserts that carried SQL
 * SQLite accepts and MariaDB does not.
 *
 * Skipped unless `NATIVA_MYSQL_DSN` names a database, because the module's
 * suite still has to run on a machine that only has SQLite. Everything the
 * migration test touches is created by the migrator and dropped again.
 */
final class SettingsMysqlDialectTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('NATIVA_MYSQL_DSN');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set NATIVA_MYSQL_DSN to run the MariaDB/MySQL dialect tests.');
        }

        if (!\in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is not loaded; run PHP with -d extension=pdo_mysql.');
        }

        DatabaseConnectionManager::resetInstance();
        putenv('DB_CONFIG_DSN=' . $dsn);

        $this->pdo = DatabaseConnectionManager::instance()->connection('config');
        $this->dropSchema();
    }

    protected function tearDown(): void
    {
        // tearDown() also runs when setUp() skipped before the handle existed.
        if (isset($this->pdo)) {
            $this->dropSchema();
        }

        putenv('DB_CONFIG_DSN');
        DatabaseConnectionManager::resetInstance();
    }

    #[Test]
    public function theSchemaAndTheUpsertsRunOnMysql(): void
    {
        $this->migrate();

        // Re-running is the property the MySQL branch has to keep: DDL commits
        // implicitly, so a failed second run cannot be rescued by a transaction.
        $this->migrate();

        $db = DatabaseConnectionManager::instance()->connect('config');
        $settings = new SettingsRepository($db);

        self::assertGreaterThan(0, (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn());

        $settings->set('site_name', 'First', 'general', true);
        $settings->set('site_name', 'Second', 'general', true);

        self::assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'site_name'")->fetchColumn(),
        );
        self::assertSame('Second', $settings->get('site_name'));

        $settings->setDesignToken('accent', ['hex' => '#111']);
        $settings->setDesignToken('accent', ['hex' => '#222']);

        self::assertSame(['hex' => '#222'], $settings->designTokens()['accent']);
    }

    private function migrate(): void
    {
        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new SettingsDatabaseMigrator($this->pdo))->run($output);
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS design_tokens');
        $this->pdo->exec('DROP TABLE IF EXISTS menu_items');
        $this->pdo->exec('DROP TABLE IF EXISTS settings');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
