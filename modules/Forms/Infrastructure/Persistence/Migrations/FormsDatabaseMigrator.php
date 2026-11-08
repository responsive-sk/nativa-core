<?php

declare(strict_types = 1);

namespace Modules\Forms\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Forms Database Migrator — creates the forms.db schema.
 *
 * Two tables:
 *
 *  - `forms` — one definition per `(slug, locale)`. The unique index is over
 *    the PAIR: the same reader-facing name exists once per language.
 *  - `form_submissions` — one row per accepted submission, carrying the
 *    definition id so a rename keeps its answers attached and a delete takes
 *    them with it.
 *
 * `published` is the only flag, and it is the page front matter's own word for
 * the same idea. Submission state is `status` with two named values, not a
 * second boolean, per `docs/boolean-vocabulary.md`.
 *
 * SQLite foreign keys are enabled on the connection, but this module does not
 * rely on `ON DELETE CASCADE` for the destructive path: the repository deletes
 * submissions and the definition inside one transaction, so the behaviour is
 * the same on a connection with foreign keys off (a plain `sqlite3` shell, a
 * test that builds its own PDO).
 *
 * Every statement is idempotent, so re-running never fails or duplicates.
 */
#[AsMigrator(module: 'forms', table: 'forms')]
final readonly class FormsDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating forms.db...</info>');

        $this->createFormsTable();
        $this->createSubmissionsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: forms</info>');
        $output->writeln('<info>  ✓ Created table: form_submissions</info>');
    }

    private function createFormsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS forms (
                    id              VARCHAR(32) PRIMARY KEY,
                    slug            VARCHAR(80) NOT NULL,
                    locale          VARCHAR(10) NOT NULL,
                    title           VARCHAR(120) NOT NULL,
                    intro           VARCHAR(500) NOT NULL DEFAULT '',
                    success_message VARCHAR(300) NOT NULL DEFAULT '',
                    fields          TEXT NOT NULL,
                    published       INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0, 1)),
                    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createSubmissionsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS form_submissions (
                    id          VARCHAR(32) PRIMARY KEY,
                    form_id     VARCHAR(32) NOT NULL,
                    form_slug   VARCHAR(80) NOT NULL,
                    locale      VARCHAR(10) NOT NULL,
                    payload     TEXT NOT NULL,
                    status      VARCHAR(12) NOT NULL DEFAULT 'new'
                                CHECK (status IN ('new', 'handled')),
                    ip_address  VARCHAR(45) DEFAULT NULL,
                    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                    handled_at  DATETIME DEFAULT NULL
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_forms_slug_locale ON forms(slug, locale)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_forms_locale ON forms(locale, published)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form ON form_submissions(form_id, status, created_at)');
    }
}
