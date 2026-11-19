<?php

declare(strict_types = 1);

namespace Modules\Locales\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use Modules\Locales\Infrastructure\Persistence\SeedData;
use Modules\Locales\Infrastructure\Persistence\Statement;

/**
 * Locales Database Migrator — creates the locales.db schema.
 *
 * Table: translations (group-prefixed key + locale + value + group).
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS); the seed
 * uses the dialect's conflict clause so re-running never duplicates rows.
 */
#[AsMigrator(module: 'locales', table: 'translations')]
final readonly class LocalesDatabaseMigrator implements MigratorInterface
{
    private ConnectionInterface $connection;

    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        // DDL stays on the raw handle, which is why the migrator keeps `\PDO`.
        // The seed binds values, so it goes through the contract the
        // repository uses, on the one connection this migrator owns.
        $this->connection = new PdoConnection($pdo, 'locales');
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating locales.db...</info>');

        $this->createTranslationsTable();
        $this->createIndexes();

        $renamed = $this->renameLegacyPanelGroup();

        if ($renamed > 0) {
            $output->writeln(sprintf('<info>  ✓ renamed %d translation(s) from the `admin` group to `mark`</info>', $renamed));
        }

        $retitled = $this->renameLegacyPanelCopy();

        if ($retitled > 0) {
            $output->writeln(sprintf('<info>  ✓ retitled %d translation value(s) to `Mark`</info>', $retitled));
        }

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
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS translations (
                    id VARCHAR(26) PRIMARY KEY,
                    translation_key VARCHAR(200) NOT NULL,
                    locale VARCHAR(10) NOT NULL,
                    value TEXT NOT NULL DEFAULT '',
                    group_name VARCHAR(50) DEFAULT 'general',
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
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
     * Rename the seeded `admin.*` translation group to `mark.*` in place.
     *
     * The key and its `group_name` are data a deployment can edit, so the rows
     * are renamed, not replaced: their ids and their values survive. The UNIQUE
     * (translation_key, locale) index means a `mark.*` row that a newer seed
     * already wrote has to be kept and the legacy duplicate dropped first —
     * otherwise the rename fails on the constraint.
     */
    private function renameLegacyPanelGroup(): int
    {
        $delete = Statement::prepare($this->connection,
            "DELETE FROM translations
             WHERE translation_key LIKE 'admin.%'
               AND EXISTS (
                   SELECT 1 FROM translations AS mark
                   WHERE mark.translation_key = " . $this->dialect->concat("'mark.'", 'substr(translations.translation_key, 7)') . "
                     AND mark.locale = translations.locale
               )",
        );
        $delete->execute();

        $update = Statement::prepare($this->connection,
            "UPDATE translations
             SET translation_key = " . $this->dialect->concat("'mark.'", 'substr(translation_key, 7)') . ",
                 group_name = 'mark',
                 updated_at = CURRENT_TIMESTAMP
             WHERE translation_key LIKE 'admin.%'",
        );
        $update->execute();

        return $update->rowCount();
    }

    /**
     * Retitle the seeded public copy that said `Admin` to `Mark`.
     *
     * The keys stay as they are (`nav.admin`, `home.cta_admin`,
     * `ms.nav_admin`, `mag.nav_admin`, `ms.cta_admin`): the words a reader sees
     * move, the contract does not. Only a row still holding the exact old
     * default is rewritten, so an operator's own translation survives.
     */
    private function renameLegacyPanelCopy(): int
    {
        $pairs = [
            ['nav.admin', 'sk_SK', 'Admin', 'Mark'],
            ['nav.admin', 'en_US', 'Admin', 'Mark'],
            ['home.cta_admin', 'sk_SK', 'Otvoriť admin', 'Otvoriť Mark'],
            ['home.cta_admin', 'en_US', 'Open admin', 'Open Mark'],
            ['mag.nav_admin', 'sk_SK', 'Admin', 'Mark'],
            ['mag.nav_admin', 'en_US', 'Admin', 'Mark'],
            ['ms.nav_admin', 'sk_SK', 'Admin', 'Mark'],
            ['ms.nav_admin', 'en_US', 'Admin', 'Mark'],
            ['ms.cta_admin', 'sk_SK', 'Admin panel', 'Mark panel'],
            ['ms.cta_admin', 'en_US', 'Admin Panel', 'Mark panel'],
        ];

        $stmt = Statement::prepare($this->connection,
            'UPDATE translations SET value = :value, updated_at = CURRENT_TIMESTAMP
             WHERE translation_key = :key AND locale = :locale AND value = :old',
        );

        $retitled = 0;

        foreach ($pairs as [$key, $locale, $old, $new]) {
            $stmt->execute([':key' => $key, ':locale' => $locale, ':old' => $old, ':value' => $new]);
            $retitled += $stmt->rowCount();
        }

        return $retitled;
    }

    /**
     * Seed default translations through the dialect's conflict clause (idempotent).
     */
    private function seedDefaultTranslations(): int
    {
        $stmt = Statement::prepare($this->connection, $this->dialect->insertOrIgnore(
            'INSERT INTO translations (id, translation_key, locale, value, group_name, created_at, updated_at)
             VALUES (:id, :key, :locale, :value, :group, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        ));

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
