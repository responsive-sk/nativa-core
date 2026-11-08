<?php

declare(strict_types = 1);

namespace Modules\Core\Domain\Contracts;

/**
 * SecurityEventSink — the kernel's port for the two security facts it raises.
 *
 * The middleware that decides them is kernel-owned and must load in a tree with
 * no module on disk, but the RECORD of a refusal is not: it is an outbox message
 * some module owns, routed to a handler another module owns. Before this port
 * both middlewares named `Modules\Outbox\…` inline behind `class_exists()`, which
 * kept the `use`-line ledger empty and left two real dependencies in the kernel:
 * `mago guard` reported 8 of them — every `::class` in a `class_exists()` check
 * and every static call — and it was right to. The `class_exists` guard protects
 * the CALL, not the FACT that the kernel cannot say what a security event is
 * without naming a module.
 *
 * What crosses this boundary is primitives, like `RateLimiter`: the kernel knows
 * which fact happened and the values that describe it, the module owns the event
 * type string and the payload vocabulary. The module-side implementation maps a
 * method to `SecurityEvents::AUTHZ_DENIED` / `RATELIMIT_TRIPPED` and to the keys
 * `payloadKeys()` declares — one owner for both halves, which is what
 * `SecurityEventPayloadContractTest` reads.
 *
 * Implementations are module code, wired by the composition root only when the
 * module exists. A null sink is the kernel-only answer: the decision stands, the
 * record is dropped (the middleware's own rule — absence of the record never
 * changes the response).
 *
 * @internal
 *
 * @coversNothing
 */
interface SecurityEventSink
{
    /**
     * A request was refused because the acting identity was not allowed.
     *
     * `$required` is the missing permission, or `no-policy` when no policy row
     * covers the path at all — the two have opposite fixes, so the kernel
     * passes which one instead of letting the recorder guess.
     *
     * `$roles` is a list because the kernel identity carries permissions rather
     * than role names; it is empty today and shaped for the day it is not.
     *
     * @param list<string> $roles
     */
    public function authorizationDenied(
        string $path,
        string $required,
        string $userId,
        array $roles,
        string $ip,
    ): void;

    /**
     * A caller was refused for exceeding the request limit.
     *
     * `$key` is the limiter key that tripped (per client IP today), `$scope`
     * the path that was counted — the two an investigation starts from.
     */
    public function rateLimitTripped(string $key, string $scope, string $ip): void;
}
