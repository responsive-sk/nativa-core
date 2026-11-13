<?php

declare(strict_types = 1);

namespace Modules\Auth\Application\Services;

use Modules\Auth\Domain\Contracts\AuthProvider;
use Modules\Auth\Infrastructure\Attributes\AsAuthProvider;
use Discovery\PreferenceVote;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * AuthProviderRegistry — discovers `#[AsAuthProvider]` classes and answers the
 * composition root with ONE provider to wire.
 *
 * Discovery is a directory scan, exactly like `MiddlewareRegistry` and
 * `ShellContextProviderRegistry`: a module joins the kernel by existing, and a
 * module that is deleted takes its provider with it. The scan looks for
 * `*AuthProvider.php` in each module's `Interfaces/HTTP` directory — a file
 * whose name does not say `AuthProvider` is not one, so the scan never loads
 * the whole tree to find out.
 *
 * This registry existed once before and was deleted as dead when the `/mark`
 * gate was wired explicitly through `AuthWiring`. It comes back as a CONCEPT,
 * not as the old body: `resolveFqcn()` is the declaration-start version Boot 23
 * hardened (the deleted file's first-match-anywhere regex resolved a type named
 * `says` out of a docblock sentence — the class did not exist, the file was
 * skipped with no log line, and the provider disappeared), and
 * `PreferenceVote::select()` is the shared contention rule every other registry
 * already votes through.
 *
 * ## One provider, not a merged answer
 *
 * Two modules answering "who is this request" is not defense in depth, it is
 * two answers, and the composition root wraps `AuthMiddleware` around exactly
 * one of them. So this registry merges nothing: unlike the shell port — where
 * the site name and the default locale legitimately come from two modules —
 * there is no half of this port a second module could own. Lowest priority
 * wins; a contender at the SAME priority is logged rather than silently
 * dropped, because two modules claiming the auth surface at equal precedence is
 * a configuration a deployment must see in its log, not discover as a coin
 * flip.
 *
 * **Absence is an answer.** No provider installed means no admin surface to
 * protect: `first()` returns null, the composition root wires nothing, and
 * `/mark` answers 404 in a kernel-only tree — never an unguarded 200.
 *
 * **A provider that throws is NOT caught.** Same rule as the shell registry: a
 * missing badge is a missing badge, but an authentication answer that silently
 * disappears is a gate nobody can trust, and the failure belongs to the
 * surface, loudly.
 *
 * **Providers are instantiated at discovery, so their constructors must take
 * nothing.** A provider that grabbed a connection in its constructor would
 * capture the wrong one after the test harness swaps `DB_*`; the requirement is
 * enforced by reflection here (`getNumberOfRequiredParameters()`), and the
 * module's provider resolves its services lazily instead of holding them.
 */
final class AuthProviderRegistry
{
    /** @var list<array{int, AuthProvider}>|null */
    private static ?array $providers = null;

    /** `null` is a real answer, so the resolution is flagged separately. */
    private static bool $firstResolved = false;

    private static ?AuthProvider $first = null;

    /**
     * Every installed provider, in priority order.
     *
     * @return list<AuthProvider>
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $entry): AuthProvider => $entry[1],
            self::allWithPriority(),
        );
    }

    /**
     * Every installed provider with its priority, in priority order — the shape
     * contention logging needs (`PreferenceVote::select()`).
     *
     * @return list<array{int, AuthProvider}>
     */
    public static function allWithPriority(): array
    {
        if (null !== self::$providers) {
            return self::$providers;
        }

        $found = [];

        foreach (self::files() as $file) {
            $provider = self::load($file);

            if (null === $provider) {
                continue;
            }

            $found[] = $provider;
        }

        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        self::$providers = $found;

        return $found;
    }

