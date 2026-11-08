<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Security\Domain\LocalClient;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Users\Domain\Entities\User;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use Modules\Validation\Validation\Validator;

/**
 * AuthService — registration, authentication, remember-me, logout.
 */
final readonly class AuthService
{
    private const string REMEMBER_COOKIE = 'nativa_remember';

    private const int REMEMBER_TTL = 2_592_000; // 30 days

    // Idle session timeout (audit D-1) — a session is invalidated after this
    // many seconds without activity, even when the absolute lifetime (GC)
    // hasn't been reached. Overridable via SESSION_IDLE_TIMEOUT.
    private const int IDLE_TIMEOUT = 3600; // 60 minutes

    // How often an authenticated request refreshes its row in the DB sessions
    // table (dashboard Sessions metric source). Capped so a busy admin does
    // not write on every single request.
    private const int SESSION_DB_WRITE_INTERVAL = 60; // seconds

    // Registration limit (audit finding #4): accounts created per address per
    // hour. Counted on SUCCESS only — see register() for why an honest reader
    // who mistypes a password five times must not be locked out of the form.
    private const int REGISTER_LIMIT = 5;

    private const int REGISTER_WINDOW = 3600;

    /**
     * Dummy bcrypt hash verified against for unknown/inactive accounts.
     *
     * Generated from random bytes whose plaintext is not recorded anywhere,
     * so no password can ever match it (bcrypt is one-way). The verify()
     * result is discarded — its only purpose is to equalise the login
     * response time with a real credential check, preventing user
     * enumeration via timing side-channel (audit finding F-2).
     *
     * NOTE: generated with default BCRYPT options — keep in sync with
     * PasswordHasher::ALGORITHM so timing stays equivalent.
     */
    private const string DUMMY_PASSWORD_HASH = '$2y$12$6hO.qcb0O30iOs2k/EReUumcibRsIzq2GbYObFalM7RPajh04fCrG';

    public function __construct(
        private \PDO $pdo,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Register a new user with the given credentials.
     *
     * The per-IP limit (audit finding #4) counts ACCOUNTS CREATED, not
     * attempts: it is asked once the input is valid and the address is free,
     * immediately before the insert. Counting every attempt would lock an
     * honest reader out of the form for an hour after five typos, while the
     * thing the limit is for — mass account creation — is bounded either way.
     * Raw request rate stays the global middleware's business (60/min per IP).
     *
     * @return array{user: User, errors: list<string>, retryAfter: int}
     */
    public function register(
        string $name,
        string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $confirmation,
        string $ip,
    ): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));

        // The field rules live in the Validation module; the messages stay
        // byte-identical to the strings this method returned before, so
        // callers (and their assertions) see no change.
        $failures = (new Validator())->validate(
            [
                'name'                  => $name,
                'email'                 => $email,
                'password'              => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name'     => 'required|max:255',
                'email'    => 'required|email',
                'password' => 'required|' . PasswordPolicy::minRule() . '|confirmed',
            ],
            [
                'name.required'      => 'Name is required (max 255 characters).',
                'name.max'           => 'Name is required (max 255 characters).',
                'email.required'     => 'A valid email address is required.',
                'email.email'        => 'A valid email address is required.',
                'password.required'  => PasswordPolicy::tooShortMessage(),
                'password.min'       => PasswordPolicy::tooShortMessage(),
                'password.confirmed' => 'Password confirmation does not match.',
            ],
        );

        // Uniqueness is not a validation rule — it needs the database. It
        // keeps its position between the email-format and password errors,
        // and is only checked when the address itself passed (as before).
        $taken = [] === $failures->forField('email') && $this->users->emailExists($email)
            ? ['An account with this email already exists.']
            : [];

        $errors = array_merge(
            $failures->forField('name'),
            $failures->forField('email'),
            $taken,
            $failures->forField('password'),
        );

        if ([] !== $errors) {
            if ([] !== $failures->forField('password')) {
                $this->debug('[security] register refused by the password policy (min ' . PasswordPolicy::MIN_LENGTH . ' characters)');
            }

            return ['user' => User::fromRow([]), 'errors' => $errors, 'retryAfter' => 0];
        }

        if (!$this->withinRegistrationLimit($ip)) {
            $retryAfter = $this->limiter->getRetryAfter('register:' . $ip);
            $this->debug('[security] registration throttled for ' . $ip . ' (retry in ' . $retryAfter . 's)');

            return [
                'user'       => User::fromRow([]),
                'errors'     => ['Too many registration attempts from this address — try again later.'],
                'retryAfter' => $retryAfter,
            ];
        }

        $user = $this->users->insert([
            'id'       => bin2hex(random_bytes(16)),
            'name'     => $name,
            'email'    => $email,
            'password' => $this->hasher->hash($password),
        ]);

        return ['user' => $user, 'errors' => [], 'retryAfter' => 0];
    }

    /**
     * May this address create another account right now?
     *
     * The local developer is exempt (LocalClient) — the same choice the global
     * middleware and the comments limiter make, for the same reason: a person
     * exercising the form on their own machine is not the abuse this bounds.
     */
    private function withinRegistrationLimit(string $ip): bool
    {
        if (LocalClient::isLoopback($ip)) {
            return true;
        }

        return $this->limiter->isAllowed('register:' . $ip, self::REGISTER_LIMIT, self::REGISTER_WINDOW);
    }

    /**
     * Attempt a login (rate-limited per IP+email).
     *
     * @return array{user: ?User, errors: list<string>}
     */
    public function login(string $email, #[\SensitiveParameter] string $password, bool $remember, string $ip): array
    {
        $email = strtolower(trim($email));
        $key = 'login:' . $ip . ':' . $email;

        if (!$this->limiter->isLoginAllowed($key)) {
            return ['user' => null, 'errors' => ['Too many failed attempts. Try again later.']];
        }

        $user = $this->users->findByEmail($email);

        if (null === $user || !$user->isActive()) {
            // Run a dummy verify (result discarded) so the response time
            // matches a real credential check — no user enumeration via
            // timing side-channel (audit finding F-2).
            $this->hasher->verify($password, self::DUMMY_PASSWORD_HASH);
            $this->limiter->recordLoginAttempt($email, $ip);

            return ['user' => null, 'errors' => ['Invalid credentials.']];
        }

        if (!$this->hasher->verify($password, $user->password())) {
            $this->limiter->recordLoginAttempt($email, $ip);

            return ['user' => null, 'errors' => ['Invalid credentials.']];
        }

        $this->limiter->reset($key);

        if ($this->hasher->needsRehash($user->password())) {
            $this->users->updatePassword($user->id(), $this->hasher->hash($password));
        }

        if ($remember) {
            $this->issueRememberToken($user->id());
        }

        return ['user' => $user, 'errors' => []];
    }

    /**
     * Establish an authenticated session.
     */
    public function establishSession(User $user, string $ip): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        session_regenerate_id(true);
        CsrfMiddleware::regenerateToken();

        /** @var array{auth: array{user_id: string, name: string, email: string, last_activity: int, db_written_at: int}} $session */
        $session = &$_SESSION;
        $session['auth'] = [
            'user_id'       => $user->id(),
            'name'          => $user->name(),
            'email'         => $user->email(),
            'last_activity' => time(),
            'db_written_at' => time(),
        ];
        unset($session);

        $this->users->updateLastLogin($user->id(), $ip);

        // Mirror the new session into the DB sessions table (the dashboard
        // Sessions metric reads this table — file sessions alone are invisible
        // to it). Best-effort: never fails the login.
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $this->persistSessionRow($user->id(), $ip, is_string($userAgent) ? $userAgent : null);
    }

    public function logout(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        // Remove the DB session row before destroying the file session.
        $this->deleteSessionRow();

        $_SESSION = [];
        $sessionName = session_name();
        if (is_string($sessionName) && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                $sessionName,
                '',
                ['expires' => time() - 42_000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly']],
            );
        }
        session_destroy();

        // Clear remember-me cookie + DB token
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($token) && '' !== $token) {
            $stmt = Statement::prepare($this->pdo, 'DELETE FROM remember_tokens WHERE token_hash = :hash');
            $stmt->execute([':hash' => hash('sha256', $token)]);
        }
        // Same Secure flag as when the cookie was issued — audit finding F-5.
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 42_000, 'path' => '/', 'domain' => '', 'secure' => Env::isHttps(), 'httponly' => true]);
    }

    /**
     * Try to authenticate from a remember-me cookie.
     */
    public function authenticateFromRememberCookie(): ?User
    {
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;

        if (!is_string($token) || '' === $token) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = Statement::prepare($this->pdo,
            'SELECT user_id FROM remember_tokens WHERE token_hash = :hash AND expires_at > CURRENT_TIMESTAMP LIMIT 1',
        );
        $stmt->execute([':hash' => $hash]);
        /** @var array{user_id: string}|false $row */
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->users->findById($row['user_id']);
    }

    /**
     * Is the current session authenticated?
     */
    public function isAuthenticated(): bool
    {
        // Lazy session start — GET requests do not pass through the CSRF
        // middleware's token() helper, so the session may not be running yet.
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        /** @var array{auth?: array{user_id: string, last_activity?: int}} $session */
        $session = $_SESSION;

        if (!isset($session['auth']['user_id'])) {
            return false;
        }

        // Idle timeout — drop the auth entry when the session has been
        // inactive longer than the window (audit D-1). Sessions started
        // before last_activity existed (0) are not affected.
        $lastActivity = is_int($session['auth']['last_activity'] ?? null)
            ? $session['auth']['last_activity']
            : 0;
        if (0 !== $lastActivity && time() - $lastActivity > $this->idleTimeout()) {
            unset($_SESSION['auth']);

            return false;
        }

        // Touch the session on every authenticated request.
        /** @var array{user_id: string, last_activity: int, db_written_at?: int} $auth */
        $auth = $_SESSION['auth'];
        $auth['last_activity'] = time();

        // Mirror the touch into the DB sessions table (throttled to once a
        // minute) — the source for the dashboard Sessions metric.
        if (time() - (int) ($auth['db_written_at'] ?? 0) >= self::SESSION_DB_WRITE_INTERVAL) {
            $this->persistSessionRow($auth['user_id'], null, null);
            $auth['db_written_at'] = time();
        }
        $_SESSION['auth'] = $auth;

        return true;
    }

    /**
     * Idle timeout in seconds — SESSION_IDLE_TIMEOUT env override, default
     * 60 minutes. Non-positive values fall back to the default.
     */
    private function idleTimeout(): int
    {
        $timeout = Env::int('SESSION_IDLE_TIMEOUT', self::IDLE_TIMEOUT);

        return $timeout > 0 ? $timeout : self::IDLE_TIMEOUT;
    }

    /**
     * Current authenticated user entity or null.
     */
    public function currentUser(): ?User
    {
        $id = $this->currentUserId();

        return null === $id ? null : $this->users->findById($id);
    }

    /**
     * Current authenticated user id or null.
     */
    public function currentUserId(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        /** @var array{auth: array{user_id: string}} $session */
        $session = $_SESSION;

        return $session['auth']['user_id'];
    }

    private function issueRememberToken(string $userId): void
    {
        $token = bin2hex(random_bytes(32));

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO remember_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (:id, :uid, :hash, datetime(\'now\', \'+30 days\'), CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id'   => bin2hex(random_bytes(16)),
            ':uid'  => $userId,
            ':hash' => hash('sha256', $token),
        ]);

        // Secure flag auto-detected from HTTPS — audit finding F-5.
        setcookie(self::REMEMBER_COOKIE, $token, ['expires' => time() + self::REMEMBER_TTL, 'path' => '/', 'domain' => '', 'secure' => Env::isHttps(), 'httponly' => true]);
    }

    /**
     * Storage key for the current PHP session in the DB sessions table.
     * The PHP session id can exceed the VARCHAR(32) column, so a fixed-length
     * hash is used instead (deterministic per session id).
     */
    private static function dbSessionKey(): string
    {
        return substr(hash('sha256', session_id() ?: ''), 0, 32);
    }

    /**
     * Insert or refresh the DB row tracking the current authenticated session.
     *
     * The created_at column is only set on first insert (login time), so the
     * dashboard can count "new sessions in period" from it; subsequent touches
     * refresh only last_activity. Best-effort — session tracking must never
     * break an auth flow, so failures are swallowed.
     */
    private function persistSessionRow(string $userId, ?string $ip, ?string $userAgent): void
    {
        try {
            $stmt = Statement::prepare($this->pdo,
                'INSERT INTO sessions (id, user_id, ip_address, user_agent, last_activity)
                 VALUES (:id, :uid, :ip, :ua, datetime(\'now\'))
                 ON CONFLICT(id) DO UPDATE SET
                     user_id = excluded.user_id,
                     last_activity = excluded.last_activity',
            );
            $stmt->execute([
                ':id'  => self::dbSessionKey(),
                ':uid' => $userId,
                ':ip'  => $ip,
                ':ua'  => $userAgent,
            ]);
        } catch (\Throwable) {
            // Best-effort — the table may not exist on a fresh DB yet.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    /**
     * Delete the DB row for the current session (called on logout).
     */
    private function deleteSessionRow(): void
    {
        try {
            $stmt = Statement::prepare($this->pdo, 'DELETE FROM sessions WHERE id = :id');
            $stmt->execute([':id' => self::dbSessionKey()]);
        } catch (\Throwable) {
            // Best-effort.
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    /**
     * Verbose development aid, gated exactly like
     * `AuthMiddleware::logDenial()`: the facts a developer needs when a limit
     * refuses them are printed at LOG_LEVEL=debug and cost nothing otherwise.
     * Refusals name the field and the address — never a credential.
     */
    private function debug(string $line): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log($line);
    }
}
