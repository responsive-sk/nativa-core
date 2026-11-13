<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AuthServiceTest — register() still speaks its legacy error strings after
 * the Validation-module refactor, in the same order.
 *
 * The refactor moved the field rules into `Modules\Validation` but kept the
 * messages (custom `field.rule` strings) and the position of the
 * email-taken error (between the email-format and password errors — the
 * uniqueness check needs the database, so it is not a validation rule and is
 * spliced in afterwards). These assertions pin that contract: identical
 * input must produce byte-identical error lists.
 *
 * The MINIMUM LENGTH moved out of this service in the audit remediation round
 * (finding #3): `PasswordPolicy::MIN_LENGTH` is the owner now, so the strings
 * below read 12 without this file holding a second copy of the number — the
 * literals are the *messages*, which are the contract, and they are asserted
 * against the policy's own message generator.
 *
 * The registration throttle (finding #4) is pinned here at the service level:
 * the per-address limit counts ACCOUNTS CREATED (five per hour), the local
 * address is exempt, and the boundary of the password rule (11 refused, 12
 * accepted) is exercised rather than assumed.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class AuthServiceTest extends TestCase
{
    /** A public, documentation-range address (RFC 5737) — never loopback. */
    private const string REMOTE = '203.0.113.7';

    private AuthService $auth;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Users', 'Validation', 'Security'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Same shape as UsersDatabaseMigrator::createUsersTable().
        $pdo->exec(<<<'SQL'
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
        // UserRepository::hydrate() joins roles/permissions — the columns
        // below are the only ones it touches.
        $pdo->exec('CREATE TABLE roles (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $pdo->exec('CREATE TABLE user_roles (user_id VARCHAR(32) NOT NULL, role_id VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, role_id))');
        $pdo->exec('CREATE TABLE permissions (id VARCHAR(32) PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL)');
        $pdo->exec('CREATE TABLE role_permissions (role_id VARCHAR(32) NOT NULL, permission_id VARCHAR(32) NOT NULL, PRIMARY KEY (role_id, permission_id))');

        $this->pdo = $pdo;
        $this->auth = new AuthService($this->db(), new UserRepository($this->db()), new PasswordHasher(), new RateLimiter($this->db()));
    }

    #[Test]
    public function invalidInputReportsLegacyMessagesInOrder(): void
    {
        $result = $this->auth->register('', 'not-an-email', 'short', 'different', '127.0.0.1');

        $this->assertSame(
            [
                'Name is required (max 255 characters).',
                'A valid email address is required.',
                PasswordPolicy::tooShortMessage(),
                'Password confirmation does not match.',
            ],
            $result['errors'],
        );
        $this->assertSame(0, $result['retryAfter']);
    }

    #[Test]
    public function duplicateEmailKeepsItsPositionBeforePasswordErrors(): void
    {
        $this->assertSame([], $this->auth->register('Eva', 'eva@example.test', 'correct-horse', 'correct-horse', '127.0.0.1')['errors']);

        $result = $this->auth->register('Bob', 'eva@example.test', 'short', 'short', '127.0.0.1');

        $this->assertSame(
            [
                'An account with this email already exists.',
                PasswordPolicy::tooShortMessage(),
            ],
            $result['errors'],
        );
    }

    #[Test]
    public function validInputRegistersWithoutErrors(): void
    {
        $result = $this->auth->register('  Eva  ', 'EVA@EXAMPLE.TEST', 'correct-horse', 'correct-horse', '127.0.0.1');

        $this->assertSame([], $result['errors']);
        $this->assertSame('Eva', $result['user']->name());
        $this->assertSame('eva@example.test', $result['user']->email());
    }

    /**
     * The policy boundary, exercised rather than described: one character
     * under the minimum is refused, exactly at the minimum is accepted.
     */
    #[Test]
    public function theMinimumLengthIsTheBoundary(): void
    {
        $under = str_repeat('p', PasswordPolicy::MIN_LENGTH - 1);

        $this->assertSame(
            [PasswordPolicy::tooShortMessage()],
            $this->auth->register('Eva', 'under@example.test', $under, $under, '127.0.0.1')['errors'],
        );

        $at = str_repeat('p', PasswordPolicy::MIN_LENGTH);

        $this->assertSame([], $this->auth->register('Eva', 'at@example.test', $at, $at, '127.0.0.1')['errors']);
    }

    /**
     * Five accounts per hour from one address; the sixth is refused with the
     * remaining window, and nothing is stored for it.
     */
    #[Test]
    public function aRemoteAddressMayCreateFiveAccountsPerHour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->auth->register('Eva ' . $i, 'e' . $i . '@example.test', 'brand-new-pass', 'brand-new-pass', self::REMOTE);

            $this->assertSame([], $result['errors'], 'registration ' . $i . ' of 5 must be accepted');
            $this->assertSame(0, $result['retryAfter']);
        }

        $refused = $this->auth->register('Eva 6', 'e6@example.test', 'brand-new-pass', 'brand-new-pass', self::REMOTE);

        $this->assertNotSame([], $refused['errors'], 'the sixth registration from one address must be refused');
        $this->assertGreaterThan(0, $refused['retryAfter'], 'a refusal must carry the remaining window');
        $this->assertSame(
            5,
            (int) Statement::query($this->db(), 'SELECT COUNT(*) FROM users')->fetchColumn(),
            'the refused registration must not have created an account',
        );
    }

    /**
     * The local developer is exempt — six accounts in a row from 127.0.0.1 is a
     * person testing the form, not an account factory.
     */
    #[Test]
    public function theLocalAddressIsNeverRegistrationThrottled(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $result = $this->auth->register('Local ' . $i, 'local' . $i . '@example.test', 'brand-new-pass', 'brand-new-pass', '127.0.0.1');

            $this->assertSame([], $result['errors'], 'registration ' . $i . ' from the local address must be accepted');
        }

        $this->assertSame(6, (int) Statement::query($this->db(), 'SELECT COUNT(*) FROM users')->fetchColumn());
    }

    /**
     * The service and its repository now take the connection contract; this
     * wraps the test's in-memory handle without routing anywhere.
     */
    private function db(): PdoConnection
    {
        return new PdoConnection($this->pdo, 'users');
    }
}
