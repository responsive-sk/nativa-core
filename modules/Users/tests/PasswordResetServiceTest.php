<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Infrastructure\Mail\NullPasswordResetMailer;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PasswordResetServiceTest — reset() keeps its legacy error strings after
 * the Validation-module refactor (same two password messages as
 * AuthService::register(), same invalid-link message, same order).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class PasswordResetServiceTest extends TestCase
{
    private PDO $pdo;

    private PasswordResetService $resets;

    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Validation', 'Security'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Same shape as UsersDatabaseMigrator (users + reset tokens).
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                avatar VARCHAR(255),
                is_active BOOLEAN DEFAULT 1,
                last_login_at DATETIME,
                last_login_ip VARCHAR(45),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                id VARCHAR(32) PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(64) UNIQUE NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        // UserRepository::hydrate() joins roles/permissions — the columns
        // below are the only ones it touches.
        $this->pdo->exec('CREATE TABLE roles (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $this->pdo->exec('CREATE TABLE user_roles (user_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, role_id))');
        $this->pdo->exec('CREATE TABLE permissions (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $this->pdo->exec('CREATE TABLE role_permissions (role_id VARCHAR(32) NOT NULL, permission_id VARCHAR(32) NOT NULL, PRIMARY KEY (role_id, permission_id))');

        $this->hasher = new PasswordHasher();
        $users = new UserRepository($this->pdo);
        $users->insert([
            'id'       => bin2hex(random_bytes(16)),
            'name'     => 'Eva',
            'email'    => 'eva@example.test',
            'password' => $this->hasher->hash('correct-horse'),
        ]);

        // This file is about reset()'s answers, so the delivery port is the one
        // that delivers nothing: what a mailer does with a link is covered by
        // PasswordResetMailerTest (audit finding #1). The limiter is real — the
        // service's other job (finding #6) is bounded by it.
        $this->resets = new PasswordResetService(
            $this->pdo,
            $users,
            $this->hasher,
            new NullPasswordResetMailer(),
            new RateLimiter($this->pdo),
        );
    }

    #[Test]
    public function shortPasswordReportsLegacyMessage(): void
    {
        $this->assertSame(
            [PasswordPolicy::tooShortMessage()],
            $this->resets->reset('whatever-token', 'short', 'short'),
        );
    }

    #[Test]
    public function emptyPasswordReportsLegacyMessageOnce(): void
    {
        $this->assertSame(
            [PasswordPolicy::tooShortMessage()],
            $this->resets->reset('whatever-token', '', ''),
        );
    }

    #[Test]
    public function mismatchedConfirmationReportsLegacyMessage(): void
    {
        $this->assertSame(
            ['Password confirmation does not match.'],
            $this->resets->reset('whatever-token', 'long-enough-pw', 'different'),
        );
    }

    #[Test]
    public function unknownTokenReportsInvalidLink(): void
    {
        $this->assertSame(
            ['This reset link is invalid or has expired.'],
            $this->resets->reset('no-such-token', 'long-enough-pw', 'long-enough-pw'),
        );
    }

    #[Test]
    public function validTokenResetsPasswordAndBurnsToken(): void
    {
        $token = bin2hex(random_bytes(32));
        Statement::prepare($this->pdo,
            "INSERT INTO password_reset_tokens (id, email, token, expires_at, created_at)\n             VALUES (:id, :email, :token, datetime('now', '+1 hour'), CURRENT_TIMESTAMP)",
        )->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':email' => 'eva@example.test',
            ':token' => hash('sha256', $token),
        ]);

        $this->assertSame([], $this->resets->reset($token, 'brand-new-pass', 'brand-new-pass'));

        $user = (new UserRepository($this->pdo))->findByEmail('eva@example.test');
        $this->assertNotNull($user);
        $this->assertTrue($this->hasher->verify('brand-new-pass', $user->password()));

        // The token is single-use.
        $this->assertSame(
            ['This reset link is invalid or has expired.'],
            $this->resets->reset($token, 'another-pass', 'another-pass'),
        );
    }

    /**
     * The per-TARGET limit (audit finding #6): one address may be mailed three
     * times an hour. Four DIFFERENT callers — so the per-caller key never
     * trips — still cannot aim more than three requests at the same inbox,
     * which is what stops the endpoint being an amplifier for somebody else's
     * address.
     */
    #[Test]
    public function oneAddressMayBeTargetedThreeTimesAnHour(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $result = $this->resets->requestReset('eva@example.test', '203.0.113.' . $i);

            $this->assertTrue($result->ok, 'request ' . $i . ' of 3 for the same address must be allowed');
            $this->assertSame(0, $result->context['retryAfter']);
        }

        $refused = $this->resets->requestReset('eva@example.test', '203.0.113.9');

        $this->assertFalse($refused->ok, 'the fourth request for one address must be refused');
        $this->assertGreaterThan(
            3500,
            $refused->context['retryAfter'],
            'the refusal must carry the per-address window',
        );
    }

    /**
     * The same three requests from the local address never trip: the developer
     * exercising the form is not the abuse the limit bounds (LocalClient).
     */
    #[Test]
    public function theLocalAddressIsNeverTargetLimited(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->resets->requestReset('eva@example.test', '127.0.0.1');

            $this->assertTrue($result->ok, 'request ' . $i . ' from the local address must be allowed');
        }
    }
}
