<?php

declare(strict_types = 1);

namespace Modules\Queue\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Queue\Infrastructure\Persistence\Migrations\QueueDatabaseMigrator;
use Modules\Queue\Interfaces\HTTP\QueueServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QueueWorkCommandTest — the seam `bin/cms queue:work` drives, beside the code
 * it covers (module test contract: drives the INSTALLED copy, skips on a
 * kernel-only tree).
 *
 * `DatabaseQueue::work()` had NO caller in this tree before the command
 * existed — reachable only from an inline `php` heredoc inside the CI gate,
 * which is not something an operator can put in crontab. That is why the
 * semantics live HERE, on the module's own locator, and the CLI only prints:
 * exactly the split `SchedulerServices::run()` keeps for `schedule:run`.
 *
 * Every test points `DB_QUEUE` at a throwaway SQLite file and resets the three
 * singletons that cache the path — a test that delivered through the real
 * `storage/data/queue.db` would be a test that eats an operator's backlog.
 */
final class QueueWorkCommandTest extends TestCase
{
    private string $dbFile;

    private ?string $previousDbQueue = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Queue')) {
            $this->markTestSkipped('Queue is not installed.');
        }

        $this->previousDbQueue = getenv('DB_QUEUE') ?: null;
        $this->dbFile = sys_get_temp_dir() . '/queue-work-' . bin2hex(random_bytes(8)) . '.db';

        putenv('DB_QUEUE=' . $this->dbFile);
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
        QueueServices::reset();

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QueueDatabaseMigrator(DatabaseConnectionManager::instance()->connection('queue')))->run($null);
    }

    protected function tearDown(): void
    {
        // Everything below exists only if setUp got past its skip. Calling
        // `QueueServices::reset()` unguarded is an Error — "class not found" —
        // in a kernel-only tree, which is precisely the tree where this whole
        // class is supposed to skip quietly.
        if (isset($this->dbFile)) {
            QueueServices::reset();

            foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            if (null === $this->previousDbQueue) {
                putenv('DB_QUEUE');
            } else {
                putenv('DB_QUEUE=' . $this->previousDbQueue);
            }

            AppPaths::resetInstance();
            DatabaseConnectionManager::resetInstance();
        }

        parent::tearDown();
    }

    #[Test]
    public function aRoutableJobIsDeliveredAndTheRunExitsZero(): void
    {
        $seen = [];
        QueueServices::handlers()->register('smoke.known', static function (array $args) use (&$seen): void {
            $seen[] = $args;
        });

        $id = QueueServices::queue()->push('smoke.known', ['from' => 'admin-smoke']);

        $result = QueueServices::work();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, QueueServices::exitCode($result));
        $this->assertSame([['from' => 'admin-smoke']], $seen);
        $this->assertNull(QueueServices::queue()->findJob($id), 'a delivered job leaves `jobs`.');
    }

    #[Test]
    public function anUnroutableJobIsParkedAndTheRunExitsOne(): void
    {
        $id = QueueServices::queue()->push('smoke.unregistered', [], maxAttempts: 1);

        $result = QueueServices::work();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, QueueServices::exitCode($result));

        $parked = QueueServices::queue()->findFailed($id);
        $this->assertNotNull($parked, 'the job is parked, not left to be retried forever.');
        $this->assertStringContainsString('smoke.unregistered', (string) $parked['error']);
    }

    #[Test]
    public function aFailingJobWaitsOutItsBackoffAndTheRunExitsZero(): void
    {
        // A handler that throws while attempts remain is a RETRY, not a
        // failure, and the two are told apart by the backoff: the row is
        // released into the future, so it drops out of this batch and the run
        // stays green. Before the backoff the same row was re-reserved by the
        // NEXT LOOP ITERATION of the same `work()` call — three attempts in one
        // batch, then parked, exit 1, for a handler that would have succeeded
        // on a later tick.
        QueueServices::handlers()->register('smoke.flaky', static function (array $args): void {
            throw new \RuntimeException('not yet');
        });

        $id = QueueServices::queue()->push('smoke.flaky', [], maxAttempts: 3);

        $result = QueueServices::work();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed'], 'a retry is not a failure — reddening every tick for one would train an operator to ignore the number that means something.');
        $this->assertSame(0, QueueServices::exitCode($result));
        $this->assertNull(QueueServices::queue()->findFailed($id), 'nothing is parked yet.');

        $job = QueueServices::queue()->findJob($id);
        $this->assertNotNull($job, 'the job is still queued for another attempt.');
        $this->assertSame(1, (int) $job['attempts'], 'exactly one attempt was spent, not all three.');
        $this->assertNull($job['reserved_at'], 'and it is released, not left reserved by a worker that is gone.');
        $this->assertGreaterThan(
            date('Y-m-d H:i:s'),
            $job['available_at'],
            'the next attempt is in the future: this is what the backoff means.',
        );
    }

    #[Test]
    public function theBatchLimitDoesNotChangeThatARetryStaysARetry(): void
    {
        // `--limit` used to be the only thing that could stop a batch from
        // burning every attempt, because the release was immediate. With a real
        // backoff the limit is about throughput, not about retries: a batch of
        // one still leaves the same green result.
        QueueServices::handlers()->register('smoke.flaky', static function (array $args): void {
            throw new \RuntimeException('not yet');
        });

        QueueServices::queue()->push('smoke.flaky', [], maxAttempts: 3);

        $result = QueueServices::work(null, 1);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, QueueServices::exitCode($result));
        $this->assertCount(1, QueueServices::queue()->listJobs());
    }

    #[Test]
    public function aNamedQueueIsWorkedAlone(): void
    {
        QueueServices::handlers()->register('smoke.known', static fn (array $args): null => null);

        $mail = QueueServices::queue()->push('smoke.known', ['q' => 'mail'], queue: 'mail');
        $other = QueueServices::queue()->push('smoke.known', ['q' => 'reports'], queue: 'reports');

        $result = QueueServices::work('mail');

        $this->assertSame(1, $result['processed']);
        $this->assertNull(QueueServices::queue()->findJob($mail));
        $this->assertNotNull(QueueServices::queue()->findJob($other), 'another queue is left alone.');
    }

    #[Test]
    public function aLimitBelowOneIsClampedRatherThanSilentlyDeliveringNothing(): void
    {
        // `max(1, $limit)` exists because `schedule:work`-style cron wiring and
        // a hand-typed `--limit=0` both reach this call; delivering nothing
        // would look like a healthy run that delivered nothing.
        QueueServices::handlers()->register('smoke.known', static fn (array $args): null => null);

        QueueServices::queue()->push('smoke.known');
        QueueServices::queue()->push('smoke.known');

        $result = QueueServices::work(null, 0);

        $this->assertSame(1, $result['processed']);
        $this->assertCount(1, QueueServices::queue()->listJobs());
    }

    #[Test]
    public function theWorkedQueueAndTheSharedRegistryAreTheCachedOnes(): void
    {
        // The Boot 16 failure mode in miniature: registering into a fresh
        // HandlerRegistry (or a fresh DatabaseQueue) leaves the instance the
        // worker actually uses with an empty map, and every job is then parked
        // as unroutable while the response looks fine.
        $this->assertSame(QueueServices::queue(), QueueServices::queue());
        $this->assertSame(QueueServices::handlers(), QueueServices::handlers());
    }
}