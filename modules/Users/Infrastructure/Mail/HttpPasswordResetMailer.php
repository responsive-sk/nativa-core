<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\PasswordResetMailer;

/**
 * HttpPasswordResetMailer — the production transport this tree ships: one HTTPS
 * POST to an endpoint the deployment configures.
 *
 * ## Why a generic HTTP transport instead of a provider SDK
 *
 * The kernel has zero Composer runtime dependencies, and that claim is load
 * bearing (CI boots the tree without `composer install`). A mailer that needs a
 * vendor SDK would end the claim for every deployment that wants reset links;
 * a mailer that speaks one documented JSON contract over HTTPS does not. The
 * trade is honest and worth stating: **the endpoint must speak this shape**
 *
 * ```
 * POST <MAIL_API_URL>
 * Authorization: Bearer <MAIL_API_KEY>      # omitted when no key is configured
 * Content-Type: application/json
 *
 * {"from": "…", "to": "…", "subject": "…", "text": "…"}
 * ```
 *
 * which is what most transactional APIs accept (Resend, SendGrid v3's personal
 * -ization JSON, Plunk, Loops …). An endpoint with a different body shape —
 * Postmark's `From`/`To`/`TextBody` capitals, say — needs a transport of its
 * own next to this one, not a flag inside it: `PasswordResetMailerFactory` is
 * the single place that picks, and a second implementation costs one branch
 * there.
 *
 * ## Failure is logged, never thrown, and never falls back to the log
 *
 * Delivery is best effort by contract (see the port's docblock): the flow must
 * answer the same neutral thing whether the transport works, fails or is
 * absent. So a refused connection, a non-2xx answer or a malformed payload is
 * written to `error_log` with the ADDRESS and the STATUS — never the token —
 * and `sendResetLink()` returns. There is deliberately no "and then write it to
 * the log file" fallback: that fallback IS the audit finding this port was
 * built to remove, and a deployment whose transport is down should learn it
 * from the log line naming the failure, not from a credential on disk.
 *
 * ## What it needs from the environment
 *
 * `MAIL_API_URL`, `MAIL_FROM` and `APP_URL` (the last one to build the link a
 * reader can actually click — the port has no request, so the base URL cannot
 * be inferred from one). `MAIL_API_KEY` is optional: a same-host relay or a
 * private bridge may not want one. All of it is read in
 * `PasswordResetMailerFactory::http()`, which is also where a HALF-configured
 * deployment is refused loudly rather than delivering a link to the wrong host.
 */
final readonly class HttpPasswordResetMailer implements PasswordResetMailer
{
    /** The path the reset form lives at — the module owns it (`PasswordResetAction`). */
    private const string RESET_PATH = '/reset-password';

    /**
     * @param \Closure(string $endpoint, list<string> $headers, string $body): array{status: int, body: string}|null $sender
     *        the transport call, injectable so the suite can assert the request
     *        without network egress; `null` means the stream implementation below
     */
    public function __construct(
        private string $endpoint,
        #[\SensitiveParameter] private string $apiKey,
        private string $from,
        private string $baseUrl,
        private ?\Closure $sender = null,
    ) {
    }

    /**
     * The link this deployment's readers are meant to click.
     *
     * Built from `APP_URL`, not from a request: the port carries no Request by
     * contract, and a link built from a Host header the caller controls is a
     * phishing surface, not a convenience.
     */
    public function resetLink(#[\SensitiveParameter] string $token): string
    {
        return rtrim($this->baseUrl, '/') . self::RESET_PATH . '?token=' . $token;
    }

    public function sendResetLink(string $email, #[\SensitiveParameter] string $token, int $ttlHours): void
    {
        $payload = json_encode([
            'from'    => $this->from,
            'to'      => $email,
            'subject' => 'Reset your password',
            'text'    => sprintf(
                "Somebody asked to reset the password for this address.\n\n%s\n\nThe link works once and expires in %d hour(s). If this was not you, ignore this message — nothing has changed.",
                $this->resetLink($token),
                $ttlHours,
            ),
        ], JSON_THROW_ON_ERROR);

        $sender = $this->sender ?? HttpTransport::streamSender();

        try {
            $response = $sender($this->endpoint, HttpTransport::headers($this->apiKey), $payload);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[mailer:http] reset link for %s could not be delivered to %s: %s',
                $email,
                $this->endpoint,
                $e->getMessage(),
            ));

            return;
        }

        $status = $response['status'] ?? 0;

        if ($status < 200 || $status >= 300) {
            error_log(sprintf(
                '[mailer:http] reset link for %s rejected by %s (status %d)',
                $email,
                $this->endpoint,
                $status,
            ));
        }
    }

}
