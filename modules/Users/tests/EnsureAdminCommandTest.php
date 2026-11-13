<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Infrastructure\Persistence\Migrations\UsersDatabaseMigrator;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Interfaces\CLI\Command\EnsureAdminCommand;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EnsureAdminCommandTest — the first-administrator path, exercised on a real
 * migrated schema.
 *
 * The command exists because a fresh installation is a dead end: `migrate`
 * creates the tables empty, `/register` creates an account with no permission,
 * and every `/mark` page answers 403. These cases pin the three ways out of
 * that state — a new account, a registered account that needs promoting, and
 * the second run that must not duplicate anything — against the module's own
 * migrator rather than a hand-built schema, so a column change breaks here
 * instead of in production.
 *
 * The credentials are spelled as literals here and not read from the command:
 * the DEFAULTS are the interface (they are what `--help` prints and what a
 * developer types), so a test that followed a renamed constant would pass
 * while the documented password stopped working. The command holds no
 * constants for them, on purpose — see its docblock and
 * `NameOwnershipContractTest`.
 *
 * The tables are read through the module's repositories, not raw SQL: the
 * command drives those repositories, so asserting against the same API is what
 * makes these cases about behaviour rather than about a schema this file
 * happens to spell correctly.
 */
final class EnsureAdminCommandTest extends TestCase
{
    private string $tmpDir = '';

    private PDO $pdo;

    private string $email = 'admin@example.test';

    // The command's documented development default: this fixture asserts that
    // the value the CLI was told to use is the one it wrote.
    // @mago-expect lint:no-literal-password
    private string $password = 'admin1234';

