<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Kernel\Contracts\RouterInterface;

/**
 * ResolvesRouteUrls — an action addresses navigation by route NAME.
 *
 * Task 2.8, measured 2026-10-17: an admin action redirects to its own index
 * with `Response::redirect('/mark/settings')`, retyping the path that the same
 * file declared three lines above as
 * `#[Route(path: '/mark/settings', name: 'mark.settings')]`. The path and the
 * name are one fact with two spellings, so moving the route or renaming the
 * name left the redirect pointing at the old path.
 *
 * The owner of that fact is the Router. The Kernel injects it (constructor
 * parameter, by type) and this trait is the action's only way to ask for a
 * URL — so a class that uses it cannot compile a path literal into a
 * redirect without noticing that it bypassed the owner.
 *
 * Deliberately NOT a static service locator: an action constructed by hand
 * (a unit test reaching for a private method) gets `null` and throws when it
 * actually tries to navigate, instead of silently resolving against a global
 * router that the test never configured.
 *
 * @internal
 */
// Its users are module slices (admin actions), and phpstan.neon deliberately
// analyses the kernel scope only. A kernel-only export therefore has no
// analysed user for this trait; the rule would report a contract as dead code.
/** @phpstan-ignore trait.unused */
trait ResolvesRouteUrls
{
    /**
     * Not promoted in the constructor: an action that already has one
     * (`MediaCloudinaryAction`'s `?CloudinaryApi`) keeps it and assigns this
     * property itself, so the trait composes with the existing signatures.
     */
    private readonly ?RouterInterface $router;

    public function __construct(?RouterInterface $router = null)
    {
        $this->router = $router;
    }

    /**
     * @param array<string, string|int> $params
     */
    protected function url(string $name, array $params = []): string
    {
        return $this->router()->url($name, $params);
    }

    /**
     * Whether a route name exists in this tree.
     *
     * A template that links to another module's admin page must not assume the
     * module is installed: `url()` throws on an unknown name. Measured
     * 2026-11-09: `Cardboard` alone answered `/mark` with 500 because its quick
     * actions resolved `articles.create` and `Blog` was absent.
     */
    protected function hasRoute(string $name): bool
    {
        $router = $this->router();

        // Name lookup lives on the kernel Router, not on the two-method
        // contract (`match`, `url`): a test double that only answers those two
        // cannot confirm a name, and hiding an action is the safe answer.
        return $router instanceof Router && $router->hasRouteName($name);
    }

    /**
     * The renderer's copy of the same seam: templates call `$url('mark.x')`.
     *
     * @return \Closure(string, array<string, string|int>): string
     */
    protected function urlBuilder(): \Closure
    {
        return fn (string $name, array $params = []): string => $this->url($name, $params);
    }

    protected function router(): RouterInterface
    {
        if (null === $this->router) {
            throw new \LogicException(sprintf(
                '%s resolves a route URL, but no Router was injected. The Kernel injects it;'
                . ' a direct caller (a test) must pass one.',
                self::class,
            ));
        }

        return $this->router;
    }
}
