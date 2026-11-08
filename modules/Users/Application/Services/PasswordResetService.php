<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Core\Domain\SubmitResult;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Security\Application\Services\RateLimiter;
use Modules\Security\Domain\LocalClient;
use Modules\Security\Domain\PasswordPolicy;
use Modules\Users\Domain\Contracts\PasswordResetMailer;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Infrastructure\Persistence\Statement;
use Modules\Validation\Validation\Validator;

/**
 * PasswordResetService — request token → reset flow.
 *
 * Delivery is not this class's business: it stores the token (hashed), asks
 * the injected `PasswordResetMailer` to carry the link, and answers the same
 * thing whether the address exists or not. WHERE the link may travel is an
 * environment fact resolved once at wiring time — before this port existed the
 * service appended the plaintext token to `storage/logs/app.log` itself, in
 * every environment, so a production deployment without a mail transport kept
 * a working credential for a live account on disk (audit finding #1).
 *
 * The request itself is limited per address AND per target (audit finding #6):
 * an endpoint that mints a token for any address it is handed is otherwise a
 * free amplifier for mail (or, in this tree, log lines) sent to a victim's
 * inbox.
 */
final readonly class PasswordResetService
{
    private const int TOKEN_TTL_HOURS = 1;

    /** Requests per address per window (15 minutes). */
    private const int FORGOT_LIMIT = 5;

    private const int FORGOT_WINDOW = 900;

    /** Requests targeting one address per window (1 hour). */
    private const int FORGOT_EMAIL_LIMIT = 3;

    private const int FORGOT_EMAIL_WINDOW = 3600;

    public function __construct(
        private \PDO $pdo,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private PasswordResetMailer $mailer,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Create a reset token for an email (always succeeds to avoid user
     * enumeration) and hand the link to the configured mailer.
     *
     * The raw token leaves this method only through the port — it is not
     * returned, so no caller can decide to record it somewhere. A credential
     * that is only ever handed to a transport cannot end up in a second place
     * by accident, which is exactly how it ended up in the log before.
     *
     * Both limits are asked BEFORE the token row is rewritten: a refused
     * request must not invalidate the link the reader may already hold. The
     * keys are the caller's own inputs — their address, and the address they
     * typed — never whether an account exists, so a 429 here says nothing
     * about anyone's account and the flow stays non-enumerable.
     *
     * @return SubmitResult<array{retryAfter: int}>
     */
    public function requestReset(string $email, string $ip): SubmitResult
    {
        $email = strtolower(trim($email));

        $retryAfter = $this->throttle($email, $ip);

        if (0 < $retryAfter) {
            $this->debug('[security] reset request throttled (retry in ' . $retryAfter . 's)');

            return SubmitResult::failure(
                'Too many password reset requests — try again later.',
                ['retryAfter' => $retryAfter],
            );
        }

        $token = bin2hex(random_bytes(32));

        // Invalidate previous unused tokens for this email.
        Statement::prepare($this->pdo, 'DELETE FROM password_reset_tokens WHERE email = :email AND used_at IS NULL')
            ->execute([':email' => $email]);

        $stmt = Statement::prepare($this->pdo,
            'INSERT INTO password_reset_tokens (id, email, token, expires_at, created_at)
             VALUES (:id, :email, :token, datetime(\'now\', :ttl), CURRENT_TIMESTAMP)',
        );
        $stmt->execute([
            ':id'    => bin2hex(random_bytes(16)),
            ':email' => $email,
            ':token' => hash('sha256', $token),
            ':ttl'   => '+' . self::TOKEN_TTL_HOURS . ' hours',
        ]);

        // The mailer decides what happens to the link: the dev transport appends
        // it to storage/logs/app.log, the null transport drops it. Neither path
        // changes the response, and neither throws — see the port's docblock.
        $this->mailer->sendResetLink($email, $token, self::TOKEN_TTL_HOURS);

        return SubmitResult::success(['retryAfter' => 0]);
    }

    /**
     * Seconds until this caller may ask for another link, or 0 when allowed.
     *
     * The local developer is exempt (LocalClient) — the same choice the global
     * middleware, the comments limiter and the registration limit make. The
     * per-address key survives that exemption's purpose unchanged: it is what
     * bounds requests aimed at ONE inbox, and it is checked per address rather
     * than per caller.
     */
    private function throttle(string $email, string $ip): int
    {
        if (LocalClient::isLoopback($ip)) {
            return 0;
        }

        if (!$this->limiter->isAllowed('forgot:' . $ip, self::FORGOT_LIMIT, self::FORGOT_WINDOW)) {
            return $this->limiter->getRetryAfter('forgot:' . $ip);
        }

        if (!$this->limiter->isAllowed('forgot-email:' . $email, self::FORGOT_EMAIL_LIMIT, self::FORGOT_EMAIL_WINDOW)) {
            return $this->limiter->getRetryAfter('forgot-email:' . $email);
        }

        return 0;
    }

    /**
     * Validate + consume a reset token and update the password.
     *
     * @return list<string> errors (empty on success)
     */
    public function reset(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $confirmation,
    ): array
    {
        // Same rule shape as AuthService::register() (minus the name/email
        // fields) and the same two legacy strings — an empty password
        // reports the min message via `required`, exactly as before.
        $errors = (new Validator())->validate(
            [
                'password'              => $password,
                'password_confirmation' => $confirmation,
            ],
            ['password' => 'required|' . PasswordPolicy::minRule() . '|confirmed'],
            [
                'password.required'  => PasswordPolicy::tooShortMessage(),
                'password.min'       => PasswordPolicy::tooShortMessage(),
                'password.confirmed' => 'Password confirmation does not match.',
            ],
        )->all();

        if ([] !== $errors) {
            if (in_array(PasswordPolicy::tooShortMessage(), $errors, true)) {
                $this->debug('[security] password reset refused by the password policy (min ' . PasswordPolicy::MIN_LENGTH . ' characters)');
            }

            return $errors;
        }

        $hash = hash('sha256', $token);
        $stmt = Statement::prepare($this->pdo,
            'SELECT id, email FROM password_reset_tokens
             WHERE token = :hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1',
        );
        $stmt->execute([':hash' => $hash]);
        /** @var array{id: string, email: string}|false $row */
        $row = $stmt->fetch();

        if (false === $row) {
            return ['This reset link is invalid or has expired.'];
        }

        $user = $this->users->findByEmail($row['email']);

        if (null === $user) {
            return ['This reset link is invalid or has expired.'];
        }

        $this->users->updatePassword($user->id(), $this->hasher->hash($password));

        Statement::prepare($this->pdo, 'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $row['id']]);

        return [];
    }

    /**
     * Verbose development aid, gated exactly like `AuthService::debug()` — the
     * same `LOG_LEVEL=debug` switch the rest of the tree uses. Lines name the
     * limit or the field, never a token or a password.
     */
    private function debug(string $line): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log($line);
    }
}
