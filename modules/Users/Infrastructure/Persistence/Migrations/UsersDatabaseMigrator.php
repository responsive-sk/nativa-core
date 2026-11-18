<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;

/**
 * Users Database Migrator — creates the complete users.db schema.
 *
 * Tables: users, roles, permissions, role_permissions, user_roles,
 * password_reset_tokens, remember_tokens, sessions, rate_limits,
 * login_attempts. Every statement is idempotent (CREATE TABLE IF NOT
 * EXISTS), so re-running via `bin/cms migrate` is safe.
 *
 * It is also the upgrade step for the super-user role: the row was named
 * `admin` until the vocabulary round, and `renameLegacyRole()` moves it — or
 * merges it into an existing `operator` row — so upgrading keeps every
 * account's access instead of leaving it pointing at a role nothing checks.
 */
#[AsMigrator(module: 'users', table: 'users')]
final readonly class UsersDatabaseMigrator implements MigratorInterface
{
    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating users.db...</info>');

        $this->createUsersTable();
        $this->createRolesTable();
        $this->createPermissionsTable();
        $this->createRolePermissionsTable();
        $this->createUserRolesTable();
        $this->createPasswordResetTokensTable();
        $this->createRememberTokensTable();
        $this->createSessionsTable();
        $this->createRateLimitsTable();
        $this->createLoginAttemptsTable();
        $this->createIndexes();
        $this->renameLegacyRole($output);

        $output->writeln('<info>  ✓ Created tables: users, roles, permissions, role_permissions, user_roles, password_reset_tokens, remember_tokens, sessions, rate_limits, login_attempts</info>');
    }

    /**
     * Rename the pre-rename `admin` role to `operator`, once.
     *
     * The row is renamed in place so `user_roles` and `role_permissions` keep
     * pointing at it — the ids are what the pivots carry, and recreating the
     * role would leave every account without the bypass the role grants.
     *
     * An install that already has an `operator` row (the new command ran before
     * this migration) would violate `roles.name` UNIQUE, so the pivots move
     * onto the existing row and the legacy row is dropped. Merging keeps the
     * access; dropping the legacy row keeps the name unique.
     */
    private function renameLegacyRole(CliOutput $output): void
    {
        $legacy = $this->roleId('admin');

        if (null === $legacy) {
            return;
        }

        $operator = $this->roleId('operator');

        if (null === $operator) {
            $this->pdo->prepare('UPDATE roles SET name = :name WHERE id = :id')
                ->execute([':name' => 'operator', ':id' => $legacy]);
            $output->writeln('<info>  ✓ renamed the `admin` role to `operator`</info>');

            return;
        }

        $this->pdo->prepare($this->dialect->insertOrIgnore(
            'INSERT INTO user_roles (user_id, role_id, created_at)
             SELECT user_id, :new, created_at FROM user_roles WHERE role_id = :old',
        ))->execute([':new' => $operator, ':old' => $legacy]);
        $this->pdo->prepare('DELETE FROM user_roles WHERE role_id = :old')->execute([':old' => $legacy]);
        $this->pdo->prepare($this->dialect->insertOrIgnore(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT :new, permission_id, created_at FROM role_permissions WHERE role_id = :old',
        ))->execute([':new' => $operator, ':old' => $legacy]);
        $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :old')->execute([':old' => $legacy]);
        $this->pdo->prepare('DELETE FROM roles WHERE id = :old')->execute([':old' => $legacy]);

        $output->writeln('<info>  ✓ merged the `admin` role into `operator`</info>');
    }

    private function roleId(string $name): ?string
    {
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
        $statement->execute([$name]);
        $id = $statement->fetchColumn();

        return false === $id ? null : (string) $id;
    }

    private function createUsersTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS users (
                    id VARCHAR(32) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    avatar VARCHAR(255),
                    is_active BOOLEAN DEFAULT {$this->dialect->booleanDefault(true)},
                    last_login_at {$this->timestamp},
                    last_login_ip VARCHAR(45),
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createRolesTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS roles (
                    id VARCHAR(32) PRIMARY KEY,
                    name VARCHAR(50) UNIQUE NOT NULL,
                    description TEXT,
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createPermissionsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS permissions (
                    id VARCHAR(32) PRIMARY KEY,
                    name VARCHAR(100) UNIQUE NOT NULL,
                    description TEXT,
                    group_name VARCHAR(50) DEFAULT 'default',
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createRolePermissionsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS role_permissions (
                    role_id VARCHAR(32) NOT NULL,
                    permission_id VARCHAR(32) NOT NULL,
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (role_id, permission_id),
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createUserRolesTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS user_roles (
                    user_id VARCHAR(32) NOT NULL,
                    role_id VARCHAR(32) NOT NULL,
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, role_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createPasswordResetTokensTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id VARCHAR(32) PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(64) UNIQUE NOT NULL,
                    expires_at {$this->timestamp} NOT NULL,
                    used_at {$this->timestamp},
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createRememberTokensTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS remember_tokens (
                    id VARCHAR(32) PRIMARY KEY,
                    user_id VARCHAR(32) NOT NULL,
                    token_hash VARCHAR(64) UNIQUE NOT NULL,
                    expires_at {$this->timestamp} NOT NULL,
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createSessionsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(32) PRIMARY KEY,
                    user_id VARCHAR(32) NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    payload TEXT,
                    last_activity {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    created_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            SQL);
    }

    private function createRateLimitsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS rate_limits (
                    "key" VARCHAR(255) PRIMARY KEY,
                    count INTEGER NOT NULL DEFAULT 0,
                    expires INTEGER NOT NULL
                )
            SQL);
    }

    private function createLoginAttemptsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id VARCHAR(32) PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45),
                    attempted_at {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    /**
     * Indexes for query hot paths.
     */
    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_email ON password_reset_tokens(email)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_token ON password_reset_tokens(token)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_remember_tokens_user_id ON remember_tokens(user_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_remember_tokens_token_hash ON remember_tokens(token_hash)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_expires ON rate_limits(expires)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address)');
    }
}
