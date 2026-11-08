<?php

declare(strict_types = 1);

namespace Modules\Health\Tests;

use Modules\Health\Checks\DatabaseHealthCheck;
use Modules\Health\Checks\DistManifestHealthCheck;
use Modules\Health\Checks\FilesystemHealthCheck;
use Modules\Health\Value\HealthStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HealthChecksTest — the three probes, each against a throwaway fixture.
 *
 * Every check gets both answers, because a health check that can only say
 * "healthy" is indistinguishable from no check at all: the database probe is
 * driven by a real PDO and by a connector that throws, the filesystem probe by a
 * temp directory and by a path that does not exist, the dist probe by a valid
 * manifest, a truncated one and an empty `public/dist`.
 *
 * Nothing here touches `storage/` or the real `public/dist` — a test that wrote
 * a probe file into the page cache would be the very leak the check's `finally`
 * exists to prevent.
 */
final class HealthChecksTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Health')) {
            self::markTestSkipped('Health is not installed in this tree.');
        }

        $this->tempDir = sys_get_temp_dir() . '/health-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // The guard is not decoration: PHPUnit runs tearDown() even when setUp()
        // skipped, and on a kernel-only tree (CI) it did — reading an
        // uninitialised typed property and turning 10 skips into 10 errors.
        // Same shape as `TaxonomyRegistryTest`.
        if (isset($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function aLiveDatabaseAnswersHealthy(): void
    {
        $pdo = new \PDO('sqlite:' . $this->tempDir . '/users.db');
        $check = new DatabaseHealthCheck(['users'], static fn (string $name): \PDO => $pdo);

        $result = $check->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertStringContainsString('1 module databases answered', $result->message);
    }

    #[Test]
    public function aDatabaseThatThrowsIsUnhealthyAndKeepsTheException(): void
    {
        $check = new DatabaseHealthCheck(
            ['users', 'blog'],
            static function (string $name): \PDO {
                throw new \PDOException('disk I/O error');
            },
        );

        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertInstanceOf(\PDOException::class, $result->exception);
        self::assertStringNotContainsString('disk I/O error', $result->message, 'The public message names no cause.');
    }

    #[Test]
    public function noDatabaseToProbeIsDegradedNotUnhealthy(): void
    {
        $result = (new DatabaseHealthCheck([]))->check();

        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('no module database found to probe', $result->message);
    }

    #[Test]
    public function aWritableDirectoryIsHealthyAndNoProbeFileSurvives(): void
    {
        $result = (new FilesystemHealthCheck([$this->tempDir]))->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame([], array_values(array_diff((array) scandir($this->tempDir), ['.', '..'])));
    }

    #[Test]
    public function aMissingDirectoryIsUnhealthy(): void
    {
        $result = (new FilesystemHealthCheck([$this->tempDir, $this->tempDir . '/gone']))->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertInstanceOf(\RuntimeException::class, $result->exception);
        self::assertSame([], array_values(array_diff((array) scandir($this->tempDir), ['.', '..'])), 'The probe written before the failure is cleaned up too.');
    }

    #[Test]
    public function aParsedManifestIsHealthy(): void
    {
        $this->bundle('alpha', '{"src/alpha.ts":{"file":"assets/alpha.js","isEntry":true}}');

        $result = (new DistManifestHealthCheck($this->tempDir))->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('1 theme bundle manifests parsed', $result->message);
    }

    #[Test]
    public function aTruncatedManifestIsUnhealthy(): void
    {
        $this->bundle('beta', '{"src/beta.ts":');

        $result = (new DistManifestHealthCheck($this->tempDir))->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertInstanceOf(\JsonException::class, $result->exception);
    }

    #[Test]
    public function aBundleWithoutAManifestIsUnhealthy(): void
    {
        mkdir($this->tempDir . '/bare/assets', 0o755, true);

        $result = (new DistManifestHealthCheck($this->tempDir))->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
    }

    #[Test]
    public function aDirectoryWithoutAssetsIsNotABundle(): void
    {
        // A tool cache or an editor folder under public/dist is not a theme: it
        // has no assets/, so it is skipped instead of being failed for a missing
        // manifest no page would ever read.
        mkdir($this->tempDir . '/.cache', 0o755, true);

        $result = (new DistManifestHealthCheck($this->tempDir))->check();

        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('no theme bundle built', $result->message);
    }

    #[Test]
    public function noBundleAtAllIsDegraded(): void
    {
        $result = (new DistManifestHealthCheck($this->tempDir))->check();

        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('no theme bundle built', $result->message);
    }

    private function bundle(string $name, string $manifest): void
    {
        mkdir($this->tempDir . '/' . $name . '/assets', 0o755, true);
        file_put_contents($this->tempDir . '/' . $name . '/manifest.json', $manifest);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
