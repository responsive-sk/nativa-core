<?php

declare(strict_types = 1);

namespace Modules\Questions\Tests;

use Modules\Questions\Domain\Entities\Question;
use Modules\Questions\Interfaces\HTTP\Actions\QuestionsAdminAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * QuestionsAdminStatusCountsTest — the inbox counts its statuses by the name
 * their owner declares.
 *
 * Task 2.2 of the const audit measured two literal maps in
 * `QuestionsAdminAction`: `groupCard()` and `summary()` both started from
 * `['pending' => 0, 'approved' => 0, 'rejected' => 0]`. `Question` already
 * owns those three names (`STATUS_PENDING`, `STATUS_APPROVED`,
 * `STATUS_REJECTED`), so a rename would leave both headers showing `0` for a
 * status that had just been renamed — silently, because the array keys are
 * strings and PHP never reports a missing key here.
 *
 * The map now comes from `emptyStatusCounts()`, keyed by the constants. This
 * test holds two things:
 *
 *   1. **The keys are the owner's statuses**, in order, at zero. That is what
 *      makes the helper the single starting point for both headers.
 *   2. **The action spells no status as a string literal.** The first check
 *      alone would still pass if a future call site copied the old literal
 *      map again; the tokenizer scan fails on that copy. Comments are separate
 *      tokens, so the prose above `emptyStatusCounts()` does not count.
 *
 * Deliberately NOT covered here: the SQL DDL in `QuestionsDatabaseMigrator`
 * (a schema string, not a PHP vocabulary reference) and Outbox's own
 * `'pending'` (a different vocabulary that happens to share the spelling).
 * The plan excludes both by name.
 *
 * Like every module test it drives the INSTALLED slice and skips when the
 * module is not installed.
 */
final class QuestionsAdminStatusCountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Questions')) {
            $this->markTestSkipped('Questions is not installed.');
        }
    }

    /** @return list<string> */
    private static function ownedStatuses(): array
    {
        return [
            Question::STATUS_PENDING,
            Question::STATUS_APPROVED,
            Question::STATUS_REJECTED,
        ];
    }

    #[Test]
    public function theZeroedCountersAreTheOwnersStatuses(): void
    {
        $method = new ReflectionMethod(QuestionsAdminAction::class, 'emptyStatusCounts');

        $expected = array_fill_keys(self::ownedStatuses(), 0);

        self::assertSame(
            $expected,
            $method->invoke(null),
            'The inbox counters must start from every status Question owns, in the same order.'
            . ' A status missing here is a header that never mentions it; a status added here'
            . ' first is a vocabulary Question does not own.',
        );
    }

    #[Test]
    public function theActionSpellsNoStatusAsAStringLiteral(): void
    {
        $file = (new ReflectionClass(QuestionsAdminAction::class))->getFileName();

        if (!is_string($file)) {
            self::fail('QuestionsAdminAction has no source file to read.');
        }

        $source = file_get_contents($file);

        if (false === $source) {
            self::fail('QuestionsAdminAction source could not be read: ' . $file);
        }

        $owned = self::ownedStatuses();
        $offenders = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }

            // The action's status strings are ASCII with no escapes; the
            // surrounding quotes are all that has to come off.
            $value = substr($token[1], 1, -1);

            if (in_array($value, $owned, true)) {
                $offenders[] = sprintf('  line %d: %s', $token[2], $token[1]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "QuestionsAdminAction spells a Question status as a literal:\n"
            . implode("\n", $offenders)
            . "\n\nRead `Question::STATUS_*` instead. The literal compiles and the"
            . ' counter silently becomes zero the day the owner renames the value.',
        );
    }
}
