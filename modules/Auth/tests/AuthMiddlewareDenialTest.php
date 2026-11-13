<?php

declare(strict_types = 1);

namespace Modules\Auth\Tests;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Auth\Domain\AuthIdentity;
use Modules\Auth\Domain\Contracts\AuthProvider;
use Modules\Core\Domain\Contracts\SecurityEventSink;
use Modules\Core\Domain\Permissions;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Auth\Interfaces\HTTP\Middleware\AuthMiddleware;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthMiddlewareDenialTest — a refused admin path, from the 403 to the audit row.
 *
 * Measured 2026-10-16, while naming a real event for `authorization.yaml`'s
 * `error_ref` values: the middleware handed the bare string `authz.denied` to
 * `AuthProvider::noteEvent()`, the provider forwards what it is given, and the
 * drain dispatches by event type while `SecurityEvents::all()` holds
 * `security.authz.denied`. Both emits were run through the real processor, each
 * into a throwaway outbox:
 *
 *     BulkPublishAction  → security.authz.denied → processed, 1 audit_log row
 *     AuthMiddleware     → authz.denied          → FAILED, "No handler registered
 *                                                  for event type: authz.denied",
 *                                                  NO audit row
 *
 * So every refused admin path wrote a message the queue could never deliver: five
 * retries, then parked — while the middleware's own docblock promises the refusal
 * is "the one class of thing the application most needs to reconstruct after the
 * fact", and `SecurityEvents` promises that "no emit site spells an event type of
 * its own". The middleware now names `SecurityEvents::AUTHZ_DENIED`, inline behind
 * a `class_exists` guard exactly the way `RateLimitMiddleware` names its own.
 *
 * Three claims are pinned here, and the pair of the first two is the point:
 *
 *  1. **The refusal arrives.** A 403 from the middleware leaves one `processed`
 *     outbox message and one `audit_log` row, with the actor, the path and the
 *     missing permission in it — recorded through the composition root's handler
 *     pairing, not through a stub.
 *  2. **A name outside the vocabulary is refused, not parked.** The provider seam
 *     still takes a string, so it is the seam that must refuse `authz.denied` —
 *     otherwise the same defect returns the day somebody calls it again. The
 *     second half of that test writes the owned name and shows it lands, so the
 *     guard cannot pass by refusing everything.
 *  3. **An inactive identity is still refused without an event.** That is the one
 *     refusal the middleware answers before any policy is consulted; the model
 *     event's description says so, and this is what keeps that sentence true.
 *
 * Skips on a tree without Outbox, Audit or Users — the same module-dependency
 * contract the module tests use, because the claim is about the PAIRING between
 * them and the kernel middleware.
 */
final class AuthMiddlewareDenialTest extends TestCase
{
    private const string CLIENT_IP = '203.0.113.9';

    private string $dir = '';

    private ?string $previousDbOutbox = null;

    private ?string $previousDbAudit = null;

