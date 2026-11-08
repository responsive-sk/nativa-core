<?php

declare(strict_types = 1);

namespace Modules\Comments\Tests;

use Modules\Comments\Application\Services\CommentsMetric;
use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Infrastructure\Persistence\Statement;
use Modules\Comments\Interfaces\HTTP\Actions\CommentsAdminAction;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * CommentsStatusVocabularyTest — the dashboard card and the moderation queue
 * count and compare by the status names `Comment` owns.
 *
 * Task 2.2 of the const audit found the same defect here that it fixed in
 * `QuestionsAdminAction`: `CommentsMetric` keyed its donut and breakdown by
 * `'pending'` / `'approved'` / `'rejected'`, and `CommentsAdminAction`
 * compared the same strings directly. `Comment` already owns them
 * (`STATUS_PENDING`, `STATUS_APPROVED`, `STATUS_REJECTED`), so a rename would
 * leave the metric at zero and the approve/reject buttons on every row — no
 * type error, no failing assertion, just a quietly wrong panel.
 *
 * Two checks, because either alone can pass on a broken tree:
 *
 *   1. **Behaviour:** an in-memory `comments` table with two pending, one
 *      approved and one rejected returns exactly the owner's three keys with
 *      those counts. A missing key would drop that status from the donut.
 *   2. **Source:** neither class spells a status as a string literal — the
 *      tokenizer ignores comments, so the prose explaining the vocabulary is
 *      not an offender, while a new hand-written map is.
 *
 * SQL DDL stays out of scope by measurement: the migrator's
 * `CHECK (status IN (...))` is a schema string, not a PHP reference to the
 * entity's vocabulary.
 *
 * Like every module test it drives the INSTALLED slice and skips when the
 * module is not installed.
 */
final class CommentsStatusVocabularyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Comments')) {
            $this->markTestSkipped('Comments is not installed.');
        }
    }

    /** @return list<string> */
    private static function ownedStatuses(): array
    {
        return [
            Comment::STATUS_PENDING,
            Comment::STATUS_APPROVED,
            Comment::STATUS_REJECTED,
        ];
    }

    #[Test]
    public function theMetricCountsUnderTheOwnersStatusKeys(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE comments (status TEXT NOT NULL, created_at TEXT NOT NULL)');

        $insert = Statement::prepare($pdo, 'INSERT INTO comments (status, created_at) VALUES (:status, datetime(\'now\'))');

        foreach ([
            Comment::STATUS_PENDING,
            Comment::STATUS_PENDING,
            Comment::STATUS_APPROVED,
            Comment::STATUS_REJECTED,
        ] as $status) {
            $insert->execute([':status' => $status]);
        }

        $method = new ReflectionMethod(CommentsMetric::class, 'countByStatus');
        $counts = $method->invoke(new CommentsMetric(), $pdo, '-7 days');

        self::assertSame(
            [
                Comment::STATUS_PENDING => 2,
                Comment::STATUS_APPROVED => 1,
                Comment::STATUS_REJECTED => 1,
            ],
            $counts,
            'The metric must key its counts by every status Comment owns — a missing key'
            . ' drops that slice from the donut and the subtitle without an error.',
        );
    }

    #[Test]
    public function theMetricAndTheAdminActionSpellNoStatusLiteral(): void
    {
        $offenders = [];

        foreach ([CommentsMetric::class, CommentsAdminAction::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();

            if (!is_string($file)) {
                self::fail($class . ' has no source file to read.');
            }

            $source = file_get_contents($file);

            if (false === $source) {
                self::fail($class . ' source could not be read: ' . $file);
            }

            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                    continue;
                }

                // The status strings are ASCII with no escapes; the surrounding
                // quotes are all that has to come off.
                $value = substr($token[1], 1, -1);

                if (in_array($value, self::ownedStatuses(), true)) {
                    $offenders[] = sprintf('  %s:%d %s', $class, $token[2], $token[1]);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Comments spells a Comment status as a literal:\n"
            . implode("\n", $offenders)
            . "\n\nRead `Comment::STATUS_*` instead. The literal compiles and the"
            . ' metric or the row actions become silently wrong the day the owner renames it.',
        );
    }
}
