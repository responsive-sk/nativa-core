<?php

declare(strict_types = 1);

namespace Modules\Security\Application\Services;

/**
 * PasswordHasher — native password hashing helper.
 *
 * Uses password_hash()/password_verify() with the BCRYPT algorithm
 * (compatible with shared hosting where argon2id's 64 MB memory cost
 * exceeds limits). Zero dependencies — pure PHP core functions.
 *
 * Not final so integration tests can double it (e.g. asserting that
 * unknown-email logins still run a verify — audit F-2 regression).
 */
class PasswordHasher
{
    public const string ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Hash a plaintext password.
     */
    public function hash(string $plain): string
    {
        return password_hash($plain, self::ALGORITHM);
    }

    /**
     * Verify a plaintext password against a stored hash.
     */
    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Whether a stored hash should be re-hashed (algorithm/params drift).
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM);
    }
}
