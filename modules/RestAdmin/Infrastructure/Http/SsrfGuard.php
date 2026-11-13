<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * SsrfGuard — the check a URL passes before a socket is opened.
 *
 * REST admin is told by an operator where the remote API is, and it then makes
 * the server fetch that address. That is the shape of server-side request
 * forgery: a service registered with `http://169.254.169.254/` would make the
 * panel read cloud metadata and show it in a table. So the scheme, the
 * credentials and every resolved address are checked, not just the spelling.
 *
 * ## What is refused, and why each one
 *
 * - **Anything but http/https.** `file://` reads the local disk, `gopher://`
 *   speaks arbitrary bytes to arbitrary ports, `dict://` and `ftp://` are the
 *   other classic pivots. There is no reason for a REST resource to use them.
 * - **Credentials in the URL.** `https://user:pass@host/` puts a secret in
 *   every log line and every error message. A service's token is read from an
 *   env variable and sent as a header (plan D5).
 * - **Loopback, link-local, private, CGNAT, multicast, reserved and
 *   documentation ranges**, for IPv4 and IPv6, including IPv4-mapped IPv6
 *   (`::ffff:127.0.0.1`) and IPv4-compatible (`::127.0.0.1`) spellings.
 *   `169.254.169.254` is the one that matters most, and it is link-local.
 * - **A hostname that resolves to such an address.** The written host can be
 *   public while its A record is not, and a host that does not resolve at all
 *   is refused rather than handed to curl to try again.
 *
 * ## The allowlist is the operator's exception, and it is deliberate
 *
 * A local or internal API is a real use case — the panel's own smoke test
 * calls `127.0.0.1`. `RESTADMIN_ALLOWED_HOSTS` (comma-separated) names hosts
 * the operator has decided to trust; those skip the address check but still
 * cannot use a non-http scheme or carry credentials. The list is an explicit
 * act of configuration, which is the point: a private address is callable
 * because someone wrote it down, not because the guard missed it.
 *
 * ## What this does not cover
 *
 * DNS is resolved here and again by curl, so a record that changes between the
 * two is a window this guard cannot close without pinning the address into the
 * connection. IPv4 tunnelled inside 6to4/Teredo is not unwrapped for the same
 * class of reason: reaching a private address through it needs a relay the
 * caller does not control. Both are stated here rather than assumed away.
 */
final readonly class SsrfGuard
{
    /**
     * Ranges no remote service may live in. Documentation ranges are included
     * because they are never routable, so a request there is always a mistake;
     * `0.0.0.0/8` covers "this host" spellings, and `240.0.0.0/4` plus
     * `255.255.255.255/32` cover reserved and broadcast addresses.
     *
     * @var list<string>
     */
    private const array BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts hosts the operator explicitly trusts
     */
    public function __construct(array $allowedHosts = [])
    {
        $normalised = [];

        foreach ($allowedHosts as $host) {
            $host = strtolower(trim($host));

            if ('' !== $host) {
                $normalised[] = $host;
            }
        }

        $this->allowedHosts = $normalised;
    }

    /**
     * The operator's list from `RESTADMIN_ALLOWED_HOSTS`, or an empty list.
     *
     * A factory rather than a constructor default because a default argument
     * may not read the environment, and because a test that points at
     * `127.0.0.1` should say so with an explicit array rather than by setting
     * a process-wide variable.
     */
    public static function fromEnvironment(): self
    {
        $raw = getenv('RESTADMIN_ALLOWED_HOSTS');

        if (!is_string($raw) || '' === trim($raw)) {
            return new self();
        }

        return new self(explode(',', $raw));
    }

    /**
     * @throws HttpTransportException when the URL may not be called
     */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new HttpTransportException('The request URL could not be parsed, so it cannot be checked.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new HttpTransportException(sprintf(
                'REST admin only calls http and https URLs; "%s" is refused.',
                '' === $scheme ? '(no scheme)' : $scheme,
            ));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpTransportException(sprintf(
                'The URL for "%s" carries credentials. A token belongs in its env variable and travels as a header, never in a URL.',
                strtolower($parts['host'] ?? 'unknown host'),
            ));
        }

        $host = strtolower($parts['host'] ?? '');

        if ('' === $host) {
            throw new HttpTransportException('The request URL has no host, so there is nothing to check.');
        }

        // parse_url keeps the brackets of an IPv6 literal; the address parser
        // does not want them.
        $host = trim($host, '[]');

        if (in_array($host, $this->allowedHosts, true)) {
            return;
        }

        foreach ($this->addressesOf($host) as $address) {
            if ($this->isBlocked($address)) {
                throw new HttpTransportException(sprintf(
                    '"%s" resolves to %s, which is not a public address. Add the host to RESTADMIN_ALLOWED_HOSTS to call it on purpose.',
                    $host,
                    $address,
                ));
            }
        }
    }

    /**
     * Every address a host stands for. A literal is itself; a name is looked
     * up in both families, and a name that yields nothing is refused.
     *
     * @return list<string>
     */
    private function addressesOf(string $host): array
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = gethostbynamel($host);

        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = $address;
            }
        }

        $ipv6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));

        if ([] === $addresses) {
            throw new HttpTransportException(sprintf(
                '"%s" does not resolve to an address this transport may call.',
                $host,
            ));
        }

        return $addresses;
    }

    private function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);

        if (false === $packed) {
            // An address this code cannot read is an address it will not
            // vouch for.
            return true;
        }

        if (16 === strlen($packed)) {
            $mapped = substr($packed, 0, 12);

            // ::ffff:a.b.c.d and the deprecated ::a.b.c.d both carry an IPv4
            // address in their last four bytes; checking only the v6 ranges
            // would let 127.0.0.1 through as "not in ::1".
            if ("\0\0\0\0\0\0\0\0\0\0\xff\xff" === $mapped || str_repeat("\0", 12) === $mapped) {
                $embedded = substr($packed, 12);

                if ("\0\0\0\0" !== $embedded) {
                    return $this->isBlocked((string) inet_ntop($embedded));
                }
            }
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);

        $packed = @inet_pton($address);
        $base = @inet_pton($network);

        if (false === $packed || false === $base || strlen($packed) !== strlen($base)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);

        if ($whole > 0 && substr($packed, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }

        $remainder = $bits % 8;

        if (0 === $remainder) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($base[$whole]) & $mask);
    }
}
