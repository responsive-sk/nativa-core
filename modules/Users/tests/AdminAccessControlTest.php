<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Outbox\Infrastructure\Persistence\Migrations\OutboxDatabaseMigrator;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Modules\Core\Domain\Permissions;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use Modules\Users\Infrastructure\Persistence\Statement;
use Modules\Users\Interfaces\HTTP\AuthServices;
use PDO;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminAccessControlTest — the five answers `/mark` must give, dispatched
 * through the Kernel exactly as the composition root dispatches them.
 *
 * This file exists because of a measured hole: an unauthenticated request was
 * served the whole admin dashboard (200, user table, e-mails, delete forms,
 * a live CSRF token) with no cookies. The port (`AuthProvider`), the decision
 * (`AuthMiddleware`) and the policy table (`AdminRoutePolicy`) all existed —
 * nobody wired them, so nothing asked the question. These assertions are the
 * question: 302 to the login for a guest, 200 for an identity the policy
 * admits, 403 for one it does not, and the login pages themselves off-limits
 * once signed in.
 *
 * The first run of this file (before wiring existed) failed on the guest,
 * the no-permission and the guest-only cases with `got 200, expected 302/403`
 * — the red that proves the assertions can see the hole at all.
 *
 * ## The database
 *
 * `AuthServices` resolves `users.db` through `DatabaseConnectionManager`,
 * so the test points `DB_USERS` at a temp file and builds the schema with
 * the module's own migrator — same tables the kernel would run, no shared
 * state. `DB_OUTBOX` moves too: a refused authorization records a security
 * event, and that write must land in the test's own file, not the working
 * tree's outbox. Both are restored in tearDown().
 *
 * Like every module test it drives the INSTALLED slice and skips when the
 * module (or its dependencies) is absent — the `modules` suite also runs in
 * kernel-only CI.
 */
final class AdminAccessControlTest extends TestCase
{
    private string $tmpDir = '';

    private string $holderId = '';

    private string $viewerId = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Cardboard', 'Security', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-admin-acl-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        putenv('DB_OUTBOX=' . $this->tmpDir . '/outbox.db');
        DatabaseConnectionManager::resetInstance();
        AuthServices::reset();
        OutboxServices::reset();

        (new UsersDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/users.db')))->run($this->quietOutput());

        if (is_dir(APP_BASE_PATH . '/src/Modules/Outbox')) {
            (new OutboxDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/outbox.db')))->run($this->quietOutput());
        }

        $this->seed();
        $this->signOut();
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped (module absent) —
        // then there is no env, no tmp dir and nothing to restore.
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

    /**
     * A guest must be sent to the login, never served the dashboard.
     *
     * Before the wiring this answered 200 with the full Mark dashboard —
     * the measured defect (plan: fix-mark-access-control).
     */
    #[Test]
    public function aGuestIsRedirectedToTheLoginFromTheDashboard(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/mark'));

        self::assertSame(
            302,
            $response->getStatusCode(),
            sprintf(
                'GET /mark answered %d for an anonymous request; the admin dashboard must answer 302 to the login (before the fix: 200 with the whole dashboard)',
                $response->getStatusCode(),
            ),
        );
        self::assertSame('/login', $response->getHeader('Location'));
    }

    /**
     * The user list — e-mails, delete forms, a live CSRF token — must not
     * reach a guest, not even as an empty shell: the redirect carries no body.
     */
    #[Test]
    public function aGuestNeverSeesTheUserList(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/mark/users'));

        self::assertSame(
            302,
            $response->getStatusCode(),
            sprintf(
                'GET /mark/users answered %d for an anonymous request; the user table must answer 302 to the login (before the fix: 200, 324 KB of user data)',
                $response->getStatusCode(),
            ),
        );
        self::assertSame('/login', $response->getHeader('Location'));
        self::assertSame(
            '',
            $response->getContent(),
            'the redirect to the login must carry no body — a body here is admin markup leaking to a guest',
        );
    }

    /**
     * The positive case, on both admin paths: the policy's own permissions
     * (`mark.access` for the dashboard, `users.manage` for the user list)
     * open the door that a guest is turned away from.
     */
    #[Test]
    public function anIdentityHoldingThePermissionsSeesTheAdminPages(): void
    {
        $this->signIn($this->holderId);

        $dashboard = $this->kernel()->handle(Request::create('GET', '/mark'));

        self::assertSame(
            200,
            $dashboard->getStatusCode(),
            sprintf(
                'GET /mark answered %d for an identity holding %s; the dashboard must render for the policy\'s own permission',
                $dashboard->getStatusCode(),
                Permissions::MARK_ACCESS,
            ),
        );

        $users = $this->kernel()->handle(Request::create('GET', '/mark/users'));

        self::assertSame(
            200,
            $users->getStatusCode(),
            sprintf(
                'GET /mark/users answered %d for an identity holding %s; the user list must render for the policy\'s own permission',
                $users->getStatusCode(),
                Permissions::USERS,
            ),
        );
    }

    /**
     * Signed in is not the same as permitted: an active identity without the
     * permission gets 403 naming exactly what it lacks, so the refusal is
     * actionable instead of a bare wall.
     */
    #[Test]
    public function anIdentityWithoutThePermissionIsRefused(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->kernel()->handle(Request::create('GET', '/mark'));

        self::assertSame(
            403,
            $response->getStatusCode(),
            sprintf(
                'GET /mark answered %d for a signed-in identity holding no %s; the dashboard must answer 403 (before the fix: 200 for everyone)',
                $response->getStatusCode(),
                Permissions::MARK_ACCESS,
            ),
        );
        self::assertStringContainsString(
            Permissions::MARK_ACCESS,
            (string) $response->getContent(),
            'the 403 must name the missing permission — a silent refusal cannot be acted on',
        );
    }

    /**
     * The guest-only half: a signed-in administrator has no business looking
     * at the login form, so the auth pages bounce back to the admin area.
     */
    #[Test]
    public function aSignedInIdentityIsRedirectedOffTheLoginPage(): void
    {
        $this->signIn($this->holderId);

        $response = $this->kernel()->handle(Request::create('GET', '/login'));

        self::assertSame(
            302,
            $response->getStatusCode(),
            sprintf(
                'GET /login answered %d for a signed-in identity; the auth pages are guest-only and must answer 302 to /mark',
                $response->getStatusCode(),
            ),
        );
        self::assertSame('/mark', $response->getHeader('Location'));
    }

    /**
     * The pipeline under test — the same construction as `public/index.php`,
     * minus nothing: whatever middleware the Kernel discovers plus the
     * wiring this suite exists to prove.
     */
    private function kernel(): Kernel
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));

