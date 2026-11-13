<?php

declare(strict_types = 1);

namespace Modules\Outbox\Tests;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Infrastructure\Persistence\Statement;
use Modules\Outbox\Interfaces\CLI\Command\ProcessOutboxCommand;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProcessOutboxCommandTest — the operator command `bin/cms outbox:process`
 * drives, beside the code it covers (module test contract: drives the
 * INSTALLED copy, skips on a kernel-only tree).
 *
 * The command used to be a `Symfony\...\Command`, so in a tree with no
 * `vendor/` — which is every production deployment, and the `admin-smoke` and
 * `boot` CI jobs — it could not be loaded at all. It is plain PHP now, and the
 * contract is argv in, an exit code out. That is what these tests pin, with the
 * exit code carrying the weight: `outbox:process` exists so a cron entry or a
 * probe notices a poison message instead of silently rotating past it.
 *
 * Every test points `DB_OUTBOX` at a throwaway SQLite file and resets the two
 * singletons that cache the path — resetting only `AppPaths` leaves the
 * connection manager handing back the previous test's open handle, which is
 * how "the entry must not register" passed in a sibling test once.
 */
final class ProcessOutboxCommandTest extends TestCase
{
    private ?string $dbFile = null;

    private ?string $previousDbOutbox = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Outbox')) {
            $this->markTestSkipped('Outbox is not installed.');
        }

        $this->previousDbOutbox = getenv('DB_OUTBOX') ?: null;
        $this->dbFile = sys_get_temp_dir() . '/outbox-cmd-' . bin2hex(random_bytes(8)) . '.db';

        putenv('DB_OUTBOX=' . $this->dbFile);
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
        OutboxServices::reset();

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new OutboxDatabaseMigrator(DatabaseConnectionManager::instance()->connection('outbox')))->run($null);
    }

    protected function tearDown(): void
    {
        if (isset($this->dbFile)) {
            OutboxServices::reset();

            foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            if (null === $this->previousDbOutbox) {
                putenv('DB_OUTBOX');
            } else {
                putenv('DB_OUTBOX=' . $this->previousDbOutbox);
            }

            AppPaths::resetInstance();
            DatabaseConnectionManager::resetInstance();
        }

        parent::tearDown();
    }

    #[Test]
    public function anEmptyOutboxExitsZeroAndReportsBothSides(): void
    {
        $output = $this->collector();

        $exit = ProcessOutboxCommand::run(['outbox:process'], $output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Outbox before: pending=0 processing=0 processed=0 failed=0', $output->text());
        $this->assertStringContainsString('Delivered: processed=0 failed=0', $output->text());
        $this->assertStringContainsString('Outbox after: pending=0', $output->text());
    }

    #[Test]
    public function itNamesTheHandlerTypesThisPassCouldDeliver(): void
    {
        $output = $this->collector();

        ProcessOutboxCommand::run(['outbox:process'], $output);

        // The command registers the handler its OWN module can see. The
        // Audit↔Outbox pairing is the one it cannot, which is what
        // `wire_module_handlers()` is for and why `bin/cms` calls it before
        // handing over the argv — without it every `security.*` message would
        // be parked as unroutable by a CLI drain.
        $this->assertStringContainsString('article.published', $output->text());
        $this->assertStringContainsString('article.unpublished', $output->text());
    }

    #[Test]
    public function aMessageNobodyHandlesIsDeliveredAsFailedAndExitsOne(): void
    {
        OutboxServices::repository()->save(OutboxMessage::create('smoke.unregistered', ['from' => 'test']));

        $output = $this->collector();

        $exit = ProcessOutboxCommand::run(['outbox:process'], $output);

        $this->assertSame(1, $exit, 'a poison message must redden the run, not rotate past it.');
        $this->assertStringContainsString('Delivered: processed=0 failed=1', $output->text());
    }

    #[Test]
    public function theSpaceSeparatedLimitIsReadTheWayTheEqualsFormIs(): void
    {
        OutboxServices::repository()->save(OutboxMessage::create('smoke.unregistered', []));

        $equals = $this->collector();
        ProcessOutboxCommand::run(['outbox:process', '--limit=7'], $equals);

        $space = $this->collector();
        ProcessOutboxCommand::run(['outbox:process', '--limit', '7'], $space);

        $this->assertStringContainsString('(limit 7)', $equals->text());
        $this->assertStringContainsString('(limit 7)', $space->text(), '--limit 7 is the form a cron line already written out there uses.');
    }

    #[Test]
    public function pruneRemovesProcessedRowsAndNoPruneLeavesThem(): void
    {
        // Seeded with raw SQL because `OutboxMessage` has a private
        // constructor and `withProcessed()` stamps `processed_at = now` — and
        // `cleanProcessed('7 days')` keeps a row processed *less* than seven
        // days ago. Backdating is the only way to reach the branch, and
        // OutboxAdminListTest seeds the same table the same way.
        $this->seedProcessed('2000-01-01 00:00:00');

        $pruned = $this->collector();
        ProcessOutboxCommand::run(['outbox:process', '--prune-interval=7 days'], $pruned);

        $this->assertStringContainsString('Pruned: 1 processed message(s)', $pruned->text());
        $this->assertSame(0, OutboxServices::repository()->countTotal('processed'), 'the row is really gone.');

        // A row processed NOW is inside every sane interval, so it stays —
        // which is the half of the contract that keeps an operator from
        // deleting evidence they still want to read.
        $this->seedProcessed(date('Y-m-d H:i:s'));
        $this->assertSame(1, OutboxServices::repository()->countTotal('processed'));

        $kept = $this->collector();
        ProcessOutboxCommand::run(['outbox:process', '--no-prune'], $kept);

        $this->assertStringNotContainsString('Pruned:', $kept->text());
        $this->assertSame(1, OutboxServices::repository()->countTotal('processed'), 'the row is still there.');
    }

    /**
     * Insert one `processed` row with a chosen `processed_at`.
     */
    private function seedProcessed(string $processedAt): void
    {
        $statement = Statement::prepare(
            DatabaseConnectionManager::instance()->connect('outbox'),
            'INSERT INTO outbox_messages (id, event_type, event_payload, status, attempts, max_attempts, processed_at, created_at)'
            . ' VALUES (:id, :type, :payload, \'processed\', 1, 5, :processed, :processed)',
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':type' => 'article.published',
            ':payload' => '{}',
            ':processed' => $processedAt,
        ]);
    }

    #[Test]
    public function anUnknownOptionExitsOneAndNamesIt(): void
    {
        $output = $this->collector();

        $exit = ProcessOutboxCommand::run(['outbox:process', '--lmit=5'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown option "--lmit"', $output->text());
    }

    #[Test]
    public function aValueOptionWithNoValueExitsOne(): void
    {
        $output = $this->collector();

        $exit = ProcessOutboxCommand::run(['outbox:process', '--limit'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('needs a value', $output->text());
    }

    #[Test]
    public function theCommandExtendsNothingAndNeedsNoComposerPackage(): void
    {
        // The whole reason this class was rewritten: `symfony/console` is
        // require-dev only, so a command extending it is unloadable in a tree
        // with no vendor/ — which is every production deployment, and the
        // admin-smoke and boot jobs.
        //
        // A PHPUnit run always HAS vendor/, so it cannot prove the absence by
        // running the command; what it CAN prove is the shape that causes it.
        // Extending nothing means there is no class left to fail to load.
        $this->assertSame(
            [],
            class_parents(ProcessOutboxCommand::class),
            'extending Symfony\...\Command is exactly what made this unloadable without vendor/',
        );

        $source = file_get_contents(
            APP_BASE_PATH . '/src/Modules/Outbox/Interfaces/CLI/Command/ProcessOutboxCommand.php',
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use Symfony\\', $source);
    }

    private function collector(): CliOutput
    {
        return new class implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }

            public function text(): string
            {
                return implode("\n", $this->lines);
            }
        };
    }
}