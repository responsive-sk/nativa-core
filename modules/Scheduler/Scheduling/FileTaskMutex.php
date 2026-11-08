<?php

declare(strict_types = 1);

namespace Modules\Scheduler\Scheduling;

use Closure;
use Modules\Scheduler\Scheduling\Exceptions\SchedulerException;

/**
 * Local file mutex for scheduled tasks (ported from Marko's `scheduler`
 * package).
 *
 * Holds an exclusive, non-blocking flock() on `{directory}/{mutexName}` for
 * as long as the task runs, and writes the expiry timestamp into the file.
 * If the holder crashes, the kernel drops the lock. If the holder hangs past
 * its expiry, the next contender unlinks the file and locks a fresh one.
 * Only protects processes on the same host.
 *
 * Marko takes a PSR clock; this tree has none, so time arrives as an
 * injectable `now` closure (default `time()`). Tests pin the expiry logic
 * through it instead of sleeping.
 */
final class FileTaskMutex implements TaskMutexInterface
{
    private const int MAX_ATTEMPTS = 3;

    /** @var array<string, resource> */
    private array $handles = [];

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param Closure(): int|null $now Unix timestamp source (test seam)
     */
    public function __construct(
        private readonly string $directory,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @throws SchedulerException
     */
    public function acquire(ScheduledTask $task, int $expiresAfterSeconds): bool
    {
        $name = $task->mutexName();

        if (isset($this->handles[$name])) {
            return false;
        }

        $this->ensureDirectoryExists();
        $path = $this->path($name);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $handle = $this->open($path, 'c+');

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                if (!$this->isCurrentFile($handle, $path)) {
                    // A contender reclaimed (unlinked) the file between our open and lock.
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    continue;
                }

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) ($this->now() + $expiresAfterSeconds));
                fflush($handle);
                $this->handles[$name] = $handle;

                return true;
            }

            $expiresAt = $this->readExpiry($handle);
            fclose($handle);

            if (null === $expiresAt || $expiresAt > $this->now()) {
                return false;
            }

            // Stale: the holder is still alive but past its expiry. Detach it
            // from the path so the next attempt locks a fresh file.
            clearstatcache(true, $path);

            if (is_file($path)) {
                unlink($path);
            }
        }

        return false;
    }

    /**
     * @throws SchedulerException
     */
    public function release(ScheduledTask $task): void
    {
        $name = $task->mutexName();

        if (!isset($this->handles[$name])) {
            return;
        }

        $handle = $this->handles[$name];
        unset($this->handles[$name]);

        ftruncate($handle, 0);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @throws SchedulerException
     */
    public function exists(ScheduledTask $task): bool
    {
        $name = $task->mutexName();

        if (isset($this->handles[$name])) {
            return true;
        }

        $path = $this->path($name);
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return false;
        }

        $handle = $this->open($path, 'r');

        if (flock($handle, LOCK_SH | LOCK_NB)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }

        $expiresAt = $this->readExpiry($handle);
        fclose($handle);

        return null === $expiresAt || $expiresAt > $this->now();
    }

    private function now(): int
    {
        return ($this->now)();
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /**
     * @throws SchedulerException
     */
    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw SchedulerException::mutexDirectoryNotWritable($this->directory);
        }
    }

    /**
     * @return resource
     *
     * @throws SchedulerException
     */
    private function open(string $path, string $mode): mixed
    {
        $handle = fopen($path, $mode);

        if (false === $handle) {
            throw SchedulerException::mutexFileNotOpenable($path);
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function isCurrentFile(mixed $handle, string $path): bool
    {
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return false;
        }

        $handleStat = fstat($handle);
        $pathStat = stat($path);

        return false !== $handleStat
            && false !== $pathStat
            && $handleStat['ino'] === $pathStat['ino']
            && $handleStat['dev'] === $pathStat['dev'];
    }

    /**
     * @param resource $handle
     */
    private function readExpiry(mixed $handle): ?int
    {
        rewind($handle);
        $contents = trim((string) stream_get_contents($handle));

        return ctype_digit($contents) ? (int) $contents : null;
    }
}
