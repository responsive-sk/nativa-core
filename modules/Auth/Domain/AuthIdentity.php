<?php

declare(strict_types = 1);

namespace Modules\Auth\Domain;

/**
 * AuthIdentity — who the request authenticated as, in the Auth module's shape.
 *
 * The signed-in user TYPE crossing the module boundary was the edge the auth
 * port removes: `Modules\Users\Domain\Entities\User` cannot appear in the
 * middleware's signature, so the provider maps whatever it owns (session row,
 * token, remember-me cookie) into this value object. Three facts are all the
 * middleware ever asked the entity: the id (for the denial log), whether the
 * account is active, and which permissions it holds.
 */
final readonly class AuthIdentity
{
    /**
     * @param list<string> $permissions permission names the identity holds
     */
    public function __construct(
        public string $id,
        public bool $active,
        public array $permissions = [],
    ) {
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
