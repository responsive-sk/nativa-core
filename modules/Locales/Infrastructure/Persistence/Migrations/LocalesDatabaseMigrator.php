<?php

declare(strict_types = 1);

namespace Modules\Locales\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Locales\Infrastructure\Persistence\SeedData;
use Modules\Locales\Infrastructure\Persistence\Statement;

/**
 * Locales Database Migrator — creates the locales.db schema.
 *
 * Table: translations (group-prefixed key + locale + value + group).
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS); the seed
 * uses INSERT OR IGNORE so re-running never duplicates rows.
 */
#[AsMigrator(module: 'locales', table: 'translations')]
final readonly class LocalesDatabaseMigrator implements MigratorInterface
{
    private ConnectionInterface $connection;

    public function __construct(private \PDO $pdo)
    {
        // DDL stays on the raw handle, which is why the migrator keeps `\PDO`.
        // The seed binds values, so it goes through the contract the
        // repository uses, on the one connection this migrator owns.
        $this->connection = new PdoConnection($pdo, 'locales');
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating locales.db...</info>');

        $this->createTranslationsTable();
        $this->createIndexes();

        $seeded = $this->seedDefaultTranslations();

        $output->writeln(sprintf(
            '<info>  ✓ Created table: translations (%d defaults seeded)</info>',
            $seeded,
        ));
    }

    /**
     * Translations table — one row per (key, locale) pair.
     */
    private function createTranslationsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS translations (
                    id VARCHAR(26) PRIMARY KEY,
                    translation_key VARCHAR(200) NOT NULL,
                    locale VARCHAR(10) NOT NULL,
                    value TEXT NOT NULL DEFAULT '',
                    group_name VARCHAR(50) DEFAULT 'general',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(translation_key, locale)
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_key ON translations(translation_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_locale ON translations(locale)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_group ON translations(group_name)');
    }

    /**
     * Seed default translations via INSERT OR IGNORE (idempotent).
     */
    private function seedDefaultTranslations(): int
    {
        $stmt = Statement::prepare($this->connection,
            'INSERT OR IGNORE INTO translations (id, translation_key, locale, value, group_name, created_at, updated_at)
             VALUES (:id, :key, :locale, :value, :group, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );

        $inserted = 0;

        foreach (SeedData::getDefaults() as $entry) {
            foreach (['sk_SK', 'en_US'] as $locale) {
                $stmt->execute([
                    ':id'     => bin2hex(random_bytes(13)),
                    ':key'    => $entry['key'],
                    ':locale' => $locale,
                    ':value'  => $entry[$locale],
                    ':group'  => $entry['group'],
                ]);

                if ($stmt->rowCount() > 0) {
                    $inserted++;
                }
            }
        }

        return $inserted;
    }
}
