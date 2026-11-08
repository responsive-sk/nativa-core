<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Core\Domain\Contracts\Mailer;

/**
 * HttpMailer — one HTTPS POST per message, in the JSON shape this tree already
 * documents for mail (`{from, to, subject, text}` + bearer key).
 *
 * The same reasoning as `Users\Infrastructure\Mail\HttpPasswordResetMailer`
 * applies and is not repeated in full there: the kernel has zero Composer
 * runtime dependencies, so a provider SDK is not an option, while one documented
 * JSON POST is what most transactional APIs accept (Resend, Plunk, Loops,
 * SendGrid's personalisation JSON). Postmark's capitalised body needs its own
 * implementation next to this one — not a flag inside it.
 *
 * Failure is logged with the recipient and the status, and `send()` returns
 * false. Nothing is ever written to a log file as a fallback: for a notification
 * that would be a copy of user content beside the app log, and for anything
 * credential-shaped it is the audit finding `PasswordResetMailer` was built to
 * remove. The throwaway sentence above is the whole rule: **no payload ever
 * lands on disk because delivery failed.**
 */
final readonly class HttpMailer implements Mailer
{
    /**
     * @param \Closure(string $endpoint, list<string> $headers, string $body): array{status: int, body: string}|null $sender
     *        the transport call, injectable so the suite can assert the request
     *        without network egress; `null` means the stream implementation
     */
    public function __construct(
        private string $endpoint,
        #[\SensitiveParameter] private string $apiKey,
        private string $from,
        private ?\Closure $sender = null,
    ) {
    }

    public function send(string $to, string $subject, string $text): bool
    {
        $payload = json_encode([
            'from'    => $this->from,
            'to'      => $to,
            'subject' => $subject,
            'text'    => $text,
        ], JSON_THROW_ON_ERROR);

        $sender = $this->sender ?? HttpTransport::streamSender();

        try {
            $response = $sender($this->endpoint, HttpTransport::headers($this->apiKey), $payload);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[mailer:http] "%s" to %s could not be delivered to %s: %s',
                $subject,
                $to,
                $this->endpoint,
                $e->getMessage(),
            ));

            return false;
        }

        $status = $response['status'] ?? 0;

        if ($status < 200 || $status >= 300) {
            error_log(sprintf(
                '[mailer:http] "%s" to %s rejected by %s (status %d)',
                $subject,
                $to,
                $this->endpoint,
                $status,
            ));

            return false;
        }

        return true;
    }
}
