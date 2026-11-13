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

        $handle = curl_init();

        if (false === $handle) {
            throw new RuntimeException('Could not initialise the registry HTTP client.');
        }

        $body = '';
        $tooLarge = false;

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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

        curl_close($handle);

        if ($tooLarge) {
            throw new RuntimeException(sprintf('Registry response exceeds %d bytes: %s', $limit, $url));
        }

        if (false === $sent) {
            throw new RuntimeException(sprintf('Registry request failed: %s (%s)', $url, $error));
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Registry request returned HTTP %d: %s', $status, $url));
        }

        return $body;
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
