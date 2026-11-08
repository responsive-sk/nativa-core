<?php

declare(strict_types = 1);

namespace Modules\Users\Domain\Entities;

use Modules\Core\Domain\Permissions;

/**
 * User domain entity.
 */
final class User
{
    /** @var list<string> role names */
    private array $roles = [];

    /** @var list<string> permission names */
    private array $permissions = [];

    /** Normalized once at the boundary (fromRow) — no re-guessing per call. */
    private readonly bool $isActive;

    private readonly ?string $lastLoginAt;

    /**
     * @param array<string, mixed> $data raw row from users table
     */
    public function __construct(private array $data)
    {
        $active = $data['is_active'] ?? 1;
        $this->isActive = 1 === (is_numeric($active) ? (int) $active : 0);

        $lastLogin = $data['last_login_at'] ?? null;
        $this->lastLoginAt = is_string($lastLogin) && '' !== $lastLogin ? $lastLogin : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function id(): string
    {
        /** @var string $id */
        $id = $this->data['id'];

        return $id;
    }

    public function name(): string
    {
        /** @var string $name */
        $name = $this->data['name'];

        return $name;
    }

    public function email(): string
    {
        /** @var string $email */
        $email = $this->data['email'];

        return $email;
    }

    /**
     * The avatar reference: a relative stored path (`2026/08/09/<hex>.png`)
     * for older/direct uploads, or a public media URL when picked from the
     * library. Null when the user has no avatar.
     */
    public function avatar(): ?string
    {
        $avatar = $this->data['avatar'] ?? null;

        return is_string($avatar) && '' !== $avatar ? $avatar : null;
    }

    public function password(): string
    {
        /** @var string $password */
        $password = $this->data['password'];

        return $password;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function lastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): string
    {
        /** @var string $createdAt */
        $createdAt = $this->data['created_at'];

        return $createdAt;
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    /**
     * @param list<string> $permissions
     */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Check whether the user holds a permission, either directly or through
     * any of their roles.
     */
    public function hasPermission(string $permission): bool
    {
        if (in_array($permission, $this->permissions, true)) {
            return true;
        }

        // 'admin' role bypasses permission checks (super-user).
        if (in_array('admin', $this->roles, true)) {
            return true;
        }

        return false;
    }

    /**
     * Whether this account may open the admin panel.
     *
     * One question with two readers that must agree — the sidebar's nav filter
     * and the inbox fan-out that decides who a `notify.admin` job is addressed
     * to — so it is asked here, through `hasPermission()` (which already carries
     * the admin-role bypass), rather than spelled as a role check in one place
     * and a permission check in the other.
     */
    public function canAccessPanel(): bool
    {
        return $this->hasPermission(Permissions::MARK_ACCESS);
    }
}
