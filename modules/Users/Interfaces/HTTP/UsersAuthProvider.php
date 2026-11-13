<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP;

use Modules\Auth\Domain\AuthIdentity;
use Modules\Auth\Domain\Contracts\AuthProvider;
use Modules\Auth\Infrastructure\Attributes\AsAuthProvider;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\Permissions;

/**
 * UsersAuthProvider — the `AuthProvider` the kernel's auth port has been
 * waiting for, in the module that owns sessions, roles and the `/mark` policy
 * table.
 *
 * `AuthMiddleware` (Auth) decides; this class answers. It exists because the
 * port was shipped without an implementation: measured 2026-10-15, `/mark`
 * served the whole admin dashboard — user table, e-mails, delete forms — to a
 * request with no cookies, because nothing in the tree constructed the
 * middleware and no class here implemented the port. The middleware's own
 * docblock promised "the composition root wires `AuthMiddleware` only when a
 * provider exists"; this is the provider it was waiting for.
 *
 * Three decisions worth reading before changing anything:
 *
 * - **The `admin` role is expanded into permissions, here, once.**
 *   `User::hasPermission()` short-circuits on the `admin` role, but
 *   `AuthIdentity::hasPermission()` is a plain `in_array` — mapping
 *   `permissions()` across as-is would 403 the very administrator the policy
 *   exists to admit, and the failure would look like a policy bug rather than
 *   a mapping one. The bypass moves into the DATA the middleware reads, so the
 *   gate stays a dumb membership check and every denial is explainable from
 *   the identity alone.
 * - **`/mark` is not spelled here.** `AdminRoutePolicy` owns "what is admin"
 *   and "what it costs"; repeating the prefix in this class is how the two
 *   drift apart. The provider delegates both questions.
 * - **The Outbox edge is a string, not an import.** `noteEvent()` names
 *   `Modules\Outbox` inline (behind `class_exists`) rather than through a
 *   `use` statement, for the same reason `RateLimitMiddleware` does: the
 *   module closure scanner reads `use Modules\<X>` lines, so an import would
 *   make `module:add Users` REFUSE on a tree without Outbox installed. The
 *   event is dropped when nobody is listening; the dependency is not created.
 *   (Adding the `use` back is an install-time regression, not a style gain.)
 *
 * **It lives in `Interfaces/HTTP`, and that is load-bearing.**
 * `AuthProviderRegistry` discovers providers by the file name it publishes —
 * a `*AuthProvider.php` inside a module's `Interfaces/HTTP` — so a provider
 * that sits anywhere else is invisible to discovery: it has to be named for
 * the surface it answers for. The move also inverts a layer smell: this class
 * asks `AuthServices::auth()` on every request, so as a `Domain` class it
 * reached UPWARD into `Interfaces\HTTP` for its own wiring. `AdminRoutePolicy`
 * and `Permissions` stay where they are — they answer policy questions for the
 * module, not for the surface.
 *
 * **Zero constructor arguments, and now that is enforced.** Discovery
 * instantiates providers while it scans, so a provider whose constructor took
 * a dependency would capture an instance from the wrong moment — the test
 * harness swaps `DB_*` env between cases, and a connection captured at boot
 * would point at the previous database. `AuthProviderRegistry` refuses a
 * provider with required constructor parameters rather than passing one, and
 * this class takes its services lazily instead (`AuthServices::auth()`).
 *
 * @internal
 *
 * @coversNothing
 */
#[AsAuthProvider]
final readonly class UsersAuthProvider implements AuthProvider
{
    /** Paths an authenticated identity must not see — the module's own pages. */
    private const array GUEST_ONLY = ['/login', '/register', '/forgot-password', '/reset-password'];

    /**
     * Who this request is, from the session the module already owns.
     *
     * Null means anonymous, which is the common case and never an error: the
     * middleware turns it into a redirect to `/login`, not into a failure.
     */
    public function identity(Request $request): ?AuthIdentity
    {
        $user = AuthServices::auth()->currentUser();

        if (null === $user) {
            $this->debug('[auth] identity none');

            return null;
        }

        $permissions = $user->permissions();

        // The role bypass, expanded into the data (see the class docblock).
        if ($user->hasRole('admin')) {
            $permissions = Permissions::all();
        }

        $identity = new AuthIdentity(
            $user->id(),
            $user->isActive(),
            $permissions,
        );

        $this->debug(sprintf(
            '[auth] identity id=%s active=%d roles=%s perms=%d',
            $identity->id,
            $identity->active ? 1 : 0,
            implode(',', $user->roles()),
            \count($identity->permissions),
        ));

        return $identity;
    }

    /**
     * Admin area = the policy's own answer, never a literal copied here.
     */
    public function isProtectedPath(string $path): bool
    {
        return AdminRoutePolicy::isAdminPath($path);
    }

    /**
     * The permission this path costs, or null when no row classifies it —
     * and null is DENY in `AuthMiddleware`, so an unmapped `/mark/whatever`
     * fails closed rather than leaking.
     */
    public function requiredPermission(string $path): ?string
    {
        return AdminRoutePolicy::requiredPermission($path);
    }

    /**
     * The auth pages themselves: a signed-in administrator has no business
     * looking at the login form.
     *
     * @return list<string>
     */
    public function guestOnlyPaths(): array
    {
        return self::GUEST_ONLY;
    }

    /**
     * A security fact, forwarded to the Outbox when one is installed and
     * dropped when none is — never a thrown request.
     *
     * @param array<string, mixed> $context
     */
    public function noteEvent(string $event, array $context): void
    {
        // Inline FQCN on purpose: no `use Modules\Outbox\…` import exists to
        // enter Users' install closure (see the class docblock).
        if (!class_exists(\Modules\Outbox\Interfaces\HTTP\OutboxServices::class)
            || !class_exists(\Modules\Outbox\Domain\SecurityEvents::class)
        ) {
            $this->debug(sprintf('[auth] event=%s dropped (no outbox)', $event));

            return;
        }

        // A name outside the outbox's vocabulary is refused HERE rather than
        // written — `SecurityEvents::isSecurityEvent()`'s stated purpose, and
        // this is its caller. Measured 2026-10-16: `authz.denied` went through
        // this method for every refused admin path, and because the drain
        // dispatches by type while `SecurityEvents::all()` holds
        // `security.authz.denied`, each one became a message the processor failed
        // with `No handler registered for event type` — retried five times, then
        // parked, with no audit row. Refused loudly rather than parked quietly:
        // a fact that cannot be recorded must not look like one that was.
        if (!\Modules\Outbox\Domain\SecurityEvents::isSecurityEvent($event)) {
            error_log(sprintf('[auth] event=%s refused (not a security event type)', $event));

            return;
        }

        // The keys a handler reads are declared in one place; a context that
        // invents another is a fact the audit viewer would silently drop.
        $unknown = array_diff(
            array_keys($context),
            \Modules\Outbox\Domain\SecurityEvents::payloadKeys($event),
        );

        if ([] !== $unknown) {
            error_log(sprintf(
                '[auth] event=%s carries keys no handler reads: %s',
                $event,
                implode(', ', array_values($unknown)),
            ));
        }

        \Modules\Outbox\Interfaces\HTTP\OutboxServices::securityEvent($event, $context);

        $this->debug(sprintf('[auth] event=%s forwarded', $event));
    }

    /**
     * Verbose development aid, gated exactly like
     * `AuthMiddleware::logDenial()`: the interesting facts are printed when
     * LOG_LEVEL=debug and cost nothing otherwise.
     */
    private function debug(string $line): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log($line);
    }
}
