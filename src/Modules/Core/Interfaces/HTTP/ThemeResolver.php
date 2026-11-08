<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP;

use Modules\Core\Application\Services\ShellContextProviderRegistry;
use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Domain\ThemePreference;
use Modules\Core\Infrastructure\Http\Request;

/**
 * ThemeResolver — resolves the active theme for the request (ADR-0002).
 *
 * The answer now comes through the kernel-owned `ShellContextProvider` port
 * instead of `SettingsServices`: which theme is active is the site's business,
 * and a kernel that cannot answer "which theme" without a module installed is
 * a kernel that only works when the module is there. With no provider the
 * answer is the kernel's own theme, which is a theme the tree actually has.
 *
 * The whitelist stays a whitelist. A provider names a theme; the resolver
 * decides whether this tree can render it. In a kernel-only tree that set is
 * exactly `kernel` — a module that ships a theme extends the set when it is
 * installed, and until then a name the tree does not have resolves to the
 * kernel theme rather than to a class that is not installed.
 *
 * `ADMIN_THEME` survives as the admin bundle name (`AssetManager` derives its
 * bundle list from it). The admin surface itself belongs to a module: this tree
 * serves no `/mark` route, so the constant is a name, not a promise.
 */
final class ThemeResolver
{
    /** The kernel's own theme — the answer when nothing else names one. */
    public const string KERNEL_THEME = 'kernel';

    /** The theme a request renders with by default. */
    public const string PUBLIC_THEME = self::KERNEL_THEME;

    /** Admin bundle name (the admin surface ships with a module). */
    public const string ADMIN_THEME = 'moonshine';

    /** Settings key holding the active public theme, read through the port. */
    public const string THEME_SETTING = 'theme';

    /**
     * Public themes this tree can render without asking anyone. The kernel
     * ships one; a module-carried theme extends the set through
     * `ThemeRegistry` (discovered from the filesystem, not added here).
     *
     * @var list<string>
     */
    public const array PUBLIC_WHITELIST = [self::KERNEL_THEME];

    /**
     * Resolve the active theme for the request.
     */
    public function resolve(Request $request): string
    {
        return self::resolvePath($request->getPathInfo());
    }

    /**
     * Path-only half of resolve(): the error renderer names a theme from a
     * bare path (no Request to build), and anything that resolves must
     * resolve the same way whether a request object exists or not.
     */
    public static function resolvePath(string $path): string
    {
        if (AdminRoutePolicy::isAdminPath($path)) {
            return self::ADMIN_THEME;
        }

        return ThemePreference::resolve(
            ShellContextProviderRegistry::theme(),
            [...self::PUBLIC_WHITELIST, ...array_keys(ThemeRegistry::all())],
            self::KERNEL_THEME,
        );
    }
}