    private ?string $previousRemoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Auth', 'Outbox', 'Audit', 'Users'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped(sprintf('%s is not installed.', $module));
            }
        }

        $this->dir = sys_get_temp_dir() . '/authz-denial-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->previousDbOutbox = getenv('DB_OUTBOX') ?: null;
        $this->previousDbAudit = getenv('DB_AUDIT') ?: null;
        $this->previousRemoteAddr = getenv('REMOTE_ADDR') ?: null;

        putenv('DB_OUTBOX=' . $this->dir . '/outbox.db');
        putenv('DB_AUDIT=' . $this->dir . '/audit.db');
        putenv('REMOTE_ADDR=' . self::CLIENT_IP);

        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
        self::resetOutboxServices();
        self::resetAuditServices();

        $outbox = DatabaseConnectionManager::instance()->connection('outbox');
        $audit = DatabaseConnectionManager::instance()->connection('audit');

        self::migrateOutbox($outbox);
        self::migrateAudit($audit);

        // The real pairing, from the one home that declares it. A handler map
        // assembled here by hand would prove the handler works and nothing about
        // the wiring that is supposed to install it.
        if (!function_exists('wire_module_handlers')) {
            require_once APP_BASE_PATH . '/src/composition.php';
        }

        \wire_module_handlers();
    }

    protected function tearDown(): void
    {
        self::resetOutboxServices();
        self::resetAuditServices();
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        // `setUp()` skips the class before it allocates the directory, so on a
        // tree without the optional modules this runs with `$dir` still empty —
        // and `glob('' . '/*')` is `/*`, the filesystem root. An unguarded
        // teardown therefore asked `unlink()` to delete every entry at `/`,
        // which failed loudly here (`unlink(/bin): Permission denied`) only
        // because those entries are directories. Guard the cleanup by the
        // thing it cleans: there is nothing to clean until the directory exists.
        if ('' !== $this->dir) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($this->dir)) {
                rmdir($this->dir);
            }
        }

        $this->restoreEnv('DB_OUTBOX', $this->previousDbOutbox);
        $this->restoreEnv('DB_AUDIT', $this->previousDbAudit);
        $this->restoreEnv('REMOTE_ADDR', $this->previousRemoteAddr);

        parent::tearDown();
    }

    /**
     * The path under test, read from the OWNER rather than declared here: the
     * admin prefix is optional (`ADMIN_PATH`), and a second constant is exactly
     * the collision task 0.3 removed.
     */
    private function adminPath(): string
    {
        return AdminRoutePolicy::prefix() . '/users';
    }

    #[Test]
    public function aRefusedAdminPathReachesTheAuditLog(): void
    {
        $response = $this->middleware(new AuthIdentity('u-42', true, ['content.manage']))
            ->handle(Request::create('GET', $this->adminPath()), self::passThrough());

        self::assertSame(403, $response->getStatusCode(), 'An identity without the permission must be refused.');

        $result = self::processOutbox();

        self::assertSame(
            ['processed' => 1, 'failed' => 0],
            $result,
            'The refusal must be DELIVERED. A message the queue cannot route is the defect'
            . ' this test exists for: it retried five times, parked, and left no audit row.',
        );

        $message = $this->outboxRows()[0] ?? null;

        self::assertNotNull($message, 'A refused admin path recorded nothing at all.');
        self::assertSame('security.authz.denied', $message['event_type']);
        self::assertSame('processed', $message['status']);

        $audit = $this->auditRows();

        self::assertCount(1, $audit, 'The handler pairing must turn the event into an audit row.');
        self::assertSame('security.authz.denied', $audit[0]['action']);
        self::assertSame($this->adminPath(), $audit[0]['target']);
        self::assertSame('u-42', $audit[0]['actor_name']);
        self::assertSame(self::CLIENT_IP, $audit[0]['ip_address']);

        $detail = $audit[0]['detail'];
        self::assertIsString($detail);
        self::assertStringContainsString(Permissions::USERS, $detail);
    }

    #[Test]
    public function theRecordedPayloadCarriesExactlyTheDeclaredKeys(): void
    {
        $this->middleware(new AuthIdentity('u-42', true, []))
            ->handle(Request::create('GET', $this->adminPath()), self::passThrough());

        $message = $this->outboxRows()[0] ?? null;

        self::assertNotNull($message, 'A refused admin path recorded nothing at all.');

        $encoded = $message['event_payload'];
        self::assertIsString($encoded);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($encoded, true);
        $keys = array_keys($payload);
        sort($keys);

        $declared = self::declaredPayloadKeys(self::authzDenied());
        sort($declared);

        // `SecurityEvents::payloadKeys()` exists so "an emit site cannot quietly
        // invent one the handler will not read" — this is that guard's caller for
        // the one emit site that builds its payload by hand.
        self::assertSame(
            $declared,
            $keys,
            'The emitted payload and the declared keys must be the same set: a key the handler'
            . ' does not read is a fact the audit viewer silently drops, and a declared key that'
            . ' is never sent is a contract nobody honours.',
        );

        self::assertSame(Permissions::USERS, $payload['required']);
    }

    #[Test]
    public function aNameOutsideTheOutboxVocabularyIsRefusedNotParked(): void
    {
        // The exact shape the defect took: the provider seam takes a string, so a
        // string that no handler claims must be refused at the seam.
        self::noteEvent('authz.denied', [
            'path'     => $this->adminPath(),
            'required' => Permissions::USERS,
            'userId'   => 'u-42',
            'ip'       => self::CLIENT_IP,
        ]);

        self::assertSame(
            [],
            $this->outboxRows(),
            'A name outside SecurityEvents::all() must not be written: it becomes a message the'
            . ' drain fails with "No handler registered for event type", which is how every'
            . ' refused admin path used to end.',
        );

        // The other half: the guard must not refuse the events that ARE ours.
        self::noteEvent(self::authzDenied(), [
            'path'     => $this->adminPath(),
            'required' => Permissions::USERS,
            'userId'   => 'u-42',
            'roles'    => [],
            'ip'       => self::CLIENT_IP,
        ]);

        self::assertCount(1, $this->outboxRows(), 'The owned name must still be recorded.');
    }

    #[Test]
    public function anInactiveIdentityIsRefusedWithoutASecurityEvent(): void
    {
        $response = $this->middleware(new AuthIdentity('u-42', false, [Permissions::USERS]))
            ->handle(Request::create('GET', $this->adminPath()), self::passThrough());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('not active', $response->getContent());

        // Documented, not a second defect to fix here: the middleware refuses an
        // inactive identity before any policy is consulted, so there is no policy
        // decision to record. The model event says so in its own description.
        self::assertSame([], $this->outboxRows());
    }

    // ---------------------------------------------------------------------
    // OWNERS OF THE OPTIONAL MODULES
    //
    // Outbox, Audit and Users are optional: `src/Modules/<Name>` is gitignored
    // operator state, so a clone carries Core + Database and nothing else. A
    // `use Modules\Outbox\…` here therefore made this file 16 `class.notFound`
    // errors on every kernel-only checkout — the analyser cannot know the class
    // is optional, it only knows the reference is unconditional.
    //
    // So these three modules are named inline behind `class_exists()` and never
    // imported. That is the idiom the kernel middleware already uses for the
    // same three classes (`AuthMiddleware::recordDenial()`,
    // `RateLimitMiddleware::notifyTripped()`), it keeps every call site typed,
    // and it costs nothing at runtime: `setUp()` already skips the whole class
    // when a module is missing, so a guard that does not fire is the normal
    // path and one that fires means the skip did not happen.
    // ---------------------------------------------------------------------

    private static function resetOutboxServices(): void
    {
        if (!class_exists(\Modules\Outbox\Interfaces\HTTP\OutboxServices::class)) {
            return;
        }

        \Modules\Outbox\Interfaces\HTTP\OutboxServices::reset();
    }

    private static function resetAuditServices(): void
    {
        if (!class_exists(\Modules\Audit\Interfaces\HTTP\AuditServices::class)) {
            return;
        }

        \Modules\Audit\Interfaces\HTTP\AuditServices::reset();
    }

    /** @return array{processed: int, failed: int} */
    private static function processOutbox(): array
    {
        // The guard is written here, not in a helper, and that is load-bearing:
        // PHPStan narrows "this class exists" only from a `class_exists()` call
        // in the same scope. `self::assertInstalled(OutboxServices::class)` looks
        // tidier and fails — the analyser still sees an unknown class on the
        // next line. Same reason `AuthMiddleware::recordDenial()` inlines it.
        if (!class_exists(\Modules\Outbox\Interfaces\HTTP\OutboxServices::class)) {
            self::notInstalled('Outbox');
        }

        return \Modules\Outbox\Interfaces\HTTP\OutboxServices::processor()->process();
    }

    private static function migrateOutbox(PDO $connection): void
    {
        if (!class_exists(\Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator::class)) {
            self::notInstalled('Outbox');
        }

        (new \Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator($connection))
            ->run(self::nullOutput());
    }

    private static function migrateAudit(PDO $connection): void
    {
        if (!class_exists(\Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator::class)) {
            self::notInstalled('Audit');
        }

        (new \Modules\Audit\Infrastructure\Persistence\Migrations\AuditDatabaseMigrator($connection))
            ->run(self::nullOutput());
    }

    /** @param array<string, mixed> $context */
    private static function noteEvent(string $event, array $context): void
    {
        if (!class_exists(\Modules\Users\Interfaces\HTTP\UsersAuthProvider::class)) {
            self::notInstalled('Users');
        }

        (new \Modules\Users\Interfaces\HTTP\UsersAuthProvider())->noteEvent($event, $context);
    }

    private static function authzDenied(): string
    {
        if (!class_exists(\Modules\Outbox\Domain\SecurityEvents::class)) {
            self::notInstalled('Outbox');
        }

        return \Modules\Outbox\Domain\SecurityEvents::AUTHZ_DENIED;
    }

    /** @return list<string> */
    private static function declaredPayloadKeys(string $eventType): array
    {
        if (!class_exists(\Modules\Outbox\Domain\SecurityEvents::class)) {
            self::notInstalled('Outbox');
        }

        return \Modules\Outbox\Domain\SecurityEvents::payloadKeys($eventType);
    }

    private static function nullOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }

    /**
     * A module this class was supposed to be skipped without is a broken skip,
     * not a reason to pass quietly: say which one, by name.
     */
    private static function notInstalled(string $module): never
    {
        throw new \RuntimeException(sprintf(
            'The %s module is not installed, but setUp() did not skip this test — the module guard'
            . ' and the module list have drifted apart.',
            $module,
        ));
    }

    private function middleware(AuthIdentity $identity): AuthMiddleware
    {
        // The REAL port implementation, not a stub: this class's claim is that a
        // kernel refusal reaches an audit row, and the adapter is the code that
        // turns the kernel's decision into the outbox message.
        $provider = new readonly class ($identity, Permissions::USERS) implements AuthProvider {
            public function __construct(
                private AuthIdentity $identity,
                private string $required,
            ) {
            }

            // Answers, not decisions: the middleware is the subject here, so the
            // provider says who the request is and what the path costs.
            public function identity(Request $request): AuthIdentity
            {
                return $this->identity;
            }

            public function isProtectedPath(string $path): bool
            {
                return true;
            }

            public function requiredPermission(string $path): string
            {
                return $this->required;
            }

            public function guestOnlyPaths(): array
            {
                return [];
            }

            public function noteEvent(string $event, array $context): void
            {
            }
        };

        // The adapter is the module's code, so its name is a STRING: `tests/`
        // is kernel scope and must typecheck in a checkout where `Modules\Outbox`
        // is not on disk (the skip above is runtime; PHPStan is not). The
        // `assertInstanceOf` both proves the port is implemented and gives the
        // variable its kernel type.
        $sinkClass = 'Modules\\Outbox\\Application\\Services\\OutboxSecurityEventSink';

        if (!class_exists($sinkClass)) {
            self::fail('Outbox is installed but its security-event adapter is not autoloadable.');
        }

        $sink = new $sinkClass();
        self::assertInstanceOf(SecurityEventSink::class, $sink);

        return new AuthMiddleware($provider, $sink);
    }

    private static function passThrough(): callable
    {
        return static fn (Request $request): Response => Response::html('next', 200);
    }

    private function restoreEnv(string $key, ?string $previous): void
    {
        if (null === $previous) {
            putenv($key);

            return;
        }

        putenv($key . '=' . $previous);
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        return $this->query('outbox', 'SELECT event_type, status, event_payload, error_message FROM outbox_messages');
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return $this->query('audit', 'SELECT action, target, detail, actor_name, ip_address FROM audit_log');
    }

    /** @return list<array<string, mixed>> */
    private function query(string $connection, string $sql): array
    {
        $statement = DatabaseConnectionManager::instance()->connection($connection)->query($sql);

        self::assertNotFalse($statement, sprintf('Query failed: %s', $sql));

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