        // The same two Core-only lines `public/index.php` runs: ask the
        // provider registry, wire the middleware around what it finds. Inline
        // FQCNs so the mirror needs no imports and cannot silently keep
        // compiling against a moved class.
        $provider = \Modules\Auth\Application\Services\AuthProviderRegistry::first();

        if (null !== $provider) {
            $kernel->addMiddleware(
                new \Modules\Auth\Interfaces\HTTP\Middleware\AuthMiddleware($provider),
            );
        }

        return $kernel;
    }

    /**
     * Two identities against the migrated schema: a holder carrying both
     * permissions the paths under test require, and a viewer carrying none.
     * The `admin` role is deliberately absent — its bypass is the provider's
     * own contract (UsersAuthProviderTest), not this file's subject.
     */
    private function seed(): void
    {
        $pdo = DatabaseConnectionManager::instance()->connection('users');

        $pdo->exec("INSERT INTO permissions (id, name) VALUES ('perm-mark', '" . Permissions::MARK_ACCESS . "')");
        $pdo->exec("INSERT INTO permissions (id, name) VALUES ('perm-users', '" . Permissions::USERS . "')");

        $pdo->exec("INSERT INTO roles (id, name) VALUES ('role-editor', 'editor'), ('role-viewer', 'viewer')");
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES ('role-editor', 'perm-mark'), ('role-editor', 'perm-users')");

        $this->holderId = $this->insertUser('acl-holder', 'acl-holder@example.test', 'role-editor');
        $this->viewerId = $this->insertUser('acl-viewer', 'acl-viewer@example.test', 'role-viewer');
    }

    private function insertUser(string $name, string $email, string $roleId): string
    {
        $id = bin2hex(random_bytes(16));

        $stmt = Statement::prepare(
            DatabaseConnectionManager::instance()->connect('users'),
            'INSERT INTO users (id, name, email, password, is_active) VALUES (:id, :name, :email, :password, 1)',
        );
        $stmt->execute([
            ':id'       => $id,
            ':name'     => $name,
            ':email'    => $email,
            ':password' => password_hash('irrelevant-for-this-test', PASSWORD_DEFAULT),
        ]);

        $stmt = Statement::prepare(
            DatabaseConnectionManager::instance()->connect('users'),
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
        );
        $stmt->execute([':user' => $id, ':role' => $roleId]);

        return $id;
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

    private function signOut(): void
    {
        $_SESSION = [];
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