    /**
     * The provider the composition root wires, or null when no module ships one
     * (see the class docblock: absence is an answer, not a failure).
     *
     * Resolved once per process, and the instance is cached — the same
     * per-request lifetime a PHP-FPM worker sees, the same way
     * `ShellContextProviderRegistry::all()` caches its providers.
     */
    public static function first(): ?AuthProvider
    {
        if (self::$firstResolved) {
            return self::$first;
        }

        $answers = [];

        foreach (self::allWithPriority() as [$priority, $provider]) {
            $answers[] = ['priority' => $priority, 'name' => $provider::class, 'value' => $provider];
        }

        $result = PreferenceVote::select($answers);
        $winner = $result['winner'];

        if (null === $winner) {
            self::$firstResolved = true;

            return null;
        }

        foreach ($result['ignored'] as $loser) {
            error_log(sprintf(
                '[auth] provider %s ignored (same priority as %s)',
                $loser['name'],
                $winner['name'],
            ));
        }

        self::$first = $winner['value'];
        self::$firstResolved = true;

        self::debug(sprintf(
            '[auth] discovered %s (priority %d)',
            $winner['name'],
            $winner['priority'],
        ));

        return self::$first;
    }

    /**
     * Reset the cached providers and the resolved winner (test isolation).
     */
    public static function reset(): void
    {
        self::$providers = null;
        self::$first = null;
        self::$firstResolved = false;
    }

    /**
     * Candidate provider files, one glob — a file whose name does not say
     * `AuthProvider` is not one, and this is what keeps the scan from loading
     * the tree.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Interfaces/HTTP/*AuthProvider.php');

        return is_array($files) ? $files : [];
    }

    /**
     * Load one candidate file: text-gate, resolve, then validate the port +
     * attribute + zero-argument constructor contract.
     *
     * The text gate runs on the FILE before anything is loaded, so a module
     * whose class name matches the pattern but carries no attribute is not
     * even required.
     *
     * @return array{int, AuthProvider}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsAuthProvider')) {
            return null;
        }

        $fqcn = self::resolveFqcn($content);

        if (null === $fqcn) {
            return null;
        }

        if (!class_exists($fqcn)) {
            require_once $file;
        }

        if (!class_exists($fqcn)) {
            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(AuthProvider::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsAuthProvider::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var AuthProvider $instance */
        $instance = $reflection->newInstance();

        return [$attribute->priority, $instance];
    }

    /**
     * Extract the FQCN from a file body: its namespace declaration and its type
     * declaration, and nothing else.
     *
     * A declaration starts a LINE (after optional whitespace and the modifiers
     * PHP allows on a type) or a STATEMENT (after a `;`), so a compact
     * `namespace Demo; class Thing {}` file still resolves — the failure mode
     * here is silence, not an error, and a heuristic that is too strict trades
     * one silent skip for another.
     *
     * What cannot be a declaration is PROSE. This used to take the FIRST mention
     * of the keyword anywhere in the file, so a docblock sentence like "This
     * class says the one thing..." resolved a type named `says`: the class did
     * not exist, the file was skipped with no log line, and the provider
     * silently disappeared — measured on the Settings shell provider, whose nav
     * menu vanished exactly that way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all five
     * registries that share this shape.
     */
    private static function resolveFqcn(string $content): ?string
    {
        $namespace = '';
        $className = '';

        if (preg_match('/(?:^|;)\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];
        }

        if (preg_match('/(?:^|;)\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $classNameMatch)) {
            $className = $classNameMatch[1];
        }

        if ('' === $namespace || '' === $className) {
            return null;
        }

        return $namespace . '\\' . $className;
    }

    /**
     * Verbose development aid, gated exactly like `AuthMiddleware::logDenial()`:
     * the interesting fact is printed when `LOG_LEVEL=debug` and costs nothing
     * otherwise.
     */
    private static function debug(string $line): void
    {
        if ('debug' !== strtolower((string) (getenv('LOG_LEVEL') ?: 'info'))) {
            return;
        }

        error_log($line);
    }
}
