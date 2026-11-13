<?php

declare(strict_types = 1);

namespace Modules\Tooling;

use RuntimeException;

/**
 * CurlRegistryTransport — the only code that talks to a registry host.
 *
 * HTTPS is required; plain HTTP is allowed only for loopback hosts so a local
 * registry can be tested without weakening a production deployment. Redirects
 * are followed (release hosts redirect to object storage) but are capped and
 * restricted to HTTP(S), and the write callback stops at the caller's limit
 * instead of buffering an unbounded response.
 */
final readonly class CurlRegistryTransport implements RegistryTransport
{
    public function __construct(
        private int $connectTimeout = 5,
        private int $timeout = 20,
    ) {
    }

    public function get(string $url, int $limit): string
    {
        if ('' === $url || !self::isAllowedUrl($url)) {
            throw new RuntimeException(sprintf(
                'Registry URL must use HTTPS (HTTP is allowed only for localhost): %s',
                $url,
            ));
        }

        $current = $url;

        for ($redirects = 0; ; ++$redirects) {
            if (!self::isAllowedUrl($current)) {
                throw new RuntimeException(sprintf(
                    'Registry URL must use HTTPS (HTTP is allowed only for localhost): %s',
                    $current,
                ));
            }

            $handle = curl_init();

            if (false === $handle) {
                throw new RuntimeException('Could not initialise the registry HTTP client.');
            }

            $body = '';
            $tooLarge = false;

            $options = [
                CURLOPT_URL            => $current,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                // Redirects are followed by this loop, one hop at a time, so
                // every Location is checked by isAllowedUrl() instead of
                // allowing cURL to downgrade HTTPS or leave localhost.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => 'nativa-module-registry/1',
                CURLOPT_HTTPHEADER     => ['Accept: */*'],
                CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body, &$tooLarge, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $tooLarge = true;

                        return 0;
                    }

                    $body .= $chunk;

                    return strlen($chunk);
                },
            ];

            curl_setopt_array($handle, $options);

            $sent = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $redirect = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);

            curl_close($handle);

            if ($tooLarge) {
                throw new RuntimeException(sprintf('Registry response exceeds %d bytes: %s', $limit, $current));
            }

            if (false === $sent) {
                throw new RuntimeException(sprintf('Registry request failed: %s (%s)', $current, $error));
            }

            if ($status >= 300 && $status < 400) {
                if ($redirects >= 3) {
                    throw new RuntimeException(sprintf('Registry redirected too many times: %s', $url));
                }

                if ('' === $redirect) {
                    throw new RuntimeException(sprintf('Registry redirect has no location: %s', $current));
                }

                $current = $redirect;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('Registry request returned HTTP %d: %s', $status, $current));
            }

            return $body;
        }
    }

    private static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ('https' === $scheme) {
            return true;
        }

        return 'http' === $scheme && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
