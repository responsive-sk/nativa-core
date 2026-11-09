<?php

declare(strict_types = 1);

namespace Shell;

use Discovery\PreferenceVote;
use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * ShellContextProviderRegistry — discovers `#[AsShellContextProvider]` classes
 * and answers the shell's questions with ONE merge rule instead of a list the
 * caller has to interpret.
 *
 * Discovery is a directory scan, exactly like `MiddlewareRegistry`: a module
 * joins the kernel by existing, and a module that is deleted takes its provider
 * with it. The scan looks for `*ShellContextProvider.php` in each module's
 * `Interfaces/HTTP` directory — a provider is an HTTP-surface concern, and the
 * filename says what the file is, so the scan does not have to load every class
 * in the tree to find out. (The pattern is spelled once, in `files()`, and not
 * here: a `star-slash` pair written inside a docblock closes the comment.)
 *
 * ## The merge rule
 *
 * Two modules legitimately answer different halves of this port: the module that
 * owns site settings answers the name, the theme and the menu; the module that
 * owns locales answers the default locale, the language list and `<html lang>`.
 * So the answers merge, and each one merges the only way it can mean anything:
 *
 * | Question | Rule |
 * |---|---|
 * | `siteName`, `theme`, `defaultLocale`, `activeLocale`, `htmlLang` | first non-null wins (priority, then discovery order) |
 * | `languages` | union, in provider order, de-duplicated |
 * | `copy` | union PER KEY — the first provider that holds a key wins it |
 * | `navigation` | first provider that returns a non-empty list for that slot |
 *
 * A tie at the winning priority keeps the first answer and LOGS the rest
 * (`PreferenceVote::select()` + `error_log`): two modules answering the same
 * question is a configuration worth seeing, not a silent coin flip.
 *
 * Navigation is the one that cannot merge: a header built from two menus is not
 * a menu, it is two menus glued together, and no provider could have known the
 * order of the other's items. First non-empty wins, so a module that owns
 * navigation owns all of it.
 *
 * A provider that throws is NOT caught. A missing badge is a missing badge; a
 * navigation menu that silently disappears is a site that looks broken to
 * whoever deployed it and fine to the log. The failure belongs to the surface.
 */
final class ShellContextProviderRegistry
{
    /** @var list<ShellContextProvider>|null */
    private static ?array $providers = null;

    /**
     * Every installed provider, in priority order.
     *
     * @return list<ShellContextProvider>
     */
    public static function all(): array
    {
        if (null !== self::$providers) {
            return self::$providers;
        }

        self::$providers = array_map(
            static fn (array $entry): ShellContextProvider => $entry[1],
            self::allWithPriority(),
        );

        return self::$providers;
    }

    /**
     * Every installed provider with its priority, in priority order — the
     * shape contention logging needs (`PreferenceVote::select()`).
     *
     * @return list<array{int, ShellContextProvider}>
     */
    public static function allWithPriority(): array
    {
        $found = [];

        foreach (self::files() as $file) {
            $provider = self::load($file);

            if (null === $provider) {
                continue;
            }

            $found[] = $provider;
        }

        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
    }

