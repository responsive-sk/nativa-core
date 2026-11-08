<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Domain\Contracts\Mailer;

/**
 * DevLogMailer — the development transport: the message is APPENDED to the log
 * a developer already reads instead of being sent, because a starter kit ships
 * no SMTP transport and a notification has to be inspectable without one.
 *
 * Picked only for an environment that names itself development
 * (`MailerFactory::writesToLog()`), which is the same whitelist — not the
 * negation of "production" — the reset link has: `staging` or a typo never
 * writes mail to disk.
 *
 * Unlike the reset transport this one writes the WHOLE body, and that is
 * deliberate: a notification is the thing being tested ("did the comment reach
 * the admin?"), so a line that only said "a mail was sent" would make the
 * transport useless for its one purpose. It must therefore never carry a
 * credential — see the port's docblock; the callers in this tree carry an
 * excerpt and a link, nothing else.
 *
 * The file is INJECTED rather than resolved in here, so a test can point it at
 * a temporary file and assert what lands in it without touching the real log
 * (the same reason every path in this tree comes from `AppPaths`).
 */
final readonly class DevLogMailer implements Mailer
{
    public function __construct(private string $logFile)
    {
    }

    public function send(string $to, string $subject, string $text): bool
    {
        $line = sprintf(
            "[%s] [mailer:dev] To: %s\nSubject: %s\n\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            rtrim($text, "\n"),
        );

        $dir = dirname($this->logFile);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            error_log(sprintf(
                '[mailer:dev] cannot create %s — the message to %s was not written',
                $dir,
                $to,
            ));

            return false;
        }

        if (false === @file_put_contents($this->logFile, $line, FILE_APPEND)) {
            error_log(sprintf(
                '[mailer:dev] cannot write %s — the message to %s was not written',
                $this->logFile,
                $to,
            ));

            return false;
        }

        return true;
    }
}
