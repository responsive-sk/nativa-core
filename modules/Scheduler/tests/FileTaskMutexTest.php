<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Tests;

use Modules\Scheduler\Scheduling\FileTaskMutex;
use Modules\Scheduler\Scheduling\Schedule;
use Modules\Scheduler\Scheduling\ScheduledTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FileTaskMutexTest — the overlap lock beside the code it covers (module
 * test contract: drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * Time is injected, so expiry is pinned without sleeping. The directory is
 * a throwaway under the system temp dir, created and deleted around each
 * test — never `storage/`, which belongs to the running site.
 */
final class FileTaskMutexTest extends TestCase
{
    private string $dir;

    /** @var int controllable "now" */
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Scheduler')) {
            $this->markTestSkipped('Scheduler is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/scheduler-mutex-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function mutex(): FileTaskMutex
    {
        return new FileTaskMutex($this->dir, fn (): int => $this->now);
    }

    private function task(): ScheduledTask
    {
        return (new Schedule())->call(static fn (): null => null)
            ->description('nightly-report');
    }

    #[Test]
    public function acquireHoldReleaseCycle(): void
    {
        $mutex = $this->mutex();
        $task = $this->task();

        $this->assertTrue($mutex->acquire($task, 60));
        $this->assertTrue($mutex->exists($task));

        // Same instance refuses a second acquire.
        $this->assertFalse($mutex->acquire($task, 60));

        $mutex->release($task);

        $this->assertFalse($mutex->exists($task));
        $this->assertTrue($mutex->acquire($task, 60));

        $mutex->release($task);
    }

    #[Test]
    public function secondInstanceRefusedWhileHeld(): void
    {
        $first = $this->mutex();
        $second = $this->mutex();
        $task = $this->task();

        $this->assertTrue($first->acquire($task, 60));
        $this->assertFalse($second->acquire($task, 60));
        // Held (not merely present): exists() sees through the lock.
        $this->assertTrue($second->exists($task));

        $first->release($task);

        $this->assertTrue($second->acquire($task, 60));

        $second->release($task);
    }

    #[Test]
    public function staleLockReclaimedAfterExpiry(): void
    {
        $holder = $this->mutex();
        $contender = $this->mutex();
        $task = $this->task();

        $this->assertTrue($holder->acquire($task, 60));

        // The holder hangs: time passes beyond the 60s expiry while the
        // process (and its flock) is still alive. The holder instance is
        // deliberately NOT released — that is the crash/hang simulation.
        $this->now += 61;

        $this->assertTrue($contender->acquire($task, 60));

        $contender->release($task);
    }

    #[Test]
    public function releaseWithoutAcquireIsNoop(): void
    {
        $mutex = $this->mutex();

        $mutex->release($this->task());

        $this->assertFalse($mutex->exists($this->task()));
    }
}