    /** Reset the cached providers (test isolation). */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /** First provider that names the site, or null when no module does. */
    public static function siteName(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->siteName(), 'siteName');
    }

    /** First provider that names a theme, or null — which means "use the kernel's". */
    public static function theme(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->theme(), 'theme');
    }

    /** First provider that names a default locale, or null when unset. */
    public static function defaultLocale(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->defaultLocale(), 'defaultLocale');
    }

    /**
     * First provider that names the locale RESOLVED FOR THIS REQUEST, or null.
     *
     * Null is not an error: a tree without a locale module renders with the
     * kernel fallback. The caller decides what that fallback is.
     */
    public static function activeLocale(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->activeLocale(), 'activeLocale');
    }

    /** First provider that names a `<html lang>`, or null to follow the locale. */
    public static function htmlLang(): ?string
    {
        return self::firstString(static fn (ShellContextProvider $p): ?string => $p->htmlLang(), 'htmlLang');
    }

    /**
     * The union of every provider's languages, in provider order.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        $languages = [];

        foreach (self::all() as $provider) {
            foreach ($provider->languages() as $language) {
                if (!in_array($language, $languages, true)) {
                    $languages[] = $language;
                }
            }
        }

        return $languages;
    }

    /**
     * The union of every provider's copy for a group, in priority order, with
     * the FIRST provider to hold a key winning it — the rule `copy` gets in the
     * table above.
     *
     * Per key, not per provider, because the two are genuinely independent: a
     * site module translating its own three strings must not have to re-answer
     * the twenty its locale already carries, and a provider that answers a group
     * another one also answers should not blank it out wholesale.
     *
     * Two details that are deliberate and not accidents of the loop:
     *
     * - **A priority win is silent.** Answering a key the winner did not hold is
     *   normal, so this is the one merge here that does NOT log contention: two
     *   providers naming the SAME key is a real disagreement worth a line, but
     *   telling them apart from the ordinary case costs a pass over the whole
     *   union for every key. Navigation takes the other trade and logs, because
     *   a lost menu is visible; a lost string is not.
     * - **An empty string is not an answer.** A stored row with a blank value
     *   does not mask the provider behind it, for the same reason every other
     *   method treats `''` as absent: the theme's own floor is better copy than
     *   a blank, and a blank is what a half-filled translation table looks like.
     *
     * @return array<string, string>
     */
    public static function copy(string $group, string $locale): array
    {
        $copy = [];

        foreach (self::all() as $provider) {
            foreach ($provider->copy($group, $locale) as $key => $value) {
                if ('' === $value || array_key_exists($key, $copy)) {
                    continue;
                }

                $copy[$key] = $value;
            }
        }

        return $copy;
    }

    /**
     * The stored settings of the active theme, merged per key — the same rule
     * `copy()` gets, for the same reason: a module that owns one theme-scoped
     * choice must not have to re-answer another module's rows, and two modules
     * answering the same field is a real disagreement worth a log line.
     *
     * An empty string is not an answer here either. A blank row keeps the
     * schema default visible instead of masking it with a value no theme
     * would render as anything but a broken option.
     *
     * @return array<string, string>
     */
    public static function themeSettings(): array
    {
        $settings = [];

        foreach (self::all() as $provider) {
            foreach ($provider->themeSettings() as $key => $value) {
                if ('' === $value || array_key_exists($key, $settings)) {
                    continue;
                }

                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * The first non-empty navigation for a slot — see the merge rule above.
     * A same-priority second menu is logged, never silently dropped.
     *
     * @return list<ShellNavItem>
     */
    public static function navigation(string $slot, string $currentPath): array
    {
        $answers = [];
        $winnerPriority = null;

        foreach (self::allWithPriority() as [$priority, $provider]) {
            if (null !== $winnerPriority && $priority > $winnerPriority) {
                break;
            }

            $items = $provider->navigation($slot, $currentPath);

            if ([] === $items) {
                continue;
            }

            $winnerPriority ??= $priority;
            $answers[] = ['priority' => $priority, 'name' => $provider::class, 'value' => $items];
        }

        $result = PreferenceVote::select($answers);
        self::logIgnored('navigation:' . $slot, $result);

        /** @var list<ShellNavItem> $winner */
        $winner = $result['winner']['value'] ?? [];

        return $winner;
    }

    /**
     * First non-empty answer wins; a same-priority second answer is logged,
     * never silently dropped. Providers past the winner's priority are not
     * even asked — precedence by design is not contention.
     *
     * @param \Closure(ShellContextProvider): ?string $ask
     */
    private static function firstString(\Closure $ask, string $question): ?string
    {
        $answers = [];
        $winnerPriority = null;

        foreach (self::allWithPriority() as [$priority, $provider]) {
            if (null !== $winnerPriority && $priority > $winnerPriority) {
                break;
            }

            $answer = $ask($provider);

            if (null === $answer || '' === $answer) {
                continue;
            }

            $winnerPriority ??= $priority;
            $answers[] = ['priority' => $priority, 'name' => $provider::class, 'value' => $answer];
        }

        $result = PreferenceVote::select($answers);
        self::logIgnored($question, $result);

        $winner = $result['winner']['value'] ?? null;

        return is_string($winner) ? $winner : null;
    }

    /**
     * @param array{winner: array{priority: int, name: string, value: mixed}|null, ignored: list<array{priority: int, name: string, value: mixed}>} $result
     */
    private static function logIgnored(string $question, array $result): void
    {
        if (null === $result['winner']) {
            return;
        }

        foreach ($result['ignored'] as $loser) {
            error_log(sprintf(
                '[shell] %s answer from %s ignored (same priority as %s)',
                $question,
                $loser['name'],
                $result['winner']['name'],
            ));
        }
    }

    /**
     * Candidate provider files, one glob — a file whose name does not say
     * `ShellContextProvider` is not one, and this is what keeps the scan from
     * loading the whole tree.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(AppPaths::instance()->src('Modules') . '/*/Interfaces/HTTP/*ShellContextProvider.php');

        return is_array($files) ? $files : [];
    }

    /**
     * @return array{int, ShellContextProvider}|null
     */
    private static function load(string $file): ?array
    {
        $content = file_get_contents($file);

        if (!is_string($content) || !str_contains($content, 'AsShellContextProvider')) {
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

        if (!$reflection->implementsInterface(ShellContextProvider::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(AsShellContextProvider::class);

        if ([] === $attributes) {
            return null;
        }

        if ($reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        /** @var ShellContextProvider $instance */
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
     * not exist, the file was skipped with no log line, and the provider silently
     * disappeared — measured on the Settings shell provider, whose nav menu
     * vanished exactly that way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
     *
     * tests/Discovery/TextFirstFqcnTest.php pins both halves, for all four
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
}
