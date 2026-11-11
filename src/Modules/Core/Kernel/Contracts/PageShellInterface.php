<?php

declare(strict_types = 1);

namespace Modules\Core\Kernel\Contracts;

use Modules\Core\Infrastructure\Http\Request;

/**
 * PageShellInterface — the kernel's page shell port.
 *
 * The kernel answers `/` and every error page in a tree with nothing installed,
 * so it owns the QUESTIONS: which site name, which locale, which copy, which
 * status. It does not own the markup. A layout module implements this port and
 * renders those answers in its own chrome; `PlainPageShell` is the
 * dependency-free answer the kernel keeps for a tree without one.
 *
 * This is the seam that lets the shell live in a module: a kernel file names
 * the port, never the layout module.
 */
interface PageShellInterface
{
    /**
     * Render the home surface.
     *
     * The router is the answer to "which of the hero's links exist": a home
     * surface may advertise `/blog` or the admin panel only when a route
     * serves it. It is optional because the port is also rendered directly by
     * tests and by `PlainPageShell`, neither of which needs the route table;
     * a shell that has no router renders no such link, which is the safe
     * answer (a missing button, never a 404).
     *
     * @param array<string, string> $copy the port's `home` group, without the
     *                                    kernel's own `title` answer
     */
    public function home(
        Request $request,
        string $siteName,
        string $locale,
        array $copy,
        ?RouterInterface $router = null,
    ): string;

    /**
     * Render an error page.
     *
     * @param string|null $path request path for theme resolution
     * @param string|null $lang explicit content language
     */
    public function error(int $status, string $title, string $detail, ?string $path = null, ?string $lang = null): string;
}
