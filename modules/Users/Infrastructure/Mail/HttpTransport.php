<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

/**
 * HttpTransport — the one HTTPS POST every mailer in this tree speaks.
 *
 * Two transports needed the same four things: the stream context that posts
 * JSON without requiring `curl`, the bearer header when a key is configured,
 * the status line parsed out of `$http_response_header`, and the "log the
 * failure, never throw" rule. They were written twice — once in
 * `Users\Infrastructure\Mail\HttpPasswordResetMailer` and once would have been
 * written here — and a second copy drifts the first time one of them learns
 * something (a timeout, a redirect rule, a different auth header).
 *
 * So this class owns the mechanical half and the mailers own their payloads.
 * `streamSender()` stays injectable at the call sites: the suite asserts the
 * endpoint, the headers and the body without network egress.
 */
final class HttpTransport
{
    /**
     * Seconds before a dead endpoint stops holding the request that triggered
     * a delivery. Ten is the value the reset mailer shipped with; a transport
     * that needs longer is a deployment problem, not a constant to raise
     * here (the job it runs in is bounded by the worker, not by this call).
     */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * The default sender: `file_get_contents` with a stream context.
     *
     * No `curl` extension required — a shared host may not have it enabled —
     * and `ignore_errors` because the STATUS is the answer here, not a PHP
     * warning. The warning for an unreachable host is expected and handled by
     * the caller (status 0 + a log line), so it is suppressed rather than
     * printing the same failure twice into a deployment's error stream.
     *
     * @return \Closure(string, list<string>, string): array{status: int, body: string}
     */
    public static function streamSender(): \Closure
    {
        return static function (string $endpoint, array $headers, string $body): array {
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'timeout'       => self::TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($endpoint, false, $context);

            return [
                'status' => self::statusFrom($http_response_header ?? []),
                'body'   => is_string($response) ? $response : '',
            ];
        };
    }

    /**
     * The request headers both transports send: the JSON document and, when the
     * deployment configured one, the bearer key.
     *
     * @return list<string>
     */
    public static function headers(#[\SensitiveParameter] string $apiKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ('' !== $apiKey) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        return $headers;
    }

    /**
     * The status code from the response header block `file_get_contents` fills
     * in — 0 when the connection never produced one (refused, timed out, DNS).
     *
     * @param array<int, string> $headers
     */
    public static function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
