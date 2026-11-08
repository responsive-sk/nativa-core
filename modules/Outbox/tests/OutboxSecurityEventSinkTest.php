<?php

declare(strict_types = 1);

namespace Modules\Outbox\Tests;

use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Application\Services\OutboxSecurityEventSink;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OutboxSecurityEventSinkTest — the kernel port's implementation, against a real
 * outbox table.
 *
 * The two kernel middlewares used to name `SecurityEvents::…` inline. They now
 * say *which fact happened* through `Modules\Core\Domain\Contracts\SecurityEventSink`,
 * and this module decides what that becomes: the event TYPE and the payload
 * KEYS. That is the half worth testing here — the mapping is the module's
 * ownership claim, and `payloadKeys()` beside it is the declaration both halves
 * must agree on.
 *
 * The kernel-side half is covered where the middleware lives:
 * `AuthMiddlewareDenialTest` drives the real adapter end to end into an audit
 * row, and `RateLimitMiddlewareTest` hands the middleware a throwing sink to
 * prove a recorder that cannot write never changes the response.
 *
 * Throwaway SQLite file, never a real module database; the module test contract
 * (drives the INSTALLED copy, skips on a kernel-only tree) applies here too.
 */
final class OutboxSecurityEventSinkTest extends TestCase
{
    private string $dbFile = '';

    private ?string $previousDbOutbox = null;

    /** Set only once `setUp()` got past the install check; `tearDown()` still runs for a skipped test. */
    private bool $ready = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Outbox')) {
            $this->markTestSkipped('Outbox is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/outbox-sink-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->previousDbOutbox = getenv('DB_OUTBOX') ?: null;

        putenv('DB_OUTBOX=' . $this->dbFile);

        DatabaseConnectionManager::resetInstance();
        OutboxServices::reset();

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new OutboxDatabaseMigrator($this->pdo()))->run($null);

        $this->ready = true;
    }

    protected function tearDown(): void
    {
        // PHPUnit calls this even when `setUp()` skipped on a kernel-only tree,
        // where the module's classes are not on disk at all — hence the flag
        // rather than the install check again.
        if (!$this->ready) {
            parent::tearDown();

            return;
        }

        $this->restoreEnv('DB_OUTBOX', $this->previousDbOutbox);

        OutboxServices::reset();
        DatabaseConnectionManager::resetInstance();

        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    /**
     * The refusal's record: the owned type, and exactly the keys
     * `payloadKeys(AUTHZ_DENIED)` declares — `roles` included, because the
     * kernel passes it (empty today) and the audit handler reads the whole set.
     */
    #[Test]
    public function authorizationDeniedRecordsTheOwnedTypeAndTheDeclaredKeys(): void
    {
        (new OutboxSecurityEventSink())->authorizationDenied(
            '/mark/users',
            Permissions::USERS,
            'u-42',
            [],
            '198.51.100.23',
        );

        $rows = $this->rows();

        self::assertCount(1, $rows, 'The port call must leave exactly one outbox message.');
        self::assertSame(SecurityEvents::AUTHZ_DENIED, $rows[0]['event_type']);

        $payload = $this->payload($rows[0]);

        self::assertSame('/mark/users', $payload['path'] ?? null);
        self::assertSame(Permissions::USERS, $payload['required'] ?? null);
        self::assertSame('u-42', $payload['userId'] ?? null);
        self::assertSame([], $payload['roles'] ?? null);
        self::assertSame('198.51.100.23', $payload['ip'] ?? null);

        $keys = array_keys($payload);
        sort($keys);
        $declared = SecurityEvents::payloadKeys(SecurityEvents::AUTHZ_DENIED) ?? [];
        sort($declared);

        self::assertSame(
            $declared,
            $keys,
            'The adapter sends the keys payloadKeys() declares — a port that invents one would hand the'
            . ' audit handler a payload it cannot read.',
        );
    }

    #[Test]
    public function rateLimitTrippedRecordsTheOwnedTypeAndTheDeclaredKeys(): void
    {
        (new OutboxSecurityEventSink())->rateLimitTripped('rate_limit:198.51.100.23', '/docs', '198.51.100.23');

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertSame(SecurityEvents::RATELIMIT_TRIPPED, $rows[0]['event_type']);

        $payload = $this->payload($rows[0]);

        self::assertSame('rate_limit:198.51.100.23', $payload['key'] ?? null);
        self::assertSame('/docs', $payload['scope'] ?? null);
        self::assertSame('198.51.100.23', $payload['ip'] ?? null);

        $keys = array_keys($payload);
        sort($keys);
        $declared = SecurityEvents::payloadKeys(SecurityEvents::RATELIMIT_TRIPPED) ?? [];
        sort($declared);

        self::assertSame($declared, $keys);
    }

    private function pdo(): PDO
    {
        return DatabaseConnectionManager::instance()->connection('outbox');
    }

    /**
     * @return list<array{event_type: string, event_payload: string}>
     */
    private function rows(): array
    {
        $statement = $this->pdo()->query(
            'SELECT event_type, event_payload FROM outbox_messages ORDER BY created_at ASC, id ASC',
        );

        self::assertNotFalse($statement);

        /** @var list<array{event_type: string, event_payload: string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array{event_type: string, event_payload: string} $row
     *
     * @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        $decoded = json_decode($row['event_payload'], true);

        self::assertIsArray($decoded, 'The outbox message payload is not a JSON object.');

        return $decoded;
    }

    private function restoreEnv(string $key, ?string $previous): void
    {
        if (null === $previous) {
            putenv($key);

            return;
        }

        putenv($key . '=' . $previous);
    }
}
