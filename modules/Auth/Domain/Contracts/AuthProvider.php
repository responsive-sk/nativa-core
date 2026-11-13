<?php

declare(strict_types = 1);

namespace Modules\Auth\Domain\Contracts;

use Modules\Auth\Domain\AuthIdentity;
use Modules\Core\Infrastructure\Http\Request;

/**
 * AuthProvider — the Auth module's port, the one seam the middleware asks
 * instead of reaching into a feature module.
 *
 * Before this port, `AuthMiddleware` imported the auth service, the signed-in
 * user entity and the admin path table from `Users`, and the security-event
 * emitter from `Outbox` — six edges that made the shared middleware
 * unloadable without two feature modules it does not own. Now the middleware
 * asks this port and nothing else:
 *
 * - WHO the request is (`identity()`, mapped to this module's value object by
 *   whoever owns the session; session rows, remember-me cookies and their
 *   renewal stay the module's business entirely);
 * - WHICH paths are protected and what they require (`isProtectedPath()`,
 *   `requiredPermission()` — the permission vocabulary belongs with the roles
 *   that carry it, so the table lives in the module and only the answers
 *   cross);
 * - WHICH paths are guest-only (`guestOnlyPaths()` — the login/register
 *   literals live here as the provider's answer, not as kernel knowledge);
 * - WHAT happened (`noteEvent()` — the provider forwards to the outbox when
 *   one is installed and drops the event when none is, so "without the outbox
 *   the event is dropped, not the request" holds without Core naming Outbox).
 *
 * **Absence removes the middleware, not the request.** No provider installed
 * means no admin surface to protect (`/mark` is 404, never 403), so the
 * composition root wires `AuthMiddleware` only when a provider exists — the
 * same absence-is-an-answer rule as `ShellContextProvider`.
 *
 * Implementations are discovered by attribute (`#[AsAuthProvider]`) the same
 * way middleware is, so a module joins by existing rather than by being
 * listed where the kernel would have to keep it.
 */
interface AuthProvider
{
    /**
     * Who this request authenticated as, or null for anonymous.
     *
     * The provider tries everything it owns (session, remember-me cookie)
     * and maps the result to the kernel shape. Null means "nobody" — the
     * middleware redirects protected paths to the login, it never loads a
     * missing class to find out more.
     */
    public function identity(Request $request): ?AuthIdentity;

    /**
     * Whether this path is an admin path the middleware must protect.
     */
    public function isProtectedPath(string $path): bool;

    /**
     * The permission this admin path requires, or null when no policy row
     * covers it (the middleware refuses uncovered paths rather than allowing
     * them — a route nobody classified costs access, never leaks it).
     */
    public function requiredPermission(string $path): ?string;

    /**
     * Paths an authenticated identity must not see (login, register, …),
     * answered by the module that owns those pages.
     *
     * @return list<string>
     */
    public function guestOnlyPaths(): array;

    /**
     * Note a security fact (`authz.denied`, …) with context.
     *
     * @param array<string, mixed> $context
     */
    public function noteEvent(string $event, array $context): void;
}
