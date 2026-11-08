<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Persistence\Repositories;

use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Users\Domain\Entities\User;
use Modules\Users\Infrastructure\Persistence\Statement;

/**
 * UserRepository — persistence for users + role/permission loading.
 */
final readonly class UserRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM users WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute([':email' => $email]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findById(string $id): ?User
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * All users (newest first), with roles/permissions hydrated.
     *
     * @return list<User>
     */
    public function all(): array
    {
        $stmt = Statement::query($this->pdo, 'SELECT * FROM users ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();
        $users = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $users[] = $this->hydrate($row);
        }

        return $users;
    }

    /**
     * Update name/email/active state for a user.
     */
    public function update(string $id, string $name, string $email, bool $isActive): void
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE users SET name = :name, email = :email, is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $stmt->execute([
            ':name'   => $name,
            ':email'  => strtolower($email),
            ':active' => $isActive ? 1 : 0,
            ':id'     => $id,
        ]);
    }

    /**
     * Delete a user and all of their pivots (atomic — all or nothing).
     */
    public function delete(string $id): void
    {
        $user = $this->findById($id);
        $email = null === $user ? '' : $user->email();

        $uow = new UnitOfWork($this->pdo);
        $uow->transaction(function (\PDO $pdo) use ($id, $email): void {
            Statement::prepare($pdo, 'DELETE FROM user_roles WHERE user_id = :id')->execute([':id' => $id]);
            if ('' !== $email) {
                Statement::prepare($pdo, 'DELETE FROM password_reset_tokens WHERE email = :email')->execute([':email' => $email]);
            }
            Statement::prepare($pdo, 'DELETE FROM remember_tokens WHERE user_id = :id')->execute([':id' => $id]);
            Statement::prepare($pdo, 'DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $id]);
            Statement::prepare($pdo, 'DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
        });
    }

    /**
     * Remove a single role from a user.
     */
    public function removeRole(string $userId, string $roleId): void
    {
        Statement::prepare($this->pdo,
            'DELETE FROM user_roles WHERE user_id = :uid AND role_id = :rid',
        )->execute([':uid' => $userId, ':rid' => $roleId]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT 1 FROM users WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute([':email' => $email]);

        return false !== $stmt->fetch();
    }

    /**
     * Insert a new user and return the created entity.
     *
     * @param array<string, mixed> $data id, name, email, password, optional avatar
     */
    public function insert(array $data): User
    {
        // Normalize the raw input ONCE at the boundary (mixed → strings).
        $id = is_string($data['id'] ?? null) ? $data['id'] : '';
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $avatar = is_string($data['avatar'] ?? null) ? $data['avatar'] : null;

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO users (id, name, email, password, avatar, is_active, created_at, updated_at)
             VALUES (:id, :name, :email, :password, :avatar, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id'       => $id,
            ':name'     => $name,
            ':email'    => strtolower($email),
            ':password' => $password,
            ':avatar'   => $avatar,
        ]);

        /** @var User $user */
        $user = $this->findById($id);

        return $user;
    }

    /**
     * Assign a role to a user (idempotent).
     */
    public function assignRole(string $userId, string $roleId): void
    {
        $stmt = Statement::prepare($this->pdo,
            'INSERT OR IGNORE INTO user_roles (user_id, role_id, created_at) VALUES (:uid, :rid, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([':uid' => $userId, ':rid' => $roleId]);
    }

    /**
     * Set or clear the stored-avatar path for a user (null clears it).
     */
    public function updateAvatar(string $userId, ?string $avatar): void
    {
        Statement::prepare($this->pdo,
            'UPDATE users SET avatar = :avatar, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        )->execute([':avatar' => $avatar, ':id' => $userId]);
    }

    public function updateLastLogin(string $userId, string $ip): void
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = :ip WHERE id = :id',
        );
        $stmt->execute([':ip' => $ip, ':id' => $userId]);
    }

    public function updatePassword(string $userId, #[\SensitiveParameter] string $hashedPassword): void
    {
        $stmt = Statement::prepare($this->pdo,
            'UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $stmt->execute([':password' => $hashedPassword, ':id' => $userId]);
    }

    /**
     * Load role names + permission names for a user.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $user = User::fromRow($row);

        // Roles
        $roleStmt = Statement::prepare($this->pdo,
            'SELECT r.name FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :id ORDER BY r.name',
        );
        $roleStmt->execute([':id' => $row['id']]);
        $roles = array_map(static fn ($r): string => (string) $r['name'], $roleStmt->fetchAll());

        // Permissions (via roles)
        $permStmt = Statement::prepare($this->pdo,
            'SELECT DISTINCT p.name FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id ORDER BY p.name',
        );
        $permStmt->execute([':id' => $row['id']]);
        $permissions = array_map(static fn ($p): string => (string) $p['name'], $permStmt->fetchAll());

        $user->setRoles(array_values($roles));
        $user->setPermissions(array_values($permissions));

        return $user;
    }
}
