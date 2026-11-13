<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Application\Services\DatabaseQueue;
use Modules\Queue\Application\Services\HandlerRegistry;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Queue\Infrastructure\Persistence\Statement;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DatabaseQueueTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree). Covers the
 * HandlerRegistry too (Task 7 needs no new file): registered handlers run
 * with args, unknown handlers fail the job instead of fataling.
 *
 * Every test runs against a throwaway SQLite file — never a real module
 * database.
 */
final class DatabaseQueueTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private PdoConnection $connection;

    private HandlerRegistry $handlers;

    private DatabaseQueue $queue;

    /** @var int the clock, in seconds — advanced by hand instead of sleeping */
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/queue-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator($this->pdo))->run($null);

        // The migrator keeps the raw handle; services take the contract.
        $this->connection = new PdoConnection($this->pdo, 'queue');

        // `DatabaseQueue` has accepted `?callable $now` since it shipped and no
        // test ever passed it — the seam existed, unexercised. A retry released
        // with a backoff lands in the future, where `findAvailable()`'s
        // `available_at <= :now` cannot see it, so pinning the retry timing
        // needs a clock. `FileTaskMutexTest` is the same trick for the same
        // reason: time is injected so expiry is asserted, not waited for.
        $this->now = 1_700_000_000;
        $this->handlers = new HandlerRegistry();
        $this->queue = new DatabaseQueue($this->connection, $this->handlers, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        unset($this->queue, $this->pdo);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function pushReserveDeleteRoundTrip(): void
    {
        $this->handlers->register('mail.send', static fn (array $args): string => $args['to']);

        $id = $this->queue->push('mail.send', ['to' => 'a@example.com']);

        $this->assertSame(32, strlen($id));

        $job = $this->queue->reserve();

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('mail.send', $job->handler);
        $this->assertSame(['to' => 'a@example.com'], $job->args);
        $this->assertSame(1, $job->attempts);

        $this->queue->delete($job->id);

        $this->assertNull($this->queue->reserve());
        $this->assertSame(0, $this->countRows('jobs'));
    }

    #[Test]
    public function aFailingHandlerWaitsOutItsBackoffBeforeTheNextAttempt(): void
    {
        $calls = 0;
        $this->handlers->register('always.fails', static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('boom');
        });

        $this->queue->push('always.fails', [], maxAttempts: 2);

        // One batch, one attempt. This is the whole change: the release used to
        // write `available_at = now`, so the next loop iteration found the same
        // row again and both attempts burned inside a single `work()`.
        $result = $this->queue->work();

        $this->assertSame(['processed' => 0, 'failed' => 0], $result);
        $this->assertSame(1, $calls, 'one batch means one attempt, not max_attempts of them.');
        $this->assertSame(1, $this->countRows('jobs'), 'the job is still queued for its next turn.');

        $row = $this->jobRow();
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['reserved_at'], 'and it is released, not left reserved by a worker that is gone.');
        $this->assertSame(
            date('Y-m-d H:i:s', $this->now + 30),
            $row['available_at'],
            'the first retry waits the base delay: 30s, doubling from there.',
        );

        // Too early: the row is not reservable yet, and a batch spends nothing.
        $this->assertSame(['processed' => 0, 'failed' => 0], $this->queue->work());
        $this->assertSame(1, $calls);

        // A minute later the second attempt runs and exhausts max_attempts, so
        // this is where the job finally parks.
        $this->now += 60;
        $this->assertSame(['processed' => 0, 'failed' => 1], $this->queue->work());
        $this->assertSame(2, $calls, 'handler ran once per attempt, one batch each.');
        $this->assertSame(0, $this->countRows('jobs'));

        /** @var array<string, mixed>|false $failed */
        $failed = Statement::query($this->connection, 'SELECT * FROM failed_jobs')->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertSame('always.fails', $failed['handler']);
        $this->assertSame(2, (int) $failed['attempts']);
        $this->assertSame('boom', $failed['error']);
    }

    #[Test]
    public function theBackoffDoublesAndIsCapped(): void
    {
        // `max_attempts` has no upper bound — a producer sets it per job — so
        // an uncapped 2**n would schedule a row years out and turn a poison
        // handler into something nobody looks at again.
        $this->handlers->register('always.fails', static function (): void {
            throw new RuntimeException('boom');
        });

        $this->queue->push('always.fails', [], maxAttempts: 20);

        $expected = [30, 60, 120, 240, 480, 960, 1920, 3600, 3600];

        foreach ($expected as $index => $delay) {
            $this->assertSame(['processed' => 0, 'failed' => 0], $this->queue->work());
            $this->assertSame(
                date('Y-m-d H:i:s', $this->now + $delay),
                $this->jobRow()['available_at'],
                sprintf('retry %d waits %ds', $index + 1, $delay),
            );

            // Jump past whatever delay this attempt asked for.
            $this->now += $delay;
        }
    }

    /**
     * The single `jobs` row, which every retry assertion reads.
     *
     * @return array<string, mixed>
     */
    private function jobRow(): array
    {
        /** @var array<string, mixed>|false $row */
        $row = Statement::query($this->connection, 'SELECT * FROM jobs')->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'expected exactly one queued job');

        return $row;
    }

    #[Test]
    public function secondReserveRefusedWhileHeld(): void
    {
        $this->queue->push('mail.send', ['to' => 'a@example.com']);

        $first = $this->queue->reserve();

        $this->assertNotNull($first);

        // Same row, second contender: the guarded UPDATE matches zero rows.
        $this->assertNull($this->queue->reserve());

        $this->queue->release($first->id);

        $again = $this->queue->reserve();

        $this->assertNotNull($again);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, $again->attempts, 'attempts stay authoritative across release');
    }

    #[Test]
    public function delayedJobInvisibleUntilAvailable(): void
    {
        $this->queue->push('mail.send', [], delaySeconds: 3600);

        $this->assertNull($this->queue->reserve());
        $this->assertSame(0, $this->queue->work()['processed']);
        $this->assertSame(1, $this->countRows('jobs'), 'delayed job waits, not lost');
    }

    #[Test]
    public function unknownHandlerFailsTheJobNeverFatals(): void
    {
        $this->assertFalse($this->handlers->has('nope.missing'));

        $this->queue->push('nope.missing', ['x' => 1], maxAttempts: 1);

        $result = $this->queue->work();

        $this->assertSame(['processed' => 0, 'failed' => 1], $result);

        /** @var array<string, mixed>|false $failed */
        $failed = Statement::query($this->connection, 'SELECT * FROM failed_jobs')->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertStringContainsString('nope.missing', (string) $failed['error']);
    }

    #[Test]
    public function successfulHandlerDeletesAndCounts(): void
    {
        $seen = [];
        $this->handlers->register('track.seen', static function (array $args) use (&$seen): void {
            $seen[] = $args['n'];
        });

        $this->queue->push('track.seen', ['n' => 1]);
        $this->queue->push('track.seen', ['n' => 2]);

        $result = $this->queue->work();

        $this->assertSame(['processed' => 2, 'failed' => 0], $result);
        $this->assertSame([1, 2], $seen);
        $this->assertSame(0, $this->countRows('jobs'));
        $this->assertSame(0, $this->countRows('failed_jobs'));
    }

    #[Test]
    public function corruptPayloadParkedWithoutBlockingTheRest(): void
    {
        $this->handlers->register('ok.handler', static fn (): null => null);
        $this->queue->push('ok.handler');

        $poisonId = bin2hex(random_bytes(16));
        Statement::prepare($this->connection,
            "INSERT INTO jobs (id, queue, handler, payload, attempts, max_attempts, available_at)"
            . " VALUES (:id, 'default', 'ok.handler', '{not-json', 0, 3, '2000-01-01 00:00:00')",
        )->execute([':id' => $poisonId]);

        $result = $this->queue->work(limit: 10);

        // The good job processed, the poison row parked — the loop never spun.
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $this->countRows('jobs'));

        /** @var array<string, mixed>|false $failed */
        $failed = Statement::query($this->connection, "SELECT * FROM failed_jobs WHERE id = '{$poisonId}'")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($failed);
        $this->assertStringContainsString('Corrupt payload', (string) $failed['error']);
    }

    /**
     * Retention: `failed_jobs` grows unless something removes it, and the only
     * removal used to be an operator clicking "drop this job".
     */
    #[Test]
    public function pruneFailedRemovesOnlyTheRowsOlderThanTheInterval(): void
    {
        $this->park('recent', '-2 hours');
        $this->park('thisWeek', '-3 days');
        $this->park('lastMonth', '-30 days');
        $this->park('lastYear', '-365 days');

        // 29 days, not 30: the '-30 days' row sits exactly on the default's
        // boundary, and `<` is strict, so the default would make the expected
        // set depend on which side of "exactly one month" the clock falls.
        $this->assertSame(2, $this->queue->countFailedOlderThan('29 days'));

        $this->assertSame(2, $this->queue->pruneFailed('29 days'));
        $this->assertSame(2, $this->countRows('failed_jobs'));

        $survivors = array_column($this->queue->listFailed(10), 'id');
        $this->assertContains('recent', $survivors);
        $this->assertContains('thisWeek', $survivors);
        $this->assertNotContains('lastMonth', $survivors);
        $this->assertNotContains('lastYear', $survivors);
    }

    #[Test]
    public function aFailedJobYoungerThanTheIntervalIsNotPruned(): void
    {
        $this->park('recent', '-2 hours');

        $this->assertSame(0, $this->queue->pruneFailed('30 days'));
        $this->assertSame(1, $this->countRows('failed_jobs'));
    }

    #[Test]
    public function thePreviewCountMatchesWhatThePruneActuallyRemoves(): void
    {
        // The preview is only worth having if it does not flatter itself: it
        // runs the same comparison the DELETE does, so a count that disagreed
        // with the delete would make `--dry-run` a lie.
        foreach (['-2 hours', '-3 days', '-30 days', '-365 days'] as $offset) {
            $this->park('probe-' . $offset, $offset);
        }

        $preview = $this->queue->countFailedOlderThan('29 days');
        $removed = $this->queue->pruneFailed('29 days');

        $this->assertSame($preview, $removed);
        $this->assertSame(0, $this->queue->countFailedOlderThan('29 days'));
    }

    #[Test]
    public function workingTheQueueNeverPrunesAFailedJob(): void
    {
        // The whole point of retention being its own command: a worker run
        // leaves the evidence it parked. An operator who has not looked yet
        // keeps it, and the run that parked it still reports success.
        $this->park('old', '-365 days');

        $result = $this->queue->work(limit: 10);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $this->countRows('failed_jobs'));
    }

    private function park(string $id, string $failedAtOffset): void
    {
        Statement::prepare($this->connection,
            "INSERT INTO failed_jobs (id, queue, handler, payload, attempts, failed_at, error)"
            . " VALUES (:id, 'default', 'probe.handler', '{}', 3, datetime('now', :offset), 'probe')",
        )->execute([':id' => $id, ':offset' => $failedAtOffset]);
    }

    private function countRows(string $table): int
    {
        return (int) Statement::query($this->connection, "SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
