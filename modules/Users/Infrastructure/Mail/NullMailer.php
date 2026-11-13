<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\Mailer;

/**
 * NullMailer — no transport is configured, so nothing is delivered and nothing
 * is written.
 *
 * A real implementation, not an absent one: the caller asks the port and does
 * not care what answers, so a comment still saves and a job still completes.
 * The alternative — silently writing the message to the app log — is how a
 * credential turns up on disk in a deployment that believed it had no mailer.
 *
 * The drop is noted at `LOG_LEVEL=debug` because "the notification never
 * arrives" is otherwise indistinguishable from a broken transport. It names the
 * recipient and the subject, never the body.
 */
final class NullMailer implements Mailer
{
    public function send(string $to, string $subject, string $text): bool
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return false;
        }

        error_log(sprintf(
            '[mailer:null] "%s" to %s was dropped — no mail transport is configured (APP_ENV=%s), and nothing was written to disk',
            $subject,
            $to,
            (string) (getenv('APP_ENV') ?: 'development'),
        ));

        return false;
    }
}
