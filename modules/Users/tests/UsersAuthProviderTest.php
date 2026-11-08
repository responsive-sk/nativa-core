<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Core\Domain\Permissions;
use Modules\Users\Infrastructure\Persistence\Statement;
use Modules\Users\Interfaces\HTTP\UsersAuthProvider;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use Modules\Users\Interfaces\HTTP\AuthServices;
use PDO;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UsersAuthProviderTest — the mapping half of the gate, in isolation.
 *
 * `AuthMiddleware` decides; this provider is where its three facts come
 * from, and two of the mappings are invisible from the outside until they
 * are wrong:
 *
 * - **The admin expansion.** `User::hasPermission()` short-circuits on the
 *   `admin` role, but `AuthIdentity::hasPermission()` is a plain
 *   `in_array`. Mapping `permissions()` across as-is would 403 the very
 *   administrator the policy exists to admit — a regression that would look
 *   like a policy bug rather than a mapping one, which is why the expansion
 *   is pinned here against a role holding NOTHING in `role_permissions`.
 * - **Fail-closed.** An admin path no policy row classifies must resolve to
 *   null (the middleware's DENY), never to "no requirement found, allow".
 *
 * The database follows the house pattern (SettingsShellContextProviderTest):
 * `DB_USERS` pointed at a temp file, schema built by the module's own
 * migrator, both restored in tearDown(). `DB_OUTBOX` moves to an
 * UNMIGRATED file on purpose — it makes the "event dropped, request kept"
 * path reachable while Outbox is installed.
 *
 * Like every module test it drives the INSTALLED slice and skips when the
 * module (or its dependency) is absent.
 */
final class UsersAuthProviderTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Security'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-provider-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        putenv('DB_OUTBOX=' . $this->tmpDir . '/no-such-dir/outbox.db');
        DatabaseConnectionManager::resetInstance();
        AuthServices::reset();
        OutboxServices::reset();

        (new UsersDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/users.db')))->run($this->quietOutput());

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            putenv('DB_USERS');
            putenv('DB_OUTBOX');
            DatabaseConnectionManager::resetInstance();
            AuthServices::reset();
            OutboxServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        $_SESSION = [];

        parent::tearDown();
    }

    #[Test]
    public function anAnonymousRequestResolvesToNobody(): void
    {
        $_SESSION = [];

        self::assertNull(
            (new UsersAuthProvider())->identity(Request::create('GET', '/mark')),
            'no session auth entry must map to null — the middleware turns that into a redirect, never a failure',
        );
    }

    /**
     * The real sign-in path: credentials (`login`) then the session row
     * (`establishSession`), exactly as LoginAction chains them.
     */
    #[Test]
    public function aLoginEstablishedSessionResolvesToAnActiveIdentity(): void
    {
        // A fixture password: the test asserts that this exact value logs in.
        // @mago-expect lint:no-literal-password
        $id = $this->insertUser('provider-login', 'provider-login@example.test', password: 'correct-horse-battery');

        $result = AuthServices::auth()->login('provider-login@example.test', 'correct-horse-battery', false, '127.0.0.1');

        self::assertSame([], $result['errors'], 'the credentials are valid; a failure here means the service broke, not the provider');
        AuthServices::auth()->establishSession($result['user'], '127.0.0.1');

        $identity = (new UsersAuthProvider())->identity(Request::create('GET', '/mark'));

        self::assertNotNull($identity, 'an established session must resolve to an identity');
        self::assertSame($id, $identity->id);
        self::assertTrue($identity->active, 'the seeded account is active and the mapping must echo that fact');
    }

    /**
     * THE regression this file exists for: an `admin`-role user whose rows
     * grant NOTHING must still hold every permission the gate asks for.
     * Mapping `permissions()` straight across would 403 the administrator.
     */
    #[Test]
    public function theAdminRoleExpandsIntoEveryPermissionTheGateAsks(): void
    {
        $id = $this->insertUser('provider-admin', 'provider-admin@example.test');
        $this->assignRole($id, 'admin', grants: []);
        $this->signIn($id);

        $identity = (new UsersAuthProvider())->identity(Request::create('GET', '/mark'));

        self::assertNotNull($identity);
        self::assertSame(
            Permissions::all(),
            $identity->permissions,
            'the admin bypass must move into the identity data — AuthIdentity::hasPermission() is a plain in_array and '
            . 'would otherwise refuse the administrator on every /mark route',
        );

        foreach (Permissions::all() as $permission) {
            self::assertTrue($identity->hasPermission($permission), 'admin must hold ' . $permission);
        }
    }

    /**
     * The other half of the mapping: a content role keeps exactly what its
     * rows grant — no more, no leak from the expansion above.
     */
    #[Test]
    public function aNonAdminHoldsExactlyWhatItsRolesGranted(): void
    {
        $id = $this->insertUser('provider-editor', 'provider-editor@example.test');
        $this->assignRole($id, 'editor', grants: [Permissions::MARK_ACCESS]);
        $this->signIn($id);

        $identity = (new UsersAuthProvider())->identity(Request::create('GET', '/mark'));

        self::assertNotNull($identity);
        self::assertSame([Permissions::MARK_ACCESS], $identity->permissions);
        self::assertTrue($identity->hasPermission(Permissions::MARK_ACCESS));
        self::assertFalse(
            $identity->hasPermission(Permissions::USERS),
            'the admin expansion must not leak into non-admin identities — otherwise the permission graph is decorative',
        );
    }

    /**
     * The prefix boundary: `/markish` is a public path with an admin-looking
     * name, and the provider answers the policy, not a copied literal.
     */
    #[Test]
    public function theAdminBoundaryIsThePoliciesNotAStringPrefix(): void
    {
        $provider = new UsersAuthProvider();

        self::assertTrue($provider->isProtectedPath('/mark'));
        self::assertTrue($provider->isProtectedPath('/mark/users'));
        self::assertFalse($provider->isProtectedPath('/markish'), 'a whole-segment boundary — /mark must not claim /markish');
        self::assertFalse($provider->isProtectedPath('/blog'));
        self::assertFalse($provider->isProtectedPath('/'));
    }

    #[Test]
    public function theGuestOnlyPagesAreTheAuthPages(): void
    {
        self::assertSame(
            ['/login', '/register', '/forgot-password', '/reset-password'],
            (new UsersAuthProvider())->guestOnlyPaths(),
        );
    }

    /**
     * Fail-closed, both directions.
     *
     * A classified path names its permission; a public path answers none.
     * An UNCLASSIFIED route under /mark (`/mark/nowhere` — nobody wrote a
     * row for it) must not come back empty: it resolves the `/mark` baseline,
     * so it still costs `mark.access`. That fallback IS the fail-closed
     * design — the table's first row gates every admin path, and the
     * middleware's separate null-is-DENY rule covers a table that somehow
     * lost even that row.
     */
    #[Test]
    public function anUnclassifiedAdminPathStillCostsTheBaselinePermission(): void
    {
        $provider = new UsersAuthProvider();

        self::assertSame(Permissions::USERS, $provider->requiredPermission('/mark/users'));
        self::assertSame(
            Permissions::MARK_ACCESS,
            $provider->requiredPermission('/mark/nowhere'),
            'a route nobody classified must fall back to the /mark baseline — a new admin route is gated by default, '
            . 'never opened by omission',
        );
        self::assertNull($provider->requiredPermission('/blog'), 'a public path answers no permission at all');
    }

    /**
     * "Without the outbox the event is dropped, not the request."
     *
     * In a tree without Outbox the `class_exists` branch drops it; here the
     * module IS installed but `DB_OUTBOX` points at an unreachable file, so
     * the store fails and the drop happens one layer down. Either way
     * `noteEvent()` returns without throwing — a security fact must never
     * take the request with it.
     */
    #[Test]
    public function anEventIsDroppedRatherThanThrownWhenTheOutboxIsUnavailable(): void
    {
        try {
            (new UsersAuthProvider())->noteEvent('authz.denied', [
                'path'     => '/mark/nowhere',
                'required' => Permissions::MARK_ACCESS,
                'userId'   => 'test-user',
            ]);
        } catch (\Throwable $e) {
            self::fail('noteEvent() must drop the event, not throw — got ' . $e::class . ': ' . $e->getMessage());
        }

        self::assertTrue(true, 'the event was dropped and the caller survived');
    }

    private function insertUser(string $name, string $email, #[\SensitiveParameter] ?string $password = null): string
    {
        $id = bin2hex(random_bytes(16));

        $stmt = Statement::prepare(
            $this->pdo(),
            'INSERT INTO users (id, name, email, password, is_active) VALUES (:id, :name, :email, :password, 1)',
        );
        $stmt->execute([
            ':id'       => $id,
            ':name'     => $name,
            ':email'    => $email,
            ':password' => password_hash($password ?? 'not-verified-in-this-test', PASSWORD_DEFAULT),
        ]);

        return $id;
    }

    /**
     * A role with the given grants — `grants: []` is the admin case: the
     * role name carries the bypass, the rows carry nothing.
     *
     * @param list<string> $grants
     */
    private function assignRole(string $userId, string $roleName, array $grants): void
    {
        $pdo = $this->pdo();

        $pdo->exec("INSERT INTO roles (id, name) VALUES ('role-" . $roleName . "', '" . $roleName . "')");
        $pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ('" . $userId . "', 'role-" . $roleName . "')");

        foreach ($grants as $index => $permission) {
            $permissionId = 'perm-' . $roleName . '-' . $index;
            $pdo->exec("INSERT INTO permissions (id, name) VALUES ('" . $permissionId . "', '" . $permission . "')");
            $pdo->exec(
                "INSERT INTO role_permissions (role_id, permission_id) VALUES ('role-" . $roleName . "', '" . $permissionId . "')",
            );
        }
    }

    private function pdo(): PDO
    {
        return DatabaseConnectionManager::instance()->connection('users');
    }

    /**
     * The session the app itself would have: started first (a later
     * session_start() would replace $_SESSION with the file's contents),
     * then populated with the key AuthService reads.
     */
    private function signIn(string $userId): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $_SESSION = ['auth' => ['user_id' => $userId, 'last_activity' => time()]];
    }

    /**
     * A `CliOutput` that swallows every line.
     *
     * This was a `Symfony\...\NullOutput` until the migrator contract stopped
     * accepting a dev-only union arm (Boot 32): the kernel's runtime has no
     * Composer dependencies, so a test asserting a kernel contract should not
     * need one to satisfy it either.
     */
    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
