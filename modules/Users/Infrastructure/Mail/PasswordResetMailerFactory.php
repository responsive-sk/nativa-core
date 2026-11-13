<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * PasswordResetMailerFactory — the ONE place that decides how a reset link
 * travels, asked once by the composition root (`AuthServices::resets()`).
 *
 * THREE implementations, one rule. The rule is a WHITELIST of environments that
 * may write the token to a log, not "everything that is not production":
 * `staging`, `qa` or a typo'd `prodction` never get the dev transport, and only
 * the environments that are development by name keep it. Adding an environment
 * is a deliberate edit here rather than a consequence of how someone spelled a
 * variable.
 *
 * Outside those environments the order is: **the configured HTTP transport if
 * there is one, the null mailer otherwise**. `MAIL_API_URL` + `MAIL_FROM` +
 * `APP_URL` is what makes a production reset link actually arrive; without all
 * three, nothing is delivered and nothing is written — the honest state this
 * round inherited, now with a way out of it. A HALF-configured transport is not
 * silently ignored either: it is a link that would go to the wrong host, so the
 * resolution refuses it and says which key is missing.
 *
 * The default when `APP_ENV` is unset is `development`, which is this tree's
 * existing reading of an unset `APP_ENV` (see `SecurityHeadersMiddleware`'s
 * HSTS gate) and what makes a fresh checkout work without a `.env`. A
 * deployment states its environment — `.env.example` ships `APP_ENV` for exactly
 * that — and only an explicit development entry in `$DEV_ENVIRONMENTS` writes a
 * credential to disk.
 *
 * Same shape as `StorageProviderFactory` in Media: one resolution point, the
 * callers ask for the interface and never for a class name.
 */
final class PasswordResetMailerFactory
{
    /**
     * The mailer this environment delivers with.
     */
    public static function create(): PasswordResetMailer
    {
        if (self::writesToLog()) {
            return new DevLogPasswordResetMailer(AppPaths::instance()->logs('app.log'));
        }

        return self::http() ?? new NullPasswordResetMailer();
    }

    /**
     * The configured HTTP transport, or null when this deployment has not
     * configured one (or has configured only part of one).
     *
     * All three keys are required together because each one is load-bearing for
     * a link that WORKS: without the endpoint there is nothing to send through,
     * without the sender address most providers refuse the message, and without
     * `APP_URL` the link cannot be built at all — the port carries no request,
     * so there is no Host to fall back to, and inventing one would send readers
     * to a host the caller does not own.
     *
     * A partially configured deployment is logged, not guessed at: silently
     * sending a link built from a missing base URL is worse than not sending
     * one, and a silent skip would look like "mail is configured, why is nothing
     * arriving".
     */
    public static function http(): ?HttpPasswordResetMailer
    {
        $values = [
            'MAIL_API_URL' => trim((string) (Env::get('MAIL_API_URL') ?? '')),
            'MAIL_FROM'    => trim((string) (Env::get('MAIL_FROM') ?? '')),
            'APP_URL'      => trim((string) (Env::get('APP_URL') ?? '')),
        ];

        $missing = array_keys(array_filter($values, static fn (string $value): bool => '' === $value));

        if (\count($missing) === \count($values)) {
            return null;
        }

        if ([] !== $missing) {
            error_log(sprintf(
                '[mailer] reset mail transport not configured — missing %s; nothing will be delivered (storage/logs/app.log is NOT used as a fallback)',
                implode(', ', $missing),
            ));

            return null;
        }

        return new HttpPasswordResetMailer(
            $values['MAIL_API_URL'],
            trim((string) (Env::get('MAIL_API_KEY') ?? '')),
            $values['MAIL_FROM'],
            $values['APP_URL'],
        );
    }

    /**
     * Whether `create()` returns the dev-log implementation.
     *
     * Read by the surfaces that have to tell a developer where the link went —
     * the success message on `/forgot-password` names `storage/logs/app.log`
     * only where that file is where the link actually is. It is never a
     * security decision: `create()` is what gates delivery, and it asks the
     * same method.
     */
    public static function writesToLog(): bool
    {
        return MailerFactory::writesToLog();
    }
}
