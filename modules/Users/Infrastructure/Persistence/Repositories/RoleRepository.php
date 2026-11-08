<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Persistence\Repositories;

use Modules\Database\Infrastructure\Persistence\UnitOfWork;
use Modules\Users\Infrastructure\Persistence\Statement;

/**
 * RoleRepository — persistence for roles + permissions.
 */
final readonly class RoleRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT * FROM roles WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return false === $row ? null : $row;
    }

    /**
     * Create a role if it does not exist yet; returns its id.
     */
    public function ensureRole(string $id, string $name, string $description): string
    {
        $existing = $this->findByName($name);

        if (null !== $existing) {
            /** @var string $existingId */
            $existingId = $existing['id'];

            return $existingId;
        }

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO roles (id, name, description, created_at) VALUES (:id, :name, :description, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([':id' => $id, ':name' => $name, ':description' => $description]);

        return $id;
    }

    /**
     * Create a permission if it does not exist yet; returns its actual id
     * (existing id on re-seed, so pivot inserts never violate FK).
     */
    public function ensurePermission(string $id, string $name, string $description, string $group): string
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT id FROM permissions WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        /** @var array<string, mixed>|false $existing */
        $existing = $stmt->fetch();

        if (false !== $existing) {
            /** @var string $existingId */
            $existingId = $existing['id'];

            return $existingId;
        }

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO permissions (id, name, description, group_name, created_at)
             VALUES (:id, :name, :description, :group, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([':id' => $id, ':name' => $name, ':description' => $description, ':group' => $group]);

        return $id;
    }

    /**
     * Whether a role with the given id exists.
     */
    public function roleExists(string $roleId): bool
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT 1 FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $roleId]);

        return false !== $stmt->fetch();
    }

    /**
     * All roles, alphabetically.
     *
     * @return list<array{id: string, name: string, description: string, created_at: string}>
     */
    public function allRoles(): array
    {
        $stmt = Statement::query($this->pdo, 'SELECT * FROM roles ORDER BY name ASC');
        $rows = $stmt->fetchAll();
        $normalized = [];

        foreach ($rows as $row) {
            /** @var array<string, string|null> $row */
            $normalized[] = [
                'id'          => (string) ($row['id'] ?? ''),
                'name'        => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'created_at'  => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * All permissions, grouped-friendly (group_name, name, description).
     *
     * @return list<array{id: string, name: string, description: string, group_name: string, created_at: string}>
     */
    public function allPermissions(): array
    {
        $stmt = Statement::query($this->pdo,
            'SELECT * FROM permissions ORDER BY group_name ASC, name ASC',
        );
        $rows = $stmt->fetchAll();
        $normalized = [];

        foreach ($rows as $row) {
            /** @var array<string, string|null> $row */
            $normalized[] = [
                'id'          => (string) ($row['id'] ?? ''),
                'name'        => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'group_name'  => (string) ($row['group_name'] ?? 'general'),
                'created_at'  => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * Permission ids assigned to a role.
     *
     * @return list<string>
     */
    public function permissionIdsForRole(string $roleId): array
    {
        $stmt = Statement::prepare($this->pdo,
            'SELECT permission_id FROM role_permissions WHERE role_id = :rid',
        );
        $stmt->execute([':rid' => $roleId]);

        /** @var list<array<string, mixed>>|false $rows */
        $rows = $stmt->fetchAll();

        if (false === $rows) {
            return [];
        }

        $ids = [];
        foreach ($rows as $r) {
            /** @var array<string, mixed> $r */
            $raw = $r['permission_id'];
            $ids[] = is_string($raw) ? $raw : '';
        }

        return $ids;
    }

    /**
     * Role ids assigned to a user.
     *
     * @return list<string>
     */
    public function roleIdsForUser(string $userId): array
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT role_id FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        /** @var list<array<string, mixed>>|false $rows */
        $rows = $stmt->fetchAll();

        if (false === $rows) {
            return [];
        }

        $ids = [];
        foreach ($rows as $r) {
            /** @var array<string, mixed> $r */
            $raw = $r['role_id'];
            $ids[] = is_string($raw) ? $raw : '';
        }

        return $ids;
    }

    /**
     * Create a role (no idempotency — admin panel create).
     */
    public function createRole(string $id, string $name, string $description): void
    {
        Statement::prepare($this->pdo,
            'INSERT INTO roles (id, name, description, created_at) VALUES (:id, :name, :description, CURRENT_TIMESTAMP)',
        )->execute([':id' => $id, ':name' => $name, ':description' => $description]);
    }

    /**
     * Delete a role and its pivots (atomic — all or nothing).
     */
    public function deleteRole(string $roleId): void
    {
        $uow = new UnitOfWork($this->pdo);
        $uow->transaction(function (\PDO $pdo) use ($roleId): void {
            Statement::prepare($pdo, 'DELETE FROM role_permissions WHERE role_id = :rid')->execute([':rid' => $roleId]);
            Statement::prepare($pdo, 'DELETE FROM user_roles WHERE role_id = :rid')->execute([':rid' => $roleId]);
            Statement::prepare($pdo, 'DELETE FROM roles WHERE id = :rid')->execute([':rid' => $roleId]);
        });
    }

    public function assignPermission(string $roleId, string $permissionId): void
    {
        $stmt = Statement::prepare($this->pdo,
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:rid, :pid, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([':rid' => $roleId, ':pid' => $permissionId]);
    }

    /**
     * Replace all permissions for a role atomically.
     *
     * @param list<string> $permissionIds Permission ids to assign
     */
    public function syncPermissions(string $roleId, array $permissionIds): void
    {
        $current = $this->permissionIdsForRole($roleId);

        $toRemove = array_values(array_diff($current, $permissionIds));
        $toAdd = array_values(array_diff($permissionIds, $current));

        if ([] === $toRemove && [] === $toAdd) {
            return;
        }

        $uow = new UnitOfWork($this->pdo);
        $uow->transaction(function (\PDO $pdo) use ($roleId, $toRemove, $toAdd): void {
            foreach ($toRemove as $pid) {
                Statement::prepare($pdo, 'DELETE FROM role_permissions WHERE role_id = :rid AND permission_id = :pid')
                    ->execute([':rid' => $roleId, ':pid' => $pid]);
            }
            foreach ($toAdd as $pid) {
                Statement::prepare($pdo, 'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (:rid, :pid, CURRENT_TIMESTAMP)')
                    ->execute([':rid' => $roleId, ':pid' => $pid]);
            }
        });
    }
}
