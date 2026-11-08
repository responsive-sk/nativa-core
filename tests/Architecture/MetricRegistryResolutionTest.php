<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * MetricRegistryResolutionTest — a metric provider cannot vanish in silence.
 *
 * Measured 2026-10-17, the day the `modules` dashboard card was added. The card
 * was correct, committed, pushed, and reported this:
 *
 *     Cardboard  drift: AdminNavService.php, BulkEntityGate.php, AdminLayout.php
 *
 * …while not appearing on `/mark` at all. `MetricRegistry::all()` returned ten
 * providers; the eleventh was missing and had been missing the whole time.
 *
 * The cause was one line of `resolveFqcn()`:
 *
 *     preg_match('/class\s+(\w+)/', $content, $cnMatch)
 *
 * unanchored, it matches the word `class` **anywhere in the file**, including
 * inside a docblock. The provider's own comment contained the sentence
 * "written under Tooling, it failed…", so the regex resolved the FQCN to
 * `Modules\Cardboard\Application\Services\was`, `require_once` found nothing,
 * `loadAndRegister()` returned false — and the method above it returns `void`,
 * so the caller had no way to know. Ten cards registered, the eleventh absent,
 * and every gate in this tree green.
 *
 * A card that is not on the dashboard reports nothing, and a registry that
 * drops a provider cannot report that either. So the resolution is pinned here
 * instead: every `#[AsMetricProvider]` class on disk must be in
 * `MetricRegistry::all()`.
 *
 * ## Why this test names the registry by string
 *
 * `MetricRegistry` is Cardboard, and Cardboard is an optional module: a
 * kernel-only checkout has no `src/Modules/Cardboard` at all. A plain `use`
 * plus a static call here would be sixteen `class.notFound` errors on every
 * kernel-only run — the same shape `AuthMiddlewareDenialTest` carried until
 * task 0.2 removed it. So the registry is reached through `ReflectionMethod`
 * on a class-string, and the test skips by name where the module is absent.
 *
 * That means this file cannot statically prove the registry's method names. The
 * `MethodNotFoundException` catch below is the honest shape of that: if Cardboard
 * renames `all()`, this test skips and says so in its output, and the
 * `everyDeclaredProviderIsRegistered` case goes with it. That is a real limit of
 * putting the assertion outside the module, and it is stated rather than hidden.
 *
 * @internal
 *
 * @coversNothing
 */
final class MetricRegistryResolutionTest extends TestCase
{
    /**
     * Spelled as a string, NOT `::class`, and Rector's ReturnNeverTypeRector
     * suggestion is declined on purpose.
     *
     * `::class` is a static reference for PHPStan: written in `tests/`, it
     * reads as a kernel file naming Cardboard, and `composer phpstan` failed
     * with `Class Modules\Cardboard\Infrastructure\Registry\MetricRegistry not
     * found` on every kernel-only run — the analyser resolves the constant
     * whether or not the tree loaded it. The string form is the same value with
     * no reference in it, which is what this test needs in order to skip on a
     * checkout without Cardboard.
     */
    private const string REGISTRY = 'Modules\Cardboard\Infrastructure\Registry\MetricRegistry';

    #[Test]
    public function everyDeclaredMetricProviderIsRegistered(): void
    {
        /** @var list<object> $instances */
        $instances = $this->registryMethod('all')->invoke(null);

        $registered = array_map(static fn (object $metric): string => $metric::class, $instances);

        $declared = $this->declaredProviders();

        self::assertNotSame(
            [],
            $declared,
            'No #[AsMetricProvider] class was found on disk, so this test proves nothing.'
            . ' If the scan path is wrong, that is the finding — not a pass.',
        );

        $missing = array_values(array_diff($declared, $registered));

        self::assertSame(
            [],
            $missing,
            "A metric provider exists on disk and is not registered:\n"
            . implode("\n", $missing)
            . "\nThe card it would have drawn is absent from the dashboard and nothing"
            . ' says so. A provider that resolves to a class name that does not exist'
            . ' fails closed and silent, which is how this was found: ten cards'
            . ' registered, the eleventh missing, every gate green.',
        );
    }

    /**
     * A docblock mentioning `class` must not become the class name.
     *
     * The regression directly: the old pattern resolved
     * `Modules\Cardboard\Application\Services\was` from a comment containing
     * the word "class". Anchored to a declaration line, it resolves the class.
     */
    #[Test]
    public function aDocblockMentioningClassDoesNotBecomeTheClassName(): void
    {
        $source = <<<'PHP'
<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

/**
 * Written under Tooling, it failed with six edges, and the class it declared
 * was never reached.
 */
final readonly class WidgetMetric implements \Modules\Cardboard\Domain\Contracts\MetricContract
{
}

PHP;

        $resolved = $this->registryMethod('resolveFqcn')->invoke(null, $source);

        self::assertSame(
            'Modules\\Cardboard\\Application\\Services\\WidgetMetric',
            $resolved,
            'The resolver read the word "class" out of a docblock instead of the'
            . ' declaration. That is how the modules card went missing with every gate green.',
        );
    }

    /**
     * The registry's method, or a skip naming the module that is absent.
     *
     * `class_exists()` on the constant is not enough on its own: the guard has
     * to sit on the same path the reflection takes, and a `ReflectionMethod` on
     * a class that never loaded throws instead of returning null.
     */
    private function registryMethod(string $name): ReflectionMethod
    {
        $registry = self::REGISTRY;

        if (!class_exists($registry)) {
            self::markTestSkipped(
                'Cardboard is not installed — there is no metric registry in this tree.',
            );
        }

        return new ReflectionMethod($registry, $name);
    }

    /**
     * @return list<string> FQCNs of every `#[AsMetricProvider]` file on disk
     */
    private function declaredProviders(): array
    {
        $found = [];

        foreach (glob(APP_BASE_PATH . '/src/Modules/*/Application/Services/*Metric.php') ?: [] as $file) {
            $content = (string) file_get_contents($file);

            if (!str_contains($content, 'AsMetricProvider')) {
                continue;
            }

            if (1 === preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $content, $ns)
                && 1 === preg_match('/^\s*(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $content, $cn)
            ) {
                $found[] = $ns[1] . '\\' . $cn[1];
            }
        }

        sort($found);

        return $found;
    }
}
