<?php

declare(strict_types = 1);

namespace Modules\Outbox\Application\Services;

use Modules\Core\Domain\Contracts\SecurityEventSink;
use Modules\Outbox\Domain\SecurityEvents;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;

/**
 * OutboxSecurityEventSink — the Outbox module's implementation of the kernel's
 * security event port.
 *
 * This file is where the event TYPE and the payload VOCABULARY meet: the kernel
 * says which fact happened, this module decides that it is
 * `SecurityEvents::AUTHZ_DENIED` and which keys it carries. Both halves stay in
 * the module that owns them, next to `payloadKeys()` — the kernel names neither.
 *
 * Before the port the two middlewares reached back into this module through
 * inline FQCNs behind `class_exists()`. That kept the `use`-line ledger empty
 * and still left 8 dependencies `mago guard` counted. Inverting the edge moves
 * them here, where a module depending on the kernel's contract is the normal
 * direction (see `RateLimiter`), and the composition root decides whether an
 * instance exists at all.
 *
 * Recording never throws: `OutboxServices::securityEvent()` catches its own
 * failures, logs, and returns false — a message that cannot be stored must not
 * turn a 403 or a 429 into a 500.
 *
 * @internal
 *
 * @coversNothing
 */
final class OutboxSecurityEventSink implements SecurityEventSink
{
    /**
     * @param list<string> $roles
     */
    public function authorizationDenied(
        string $path,
        string $required,
        string $userId,
        array $roles,
        string $ip,
    ): void {
        OutboxServices::securityEvent(
            SecurityEvents::AUTHZ_DENIED,
            [
                'path'     => $path,
                'required' => $required,
                'userId'   => $userId,
                'roles'    => $roles,
                'ip'       => $ip,
            ],
        );
    }

    public function rateLimitTripped(string $key, string $scope, string $ip): void
    {
        OutboxServices::securityEvent(
            SecurityEvents::RATELIMIT_TRIPPED,
            ['key' => $key, 'scope' => $scope, 'ip' => $ip],
        );
    }
}
