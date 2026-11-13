<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\CLI\Command;

use Modules\Core\Domain\Permissions;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\CommandOptions;
use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use RuntimeException;

/**
 * bin/cms user:admin — the supported path to a first administrator.
 *
 * Measured 2026-10-17: a fresh `php bin/cms migrate` produced an empty
 * users.db — no users, no roles, no permissions — and `/register` creates an
 * account that then answers 403 on every `/mark` page ("mark.access permission
 * is required"). The installation was a dead end with no supported way out.
 *
 * This command is that way out, and it stays a COMMAND rather than a seeder on
 * purpose: `bin/cms migrate` runs on every deployment, and a migration that
 * planted a known password would ship one to production. The operator asks for
 * the account, once.
 *
 * It is idempotent in the useful sense: the admin role and the permission
 * vocabulary come from `Permissions::all()` and `ensure*()` (create-if-missing),
 * so a second run adds no duplicate rows. An e-mail that already exists is
 * promoted and reactivated instead of duplicated — a freshly registered user
 * is the common starting point — and the password is only re-hashed when the
 * stored hash no longer verifies it.
 *
 * The defaults (`admin@example.test` / `admin1234`) are development
 * credentials, which is why a password under the registration policy is
 * reported as a warning rather than silently accepted. They exist so a
 * checkout can be logged into with one line; a deployment passes its own.
 *
 * Options and positional arguments are both accepted:
 *
 *     user:admin [email] [password] [--name=NAME]
 *     user:admin --email=… --password=… [--name=…]
 *
 * Deliberately holds NO class constants: the defaults are values this command
 * uses once, and `NameOwnershipContractTest` refuses a new declaration for
 * something that is not an owner. The admin role name is a literal for the
 * same reason it is a literal in `BulkDeleteAction` and `UsersAuthProvider` —
 * nothing claims it, and a lone constant here would make every spelling
 * elsewhere look like a second owner.
 */
final readonly class EnsureAdminCommand
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @param list<string> $argv the command's own arguments, index 0 being the
     *                           command name as `$_SERVER['argv']` has it
     *
     * @return int 0 when the account is ready, 1 on bad input or a missing schema
     */
    public function run(array $argv, CliOutput $output): int
    {
        $parsed = $this->parse(array_slice($argv, 1));

        if (null === $parsed) {
            $output->writeln('user:admin: unknown or incomplete option.');
            $output->writeln('Usage: php bin/cms user:admin [email] [password] [--name=NAME | --name NAME]');

            return 1;
        }

        if (true === $parsed) {
            $output->writeln('Usage: php bin/cms user:admin [email] [password] [--name=NAME | --name NAME]');
            $output->writeln('');
            $output->writeln('  Creates (or repairs) an administrator account.');
            $output->writeln('  Defaults: admin@example.test / admin1234');

            return 0;
        }

        $email = strtolower(trim($parsed['email']));
        $password = $parsed['password'];
        $name = '' === trim($parsed['name']) ? 'Admin' : trim($parsed['name']);

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('user:admin: "' . $email . '" is not a valid e-mail address.');

            return 1;
        }

        if ('' === $password) {
            $output->writeln('user:admin: the password must not be empty.');

            return 1;
        }

        if (!$this->schemaExists()) {
            $output->writeln('user:admin: the users table does not exist — run `php bin/cms migrate` first.');

            return 1;
        }

        $result = (new UnitOfWork($this->db))->transaction(
            fn (ConnectionInterface $db): array => $this->ensure($db, $email, $password, $name),
        );

        $output->writeln(sprintf('user:admin: %s — %s', $email, $result['state']));

        foreach ($result['changes'] as $change) {
            $output->writeln('  ' . $change);
        }

        $output->writeln(sprintf(
            '  role: %s with %d permission(s)',
            $result['role'],
            \count(Permissions::all()),
        ));

        // A default that cannot pass the module's own registration policy is a
        // development credential, and saying so is cheaper than a surprise.
        if (\strlen($password) < PasswordPolicy::MIN_LENGTH) {
            $output->writeln(sprintf(
                '  WARNING: the password is %d characters, under the %d-character registration policy — development default only.',
                \strlen($password),
                PasswordPolicy::MIN_LENGTH,
            ));
        }

        return 0;
    }

    /**
     * @return array{state: string, changes: list<string>, role: string}
     */
    private function ensure(ConnectionInterface $db, string $email, #[\SensitiveParameter] string $password, string $name): array
    {
        $role = 'admin';
        $roles = new RoleRepository($db);
        $users = new UserRepository($db);
        $hasher = new PasswordHasher();
        $changes = [];

        $roleId = $roles->ensureRole(
            $this->id('role', $role),
            $role,
            'Full access to every permission in the admin area.',
        );

        foreach (Permissions::all() as $permission) {
            $permissionId = $roles->ensurePermission(
                $this->id('permission', $permission),
                $permission,
                $permission,
                'general',
            );
            $roles->assignPermission($roleId, $permissionId);
        }

        $user = $users->findByEmail($email);
        $state = 'unchanged';

        if (null === $user) {
            $user = $users->insert([
                'id'       => bin2hex(random_bytes(16)),
                'name'     => $name,
                'email'    => $email,
                'password' => $hasher->hash($password),
            ]);
            $state = 'created';
            $changes[] = 'account created';
        } else {
            if (!$hasher->verify($password, $user->password())) {
                $users->updatePassword($user->id(), $hasher->hash($password));
                $changes[] = 'password set';
            }

            if (!$user->isActive()) {
                $users->update($user->id(), $user->name(), $user->email(), true);
                $changes[] = 'account reactivated';
            }
        }

        $currentRoleIds = $roles->roleIdsForUser($user->id());
        $users->assignRole($user->id(), $roleId);

        if (!\in_array($roleId, $currentRoleIds, true)) {
            $changes[] = 'role assigned';
        }

        if ('created' !== $state && [] !== $changes) {
            $state = 'updated';
        }

        return ['state' => $state, 'changes' => $changes, 'role' => $role];
    }

    /**
     * Deterministic ids for the rows this command creates, so a re-run that
     * races another one cannot insert two roles with the same name.
     */
    private function id(string $kind, string $name): string
    {
        return substr(hash('sha256', $kind . ':' . $name), 0, 32);
    }

    private function schemaExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM users LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @param list<string> $arguments argv without the command name
     *
     * @return array{email: string, password: string, name: string}|true|null
     *                                                                        true for `--help`,
     *                                                                        null for a bad option
     */
    private function parse(array $arguments): array|bool|null
    {
        // `--help` is not an option the command reads, so it is answered before
        // the parser (which would refuse it as undeclared).
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            return true;
        }

        try {
            $parsed = CommandOptions::parse($arguments, ['email', 'password', 'name']);
        } catch (RuntimeException) {
            return null;
        }

        $positional = $parsed['positional'];

        if (\count($positional) > 2) {
            return null;
        }

        $values = [
            'email'    => 'admin@example.test',
            // The documented development default (see the class docblock), not a
            // credential a deployment is expected to keep.
            // @mago-expect lint:no-literal-password
            'password' => 'admin1234', // audit-ok: documented development default, see the class docblock
            'name'     => 'Admin',
        ];

        foreach (['email', 'password', 'name'] as $name) {
            $value = CommandOptions::value($parsed['options'], $name);

            if (null !== $value) {
                $values[$name] = $value;
            }
        }

        if (isset($positional[0])) {
            $values['email'] = $positional[0];
        }

        if (isset($positional[1])) {
            $values['password'] = $positional[1];
        }

        return $values;
    }
}
