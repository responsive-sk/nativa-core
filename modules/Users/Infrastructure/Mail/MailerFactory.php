<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\Mailer;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * MailerFactory — the ONE place that decides how a message leaves this tree.
 *
 * Same shape as `Users\Infrastructure\Mail\PasswordResetMailerFactory`, and
 * deliberately the same rule, because a second policy for "which environment
 * may write mail to disk" is how one of them quietly becomes the permissive one:
 *
 *   - an environment NAMED as development gets `DevLogMailer`;
 *   - anything else gets the configured HTTP transport, or `NullMailer` when
 *     none is configured.
 *
 * The whitelist is the load-bearing half. `staging`, `qa` and a typo'd
 * `prodction` are not development, so they never get a log file with user
 * content in it. `PasswordResetMailerFactory::writesToLog()` now asks this
 * method instead of carrying a second copy of the list — one list, two callers.
 *
 * ## What a notification transport needs that a reset link does not
 *
 * A reset link needs `APP_URL` because the link cannot be built without it, so
 * half a transport there is refused. A notification is built by its caller and
 * carries whatever link the caller could build, so the two required keys are
 * `MAIL_API_URL` and `MAIL_FROM`; `MAIL_API_KEY` stays optional (a same-host
 * relay may not want one). A half-configured transport is logged by name, not
 * guessed at: sending through an endpoint with no sender address is refused by
 * most providers with a message an operator will never see in this log.
 */
final class MailerFactory
{
    /**
     * Environments whose missing transport is covered by the dev log.
     *
     * Not the negation of `production` on purpose: an unrecognised name is an
     * environment nobody classified, and an unclassified environment must not
     * be the one that writes mail bodies to disk.
     */
    private const array DEV_ENVIRONMENTS = ['development', 'local', 'testing', 'test', 'dev'];

    /**
     * The mailer this environment delivers with.
     */
    public static function create(): Mailer
    {
        if (self::writesToLog()) {
            return new DevLogMailer(AppPaths::instance()->logs('app.log'));
        }

        return self::http() ?? new NullMailer();
    }

    /**
     * The configured HTTP transport, or null when this deployment has not
     * configured one (or has configured only part of one).
     */
    public static function http(): ?HttpMailer
    {
        $endpoint = trim((string) (Env::get('MAIL_API_URL') ?? ''));
        $from = trim((string) (Env::get('MAIL_FROM') ?? ''));

        $missing = array_keys(array_filter(
            ['MAIL_API_URL' => $endpoint, 'MAIL_FROM' => $from],
            static fn (string $value): bool => '' === $value,
        ));

        if ([] === $missing) {
            return new HttpMailer($endpoint, trim((string) (Env::get('MAIL_API_KEY') ?? '')), $from);
        }

        // Both empty is the ordinary "this deployment sends no mail" state and
        // needs no warning; ONE empty is a misconfiguration that would fail on
        // the first notification, so it is named here instead.
        if (2 !== \count($missing)) {
            error_log(sprintf(
                '[mailer] mail transport not configured — missing %s; nothing will be delivered (storage/logs/app.log is NOT used as a fallback)',
                implode(', ', $missing),
            ));
        }

        return null;
    }

    /**
     * Whether `create()` returns the dev-log implementation.
     *
     * Read by `PasswordResetMailerFactory` (one list, two callers) and by any
     * surface that has to tell a developer where the message went. Never a
     * security decision by itself: `create()` asks this same method.
     */
    public static function writesToLog(): bool
    {
        return in_array(
            strtolower(Env::get('APP_ENV', 'development') ?? 'development'),
            self::DEV_ENVIRONMENTS,
            true,
        );
    }
}
