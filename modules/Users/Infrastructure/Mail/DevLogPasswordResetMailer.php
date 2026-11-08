<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * DevLogPasswordResetMailer — the development transport: the reset line is
 * APPENDED to a log file instead of being sent, because the starter kit ships
 * no SMTP transport and the flow has to be usable without one.
 *
 * What it writes is a working credential — the plaintext token, beside the
 * address it belongs to — so it is picked only for an environment that says it
 * is development (`PasswordResetMailerFactory::$DEV_ENVIRONMENTS`). The file is
 * `storage/logs/app.log`: local, gitignored, and already the place a developer
 * looks.
 *
 * The log file is INJECTED rather than resolved in here, so a test can point it
 * at a temporary file and assert what lands in it without touching the real log
 * (the same reason every path in this tree comes from `AppPaths`).
 */
final readonly class DevLogPasswordResetMailer implements PasswordResetMailer
{
    public function __construct(private string $logFile)
    {
    }

    public function sendResetLink(string $email, #[\SensitiveParameter] string $token, int $ttlHours): void
    {
        $line = sprintf(
            "[%s] [mailer:dev] Password reset for %s → token: %s (valid %d h)\n",
            date('Y-m-d H:i:s'),
            $email,
            $token,
            $ttlHours,
        );

        $dir = dirname($this->logFile);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            error_log(sprintf(
                '[mailer:dev] cannot create %s — the reset link for %s was not written',
                $dir,
                $email,
            ));

            return;
        }

        if (false === @file_put_contents($this->logFile, $line, FILE_APPEND)) {
            error_log(sprintf(
                '[mailer:dev] cannot write %s — the reset link for %s was not written',
                $this->logFile,
                $email,
            ));
        }
    }
}
