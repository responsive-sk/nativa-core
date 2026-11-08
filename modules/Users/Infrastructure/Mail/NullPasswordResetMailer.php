<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * NullPasswordResetMailer — no transport is configured, so nothing is
 * delivered and nothing is written.
 *
 * This is the answer for production in this tree: the kernel has no mail
 * transport, and the alternative to "silently drop it" was the bug — a
 * plaintext reset token for a live account appended to `storage/logs/app.log`
 * (audit finding #1). Read the log, take the account.
 *
 * It is a real implementation rather than an absent one: `PasswordResetService`
 * asks the port and does not care what answers, so the request still ends in the
 * same neutral response and the flow stays non-enumerable. The drop is noted at
 * `LOG_LEVEL=debug` — the address, never the token — because "the reset mail
 * never arrives" is otherwise indistinguishable from a broken mailer.
 */
final class NullPasswordResetMailer implements PasswordResetMailer
{
    public function sendResetLink(string $email, #[\SensitiveParameter] string $token, int $ttlHours): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log(sprintf(
            '[mailer:null] reset link for %s was dropped — no mailer is configured for this environment (APP_ENV=%s), so no token was written anywhere',
            $email,
            (string) (getenv('APP_ENV') ?: 'development'),
        ));
    }
}
