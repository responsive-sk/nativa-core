<?php

declare(strict_types = 1);

namespace Modules\Health\Checks;

use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;

/**
 * FilesystemHealthCheck — the runtime storage directories still accept a write.
 *
 * `is_writable()` is not enough and is not what this checks: a directory can
 * report writable and still refuse the write (a full disk, a read-only remount,
 * an open_basedir restriction on the realpath). So the probe writes a file,
 * confirms it landed, and deletes it — marko's shape, kept because it is the
 * only one that fails for the reasons deployments actually fail.
 *
 * The probe name carries 16 random bytes so two concurrent checks (two FPM
 * workers, or a monitor and a human) never collide and never delete each
 * other's file. Cleanup runs in `finally`, including on the failure path: a
 * health check that leaves junk in `storage/cache` turns a slow leak into the
 * next outage.
 *
 * Only the two directories a request actually needs are probed — the page cache
 * and the error log. `storage/uploads` is deliberately absent: a site whose
 * uploads directory is unwritable still serves every page, and calling that
 * unhealthy would page someone at 3am for a read-mostly site.
 */
final class FilesystemHealthCheck implements HealthCheckInterface
{
    /**
     * @param list<string> $directories absolute paths the app must be able to write
     */
    public function __construct(
        private readonly array $directories,
    ) {
    }

    public function getName(): string
    {
        return 'filesystem';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        if ([] === $this->directories) {
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Degraded,
                message: 'no storage directory configured to probe',
                duration: microtime(true) - $start,
            );
        }

        /** @var list<string> $written */
        $written = [];

        try {
            $probe = '.health-probe-' . bin2hex(random_bytes(8));

            foreach ($this->directories as $directory) {
                if (!is_dir($directory)) {
                    throw new \RuntimeException(sprintf('%s is not a directory', $directory));
                }

                $file = $directory . DIRECTORY_SEPARATOR . $probe;

                if (false === @file_put_contents($file, 'health')) {
                    throw new \RuntimeException(sprintf('%s refused a write', $directory));
                }

                if ('health' !== @file_get_contents($file)) {
                    throw new \RuntimeException(sprintf('%s did not return what was written', $directory));
                }

                $written[] = $file;
            }
        } catch (\Throwable $failure) {
            return new HealthResult(
                name: $this->getName(),
                status: HealthStatus::Unhealthy,
                message: sprintf('one of %d storage directories refused a write', count($this->directories)),
                duration: microtime(true) - $start,
                exception: $failure,
            );
        } finally {
            foreach ($written as $file) {
                @unlink($file);
            }
        }

        return new HealthResult(
            name: $this->getName(),
            status: HealthStatus::Healthy,
            message: sprintf('%d storage directories accepted a write', count($this->directories)),
            duration: microtime(true) - $start,
        );
    }
}
