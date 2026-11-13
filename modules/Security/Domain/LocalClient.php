<?php

declare(strict_types = 1);

namespace Modules\Security\Domain;

/**
 * LocalClient — is this request coming from the machine the app runs on?
 *
 * The tree exempts the local developer from its own rate limits: a person
 * clicking through a form on `localhost` is not the abuse these limits exist
 * for, and a limiter that locks a developer out of their own checkout is a
 * limiter that gets disabled. The rule was spelled in three places —
 * `CommentsService::LOOPBACK_IPS`, `RateLimitMiddleware`'s own const, and (new,
 * with the audit's throttles) the Credentials paths — which is three lists that
 * can disagree about what "local" means. The module limiters ask here now; the
 * Core middleware keeps its private copy, because the coupling ledger forbids a
 * Core file naming a module (that constraint is worth more than one shared
 * constant).
 *
 * ## Why this is safe in production
 *
 * The address compared here is `Request::getClientIp()`, which returns the
 * direct socket peer (`REMOTE_ADDR`) unless that peer is listed in
 * `TRUSTED_PROXIES` — in that case the forwarded chain is walked from the
 * RIGHTMOST hop, so a client-supplied leftmost entry cannot impersonate the
 * proxy's own view. A remote client therefore cannot present itself as
 * `127.0.0.1` through a correctly configured proxy.
 *
 * The one deployment where that reasoning lapses is worth stating: a reverse
 * proxy on the SAME host that is not listed in `TRUSTED_PROXIES` and does not
 * set `X-Forwarded-For` makes every request arrive from `127.0.0.1`, and this
 * exemption then applies production-wide. The answer is configuration, not
 * code — `.env.example` says it next to `TRUSTED_PROXIES` — and it is written
 * down here because the exemption is what makes it interesting.
 *
 * `@internal`
 */
final class LocalClient
{
    /** @var list<string> The addresses a same-host request can arrive from. */
    private const array ADDRESSES = ['127.0.0.1', '::1', 'localhost'];

    public static function isLoopback(string $ip): bool
    {
        return in_array($ip, self::ADDRESSES, true);
    }
}
