<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * CurlTransport — the one place this module opens a socket.
 *
 * ## Three limits, each answering a measured failure
 *
 * - **No redirects.** Following one would let a public endpoint answer `302
 *   Location: http://169.254.169.254/` and curl would fetch it without ever
 *   asking `SsrfGuard`, because the guard checks the URL the operator wrote.
 *   A resource that redirects is misconfigured, and the panel reports the
 *   3xx instead of chasing it.
 * - **A body cap (2 MiB by default).** The answer lands in memory to be mapped
 *   and rendered, so an endpoint that streams a gigabyte is a denied service,
 *   not a big page. The write callback stops at the limit and the request
 *   fails with a message naming the limit — never an OOM.
 * - **Timeouts.** A remote API that hangs must not hold a panel request open;
 *   connect and total timeout are separate because a slow DNS answer and a
 *   slow body are different problems.
 *
 * `CURLOPT_PROTOCOLS` is narrowed to HTTP(S) as defence in depth: the guard
 * has already refused `file://` and friends, and the transport refuses to
 * speak them even if a future caller skips the guard.
 *
 * ## What is not here
 *
 * No logging. The URL may carry a query an API uses for its key, and the
 * request headers carry the token this module exists to keep out of logs. A
 * transport that logged its request would undo plan D5 in one line.
 *
 * No retries: a write that timed out may have been applied, and retrying a
 * POST is how a form creates two records. Read calls can retry at the caller
 * once the mapping layer knows which methods are safe.
 */
final readonly class CurlTransport implements HttpTransportInterface
{
    private const int CONNECT_TIMEOUT = 5;

    private const int TIMEOUT = 10;

    /** 2 MiB: a list page with a readable body, not a download. */
    private const int MAX_BODY_BYTES = 2_097_152;

    private SsrfGuard $guard;

    public function __construct(
        ?SsrfGuard $guard = null,
        private int $connectTimeout = self::CONNECT_TIMEOUT,
        private int $timeout = self::TIMEOUT,
        private int $maxBodyBytes = self::MAX_BODY_BYTES,
    ) {
        // A default argument cannot call a factory, and the production guard
        // reads the operator's allowlist from the environment.
        $this->guard = $guard ?? SsrfGuard::fromEnvironment();
    }

    public function send(HttpRequestSpec $request): HttpResponse
    {
        $this->guard->assertAllowed($request->url);

        $headers = [];

        if (!isset($request->headers['accept'])) {
            $headers[] = 'Accept: application/json';
        }

        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        $limit = $this->maxBodyBytes;

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $request->url,
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $colon = strpos($header, ':');

                if (false !== $colon) {
                    $name = strtolower(trim(substr($header, 0, $colon)));
                    $value = trim(substr($header, $colon + 1));
                    $responseHeaders[$name] = isset($responseHeaders[$name])
                        ? $responseHeaders[$name] . ', ' . $value
                        : $value;
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;

                    // Aborts the transfer; the flag says why, so the caller
                    // gets a sentence rather than curl's generic write error.
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if (null !== $request->body) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        if ('HEAD' === $request->method) {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        $sent = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);
        // No curl_close(): deprecated since PHP 8.5 (a no-op since 8.0) — the
        // handle is released when $handle goes out of scope.

        if ($tooLarge) {
            throw new HttpTransportException(sprintf(
                '%s %s answered more than %d bytes. The answer is not read past that limit.',
                $request->method,
                self::target($request->url),
                $this->maxBodyBytes,
            ));
        }

        if (false === $sent || 0 !== $errno) {
            throw new HttpTransportException(sprintf(
                '%s %s could not be sent: %s',
                $request->method,
                self::target($request->url),
                '' !== $error ? $error : 'curl reported no response',
            ));
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }

    /**
     * Scheme, host, port and path — never the query.
     *
     * An error message is read by a human and may land in a log, and some APIs
     * accept their key as a query parameter. The path is enough to know which
     * endpoint failed.
     */
    private static function target(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '(unparseable url)';
        }

        $host = strtolower($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme'] ?? 'http') . '://' . $host . $port . ($parts['path'] ?? '/');
    }
}
