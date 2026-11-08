<?php

declare(strict_types = 1);

namespace Modules\Health\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Health\Contracts\HealthCheckInterface;
use Modules\Health\Interfaces\HTTP\Actions\HealthAction;
use Modules\Health\Registry\HealthCheckRegistry;
use Modules\Health\Value\HealthResult;
use Modules\Health\Value\HealthStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HealthActionTest — the endpoint contract a monitor depends on: 200 happy,
 * 503 broken, 200 with `degraded` in the body for an unfinished tree, and a
 * payload that never carries the exception.
 *
 * The action takes its registry as an optional constructor argument, which is
 * what lets this file drive the real aggregation with synthetic checks — no
 * database, no dist tree, no page cache. Everything below the action is pinned
 * in `HealthChecksTest`.
 */
final class HealthActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Health')) {
            self::markTestSkipped('Health is not installed in this tree.');
        }
    }

    #[Test]
    public function everyCheckHealthyAnswersTwoHundred(): void
    {
        $response = $this->dispatch([
            self::check('database', HealthStatus::Healthy, '12 module databases answered'),
        ]);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('healthy', $payload['status']);
        self::assertSame(                    [['name' => 'database', 'status' => 'healthy', 'message' => '12 module databases answered', 'duration_ms' => 0.25]],
            $payload['checks'],
        );
    }

    #[Test]
    public function oneUnhealthyCheckAnswersFiveZeroThreeEvenWithHealthyOnesBeforeIt(): void
    {
        $response = $this->dispatch([
            self::check('database', HealthStatus::Healthy),
            self::check('filesystem', HealthStatus::Unhealthy),
            self::check('dist', HealthStatus::Healthy),
        ]);

        self::assertSame(503, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('unhealthy', $payload['status']);
        self::assertCount(3, $payload['checks'], 'A failing endpoint still reports every check.');
    }

    #[Test]
    public function degradedIsTwoHundredAndSaysSoInTheBody(): void
    {
        $response = $this->dispatch([self::check('dist', HealthStatus::Degraded)]);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('degraded', $payload['status']);
    }

    #[Test]
    public function anEmptyRegistryAnswersHealthyRatherThanFailing(): void
    {
        $response = $this->dispatch([]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'healthy', 'checks' => []],
            json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function thePayloadNeverCarriesTheException(): void
    {
        $failing = new class implements HealthCheckInterface {
            public function getName(): string
            {
                return 'database';
            }

            public function check(): HealthResult
            {
                return new HealthResult(
                    name: 'database',
                    status: HealthStatus::Unhealthy,
                    message: 'one of 12 module databases did not answer',
                    duration: 0.5,
                    exception: new \PDOException('/home/site/storage/data/users.db is locked'),
                );
            }
        };

        $payload = json_decode($this->dispatch([$failing])->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('/home/site/storage', $this->dispatch([$failing])->getContent());
        self::assertArrayNotHasKey('exception', $payload['checks'][0]);
    }

    #[Test]
    public function aFastProbeIsNotPublishedInExponentNotation(): void
    {
        $fast = new class implements HealthCheckInterface {
            public function getName(): string
            {
                return 'filesystem';
            }

            public function check(): HealthResult
            {
                // The first real payload: 82 microseconds in seconds.
                return new HealthResult(
                    name: 'filesystem',
                    status: HealthStatus::Healthy,
                    message: '1 storage directories accepted a write',
                    duration: 8.201_599_121_093_75e-5,
                );
            }
        };

        $body = $this->dispatch([$fast])->getContent();

        self::assertStringContainsString('"duration_ms":0.082', $body);
        self::assertStringNotContainsString('e-', $body, 'A reader saw 8.2e-5 and asked why the probe took 8 seconds.');
    }

    #[Test]
    public function theResponseCannotBeCachedByAnythingInFrontOfIt(): void
    {
        $response = $this->dispatch([self::check('database', HealthStatus::Healthy)]);

        self::assertSame(
            'no-store',
            $response->getHeader('Cache-Control'),
            'The local page cache already refuses JSON; this header covers the proxy/CDN this repo does not own.',
        );
    }

    #[Test]
    public function theRouteAnswersHeadAsWellAsGet(): void
    {
        $methods = [];

        foreach ((new \ReflectionClass(HealthAction::class))->getAttributes(Route::class) as $attribute) {
            $methods[] = $attribute->newInstance()->method;
        }

        self::assertSame(
            ['GET|HEAD'],
            $methods,
            'Probes that default to HEAD (curl -I, several load balancers) get 405 without it.',
        );
    }

    /**
     * @param list<HealthCheckInterface> $checks
     */
    private function dispatch(array $checks): \Modules\Core\Infrastructure\Http\Response
    {
        $registry = new HealthCheckRegistry();

        foreach ($checks as $check) {
            $registry->register($check);
        }

        return (new HealthAction($registry))->handle(Request::create('GET', '/health'));
    }

    private static function check(string $name, HealthStatus $status, string $message = 'fixture'): HealthCheckInterface
    {
        return new class ($name, $status, $message) implements HealthCheckInterface {
            public function __construct(
                private readonly string $name,
                private readonly HealthStatus $status,
                private readonly string $message,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function check(): HealthResult
            {
                // A quarter of a millisecond, on purpose: `json_encode(0.0)`
                // emits `0`, and a strict comparison would then pin a JSON
                // encoding detail instead of the payload contract.
                return new HealthResult(
                    name: $this->name,
                    status: $this->status,
                    message: $this->message,
                    duration: 0.000_25,
                );
            }
        };
    }
}
