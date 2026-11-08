<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Infrastructure\Http;

use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Domain\ClickHouseError;

/**
 * ClickHouseHttpClient — the wire, and nothing else.
 *
 * ClickHouse's HTTP interface takes the statement as the request body and
 * answers the rows directly, so one POST is one statement: no protocol
 * handshake, no driver, no Composer package. The database is a query
 * parameter (`?database=`), not part of the SQL, so a name cannot become
 * syntax (see the interface docblock).
 *
 * Retries are the same three the Cloudinary client uses: `429`, `502` and
 * `503` are transient, `Retry-After` wins when the server sends it (seconds),
 * and otherwise the delay doubles from 200 ms. Every other non-2xx answer is
 * a `ClickHouseError` built from the server's own body — the client never
 * invents an error message for an answer the server explained.
 *
 * The password is a `#[SensitiveParameter]` and `__debugInfo()` redacts it, so
 * a stack trace or a `var_dump()` cannot leak it. SQL bodies are logged at
 * DEBUG with their first 120 characters only: a query can carry a secret just
 * as easily as a header can.
 */
final readonly class ClickHouseHttpClient implements ClickHouseTransport
{
    private const int MAX_ATTEMPTS = 3;

    private const int BASE_DELAY_MS = 200;

    public function __construct(
        private string $baseUrl,
        #[\SensitiveParameter] private string $user,
        #[\SensitiveParameter] private string $password,
        private int $connectTimeout = 5,
        private int $timeout = 30,
    ) {
    }

    /**
     * The exact request URL for a database — public so the parameter handling
     * is asserted offline instead of against a server.
     */
    public function requestUrl(string $database = ''): string
    {
        $url = rtrim($this->baseUrl, '/') . '/';

        if ('' === $database) {
            return $url;
        }

        return $url . '?' . http_build_query(['database' => $database]);
    }

    public function execute(string $sql, string $database = ''): string
    {
        $attempt = 0;

        while (true) {
            ++$attempt;
            $response = $this->post($sql, $database);

            // 429 and the two gateway statuses are the ones a server answers
            // while it is busy or restarting; every other non-2xx is final.
            if (in_array($response['status'], [429, 502, 503], true) && $attempt < self::MAX_ATTEMPTS) {
                $delay = null !== $response['retryAfter']
                    ? $response['retryAfter'] * 1000
                    : self::BASE_DELAY_MS * (2 ** ($attempt - 1));

                error_log(sprintf('[CLICKHOUSE] retry %d/%d after HTTP %d', $attempt, self::MAX_ATTEMPTS, $response['status']));
                usleep($delay);
                continue;
            }

            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response['body'];
            }

            $error = ClickHouseError::fromResponse($response['body']);
            error_log('[CLICKHOUSE] ' . (string) $error);

            throw $error;
        }
    }

    /**
     * @return array{status: int, body: string, retryAfter: ?int}
     */
    private function post(string $sql, string $database): array
    {
        $ch = curl_init();
        $retryAfter = null;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->requestUrl($database),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $sql,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'X-ClickHouse-User: ' . $this->user,
                'X-ClickHouse-Key: ' . $this->password,
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (1 === preg_match('/^Retry-After:\s*(\d+)/i', trim($header), $matches)) {
                    $retryAfter = (int) $matches[1];
                }

                return strlen($header);
            },
        ]);

        $started = microtime(true);
        $body = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // No curl_close(): deprecated since PHP 8.5 (a no-op since 8.0) — the
        // handle is released when $ch goes out of scope.

        if (!is_string($body)) {
            $failure = ClickHouseError::fromTransport('' !== $error ? $error : 'no response from ' . $this->requestUrl($database));
            error_log('[CLICKHOUSE] ' . (string) $failure);

            throw $failure;
        }

        error_log(sprintf(
            '[CLICKHOUSE] POST %s <- %s (%d ms, %d bytes)',
            '' === $database ? '-' : $database,
            self::excerpt($sql),
            $elapsedMs,
            strlen($body),
        ));

        return ['status' => $status, 'body' => $body, 'retryAfter' => $retryAfter];
    }

    private static function excerpt(string $sql): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        return strlen($singleLine) > 120 ? substr($singleLine, 0, 120) . '…' : $singleLine;
    }

    /**
     * Prevent credential leakage on inspection.
     *
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl'        => $this->baseUrl,
            'user'           => $this->user,
            // The redaction placeholder, not a credential: it is what the
            // report prints so the real value never reaches a log line.
            // @mago-expect lint:no-literal-password
            'password'       => '***redacted***', // audit-ok: redaction placeholder
            'connectTimeout' => $this->connectTimeout,
            'timeout'        => $this->timeout,
        ];
    }
}
