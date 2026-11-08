<?php

declare(strict_types = 1);

namespace Modules\Questions\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;

/**
 * Questions Database Migrator — creates the questions.db schema.
 *
 * Table: questions (one row per reader-submitted question on a subject).
 * The subject is a discriminated pair (subject_type + subject_slug + locale)
 * so a page and an article with the same slug never collide.
 *
 * The lookup index on (subject_type, subject_slug, locale, status) is
 * NON-UNIQUE: the whole point of questions is that several readers may stall
 * at the same place, and a unique index would let the first guest lock every
 * later one out. A unique constraint over (subject_type, subject_slug, locale)
 * would read as "one question per subject" — which is exactly wrong.
 *
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS), so re-running
 * never fails or duplicates.
 */
#[AsMigrator(module: 'questions', table: 'questions')]
final readonly class QuestionsDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating questions.db...</info>');

        $this->createQuestionsTable();
        $this->createIndexes();

        $output->writeln('<info>  ✓ Created table: questions</info>');
    }

    private function createQuestionsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS questions (
                    id              VARCHAR(32) PRIMARY KEY,
                    subject_type    VARCHAR(12) NOT NULL CHECK (subject_type IN ('article', 'page')),
                    subject_slug    VARCHAR(200) NOT NULL,
                    locale          VARCHAR(10) NOT NULL,
                    text            TEXT NOT NULL,
                    attempted_answer TEXT,
                    display_name    VARCHAR(120) NOT NULL,
                    author_user_id  VARCHAR(64) DEFAULT NULL,
                    status          VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
                    ip_address      VARCHAR(45) DEFAULT NULL,
                    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createIndexes(): void
    {
        // NON-UNIQUE: several readers may stall at the same subject+locale+status.
        // Adding `id` to the constraint would be a no-op (id is already the PK),
        // and adding status makes it a useless unique row — the mistake this
        // section exists to name is a unique index over the natural key.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_subject ON questions(subject_type, subject_slug, locale, status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_status ON questions(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_subject_all ON questions(subject_type, subject_slug, locale)');
    }
}