    protected function setUp(): void
    {
        parent::setUp();

        // The INSTALLED slices, not the catalogue: `Modules\…` autoloads from
        // `src/Modules/` alone (composer.json psr-4). Accepting `modules/` here
        // made the guard pass in a checkout that has the catalogue and nothing
        // installed, and the suite then reported "Class UsersDatabaseMigrator
        // not found" in the kernel-only job instead of skipping.
        foreach (['Users', 'Security', 'Database'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed — its classes autoload from src/Modules/ only.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-ensure-admin-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        $this->pdo = new PDO('sqlite:' . $this->tmpDir . '/users.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function theDefaultsCreateAnAccountThatCanReachTheAdminArea(): void
    {
        $this->migrate();
        $recorder = $this->recorder();

        $exit = (new EnsureAdminCommand($this->db()))->run(['user:admin'], $recorder);

        self::assertSame(0, $exit);
        self::assertStringContainsString($this->email, $recorder->text());

        $user = $this->user($this->email);

        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertTrue(
            (new PasswordHasher())->verify($this->password, $user->password()),
            'the documented default password must be the one stored',
        );

        self::assertSame(
            ['admin'],
            $this->roleNamesFor($user->id()),
            'the admin role is what UsersAuthProvider expands into every permission',
        );
        self::assertSame(
            $this->sorted(Permissions::all()),
            $this->permissionNamesFor('admin'),
            'the admin role must hold the whole vocabulary, not a subset',
        );
    }

    #[Test]
    public function aSecondRunAddsNoDuplicateRows(): void
    {
        $this->migrate();
        $command = new EnsureAdminCommand($this->db());

        self::assertSame(0, $command->run(['user:admin'], $this->recorder()));

        $first = $this->user($this->email);
        self::assertNotNull($first);

        $recorder = $this->recorder();

        self::assertSame(0, $command->run(['user:admin'], $recorder));
        self::assertStringContainsString('unchanged', $recorder->text());

        $roles = new RoleRepository($this->db());
        $roleId = $this->adminRoleId();

        self::assertSame(1, \count((new UserRepository($this->db()))->all()));
        self::assertSame(1, \count($roles->allRoles()));
        self::assertSame(\count(Permissions::all()), \count($roles->allPermissions()));
        self::assertSame(1, \count($roles->roleIdsForUser($first->id())));
        self::assertSame(\count(Permissions::all()), \count($roles->permissionIdsForRole($roleId)));
        self::assertSame($first->id(), $this->user($this->email)?->id());
    }

    #[Test]
    public function anAlreadyRegisteredAccountIsPromotedAndReactivated(): void
    {
        $this->migrate();

        $users = new UserRepository($this->db());
        $roles = new RoleRepository($this->db());

        $roles->createRole('role-author', 'author', 'Author');
        $users->insert([
            'id'       => 'registered-user',
            'name'     => 'Registered',
            'email'    => $this->email,
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
        ]);
        $users->update('registered-user', 'Registered', $this->email, false);
        $users->assignRole('registered-user', 'role-author');

        $exit = (new EnsureAdminCommand($this->db()))->run(['user:admin'], $this->recorder());

        self::assertSame(0, $exit);

        $user = $this->user($this->email);

        self::assertNotNull($user);
        self::assertSame('registered-user', $user->id(), 'the existing account must be promoted, never duplicated');
        self::assertTrue($user->isActive());
        self::assertTrue((new PasswordHasher())->verify($this->password, $user->password()));
        self::assertContains('admin', $this->roleNamesFor('registered-user'));
        self::assertContains('author', $this->roleNamesFor('registered-user'), 'an unrelated role is kept');
        self::assertSame(1, \count($users->all()));
    }

    #[Test]
    public function explicitCredentialsAndNameAreAccepted(): void
    {
        $this->migrate();

        $exit = (new EnsureAdminCommand($this->db()))->run(
            ['user:admin', 'owner@example.test', 'correct-horse-battery', '--name=Owner'],
            $this->recorder(),
        );

        self::assertSame(0, $exit);

        $user = $this->user('owner@example.test');

        self::assertNotNull($user);
        self::assertSame('Owner', $user->name());
        self::assertTrue((new PasswordHasher())->verify('correct-horse-battery', $user->password()));
        self::assertNull($this->user($this->email), 'positional arguments replace the defaults');
    }

    #[Test]
    public function aWeakDefaultPasswordIsReportedAsAWarning(): void
    {
        $this->migrate();
        $recorder = $this->recorder();

        (new EnsureAdminCommand($this->db()))->run(['user:admin'], $recorder);

        $text = $recorder->text();

        self::assertStringContainsString('WARNING', $text);
        self::assertStringContainsString('registration policy', $text);
    }

    #[Test]
    public function anUnmigratedDatabaseIsRefusedWithTheRemedy(): void
    {
        $recorder = $this->recorder();

        $exit = (new EnsureAdminCommand($this->db()))->run(['user:admin'], $recorder);

        self::assertSame(1, $exit);
        self::assertStringContainsString('migrate', $recorder->text());
    }

    #[Test]
    public function anInvalidEmailIsRefused(): void
    {
        $this->migrate();
        $recorder = $this->recorder();

        $exit = (new EnsureAdminCommand($this->db()))->run(['user:admin', 'not-an-email'], $recorder);

        self::assertSame(1, $exit);
        self::assertStringContainsString('not a valid e-mail', $recorder->text());
    }

    private function migrate(): void
    {
        (new UsersDatabaseMigrator($this->pdo))->run($this->recorder());
    }

    private function user(string $email): ?\Modules\Users\Domain\Entities\User
    {
        return (new UserRepository($this->db()))->findByEmail($email);
    }

    private function adminRoleId(): string
    {
        $role = (new RoleRepository($this->db()))->findByName('admin');

        self::assertNotNull($role, 'the command must have created the admin role');

        return (string) $role['id'];
    }

    /**
     * @return list<string>
     */
    private function roleNamesFor(string $userId): array
    {
        $roles = new RoleRepository($this->db());
        $names = [];

        foreach ($roles->allRoles() as $role) {
            if (\in_array($role['id'], $roles->roleIdsForUser($userId), true)) {
                $names[] = $role['name'];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function permissionNamesFor(string $roleName): array
    {
        $roles = new RoleRepository($this->db());
        $role = $roles->findByName($roleName);
        self::assertNotNull($role);

        $assigned = $roles->permissionIdsForRole((string) $role['id']);
        $names = [];

        foreach ($roles->allPermissions() as $permission) {
            if (\in_array($permission['id'], $assigned, true)) {
                $names[] = $permission['name'];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * A `CliOutput` that keeps what it was told — the shape
     * `GenerateImagesCommandTest` uses, because a command's wording is part
     * of what it promises (the refusal names the remedy).
     */
    /**
     * The command and its repositories take the connection contract now; this
     * wraps the test's temp-file handle without routing anywhere.
     */
    private function db(): PdoConnection
    {
        return new PdoConnection($this->pdo, 'users');
    }

    private function recorder(): CliOutput
    {
        return new class () implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }

            public function text(): string
            {
                return implode("\n", $this->lines);
            }
        };
    }
}
