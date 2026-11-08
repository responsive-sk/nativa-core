<?php

declare(strict_types = 1);

namespace Modules\Health\Tests;

use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Registry\HealthCheckRegistry;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HealthCheckRegistryTest — beside the code it covers (module test contract:
 * drives the INSTALLED copy, skips on a kernel-only tree).
 *
 * The registry is the endpoint's seam, so this file pins the two properties the
 * payload depends on and nothing else: order is registration order, and an empty
 * registry raises no exception — it returns an empty list, which the action
 * reports as healthy.
 */
final class HealthCheckRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Health')) {
            self::markTestSkipped('Health is not installed in this tree.');
        }
    }

    #[Test]
    public function resultsKeepRegistrationOrder(): void
    {
        $registry = new HealthCheckRegistry();
        $registry->register(self::check('database', HealthStatus::Healthy));
        $registry->register(self::check('dist', HealthStatus::Degraded));
        $registry->register(self::check('filesystem', HealthStatus::Unhealthy));

        self::assertSame(
            ['database', 'dist', 'filesystem'],
            array_map(static fn (HealthResult $result): string => $result->name, $registry->run()),
            'A monitor diffing two responses compares this list; it must not reshuffle.',
        );
    }

    #[Test]
    public function anEmptyRegistryRunsNothingAndThrowsNothing(): void
    {
        $registry = new HealthCheckRegistry();

        self::assertSame([], $registry->all());
        self::assertSame([], $registry->run());
    }

    #[Test]
    public function registeredChecksAreListedBeforeTheyRun(): void
    {
        $registry = new HealthCheckRegistry();
        $check = self::check('database', HealthStatus::Healthy);

        $registry->register($check);

        self::assertSame([$check], $registry->all());
    }

    private static function check(string $name, HealthStatus $status): HealthCheckInterface
    {
        return new class ($name, $status) implements HealthCheckInterface {
            public function __construct(
                private readonly string $name,
                private readonly HealthStatus $status,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function check(): HealthResult
            {
                return new HealthResult(
                    name: $this->name,
                    status: $this->status,
                    message: 'fixture',
                    duration: 0.0,
                );
            }
        };
    }
}
