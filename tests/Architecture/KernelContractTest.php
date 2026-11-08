<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * KernelContractTest — the kernel's PUBLIC contracts, checked for the one
 * thing this tree will not compromise.
 *
 * The claim is that the kernel runtime has zero Composer dependencies, and
 * three CI jobs (`admin-smoke`, `boot`, `extract-check`) plus every production
 * deployment run with no `vendor/` to keep it honest. What that claim does NOT
 * cover is a contract whose *type* names a package: such a class fails to load
 * with a fatal, which is exactly the failure the CI jobs exist to catch — but
 * only once, at the moment it breaks, in a tree nobody was looking at.
 *
 * `MigratorInterface::run()` carried one. It was
 * `CliOutput | OutputInterface` with `symfony/console`, a `require-dev`
 * package, and it worked for months — because PHP silently skips a class it
 * cannot resolve when matching a UNION type, so the dev-only arm was simply
 * never a candidate. Nothing recorded that the tree's central claim rested on a
 * language behaviour rather than on nothing. This test is the recording.
 *
 * It is deliberately cheap and deliberately dumb: reflection over the public
 * signatures the kernel publishes. A PHPStan rule would be the stronger tool,
 * but PHPStan does not analyse installed module slices (that is the point of
 * its scope comment) and the contract is exactly what modules implement.
 *
 * @internal
 *
 * @coversNothing
 */
final class KernelContractTest extends TestCase
{
    /**
     * Every kernel contract whose signature modules program against.
     *
     * Add to this list when a kernel interface or abstract class gains a
     * published method — the list is the coverage, not the class existing.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function publishedContracts(): iterable
    {
        yield 'MigratorInterface' => [MigratorInterface::class];
    }

    /**
     * @param class-string $contract
     */
    #[Test]
    #[DataProvider('publishedContracts')]
    public function aPublishedSignatureNeverNamesAPackageOutsideTheKernel(string $contract): void
    {
        $offenders = [];

        foreach ($this->publishedTypes(new ReflectionClass($contract)) as $type) {
            $name = $type->getName();

            // The kernel's own namespace is the kernel; everything else that is
            // a class rather than a scalar is a dependency by name.
            if (!str_starts_with($name, 'Modules\\Core\\') && !$this->isBuiltin($type)) {
                $offenders[$name] = true;
            }
        }

        $this->assertSame(
            [],
            array_keys($offenders),
            sprintf(
                '%s publishes a type outside Modules\Core. The kernel runtime has no Composer '
                . 'dependencies, and a contract that names a package is unloadable in every tree '
                . 'without vendor/ — which is every production deployment. If the type is a scalar '
                . 'or a PDO class, this is a false positive: widen isBuiltin() and say why.',
                $contract,
            ),
        );
    }

    #[Test]
    public function theMigratorContractTakesTheKernelsOwnOutputAndNothingElse(): void
    {
        $parameter = (new ReflectionClass(MigratorInterface::class))
            ->getMethod('run')
            ->getParameters()[0];

        $type = $parameter->getType();

        $this->assertNotNull($type, 'run() must declare what it reports through.');

        // Asserting it is a NAMED type, not a union, is the assertion: PHP
        // skips a class it cannot resolve when matching a union, which is
        // exactly how a require-dev arm survived here unrecorded. A union
        // would fail the next line rather than assert itself.
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(CliOutput::class, $type->getName());
    }

    /**
     * Every class-shaped type in the contract's own methods.
     *
     * @param ReflectionClass<object> $contract
     *
     * @return list<ReflectionNamedType>
     */
    private function publishedTypes(ReflectionClass $contract): array
    {
        $types = [];

        foreach ($contract->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $types = [...$types, ...$this->flatten($parameter->getType())];
            }

            $types = [...$types, ...$this->flatten($method->getReturnType())];
        }

        return $types;
    }

    /**
     * @return list<ReflectionNamedType>
     */
    private function flatten(mixed $type): array
    {
        if ($type instanceof ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $types = [];

            foreach ($type->getTypes() as $inner) {
                $types = [...$types, ...$this->flatten($inner)];
            }

            return $types;
        }

        return $type instanceof ReflectionNamedType ? [$type] : [];
    }

    private function isBuiltin(ReflectionNamedType $type): bool
    {
        // `int`, `string`, `void`, `mixed`, … are not dependencies. `self`,
        // `static` and the like are not classes either. `?PDO` IS a class, and
        // it is allowed explicitly below rather than by prefix, because PDO is
        // an ext- the kernel requires in composer.json, not a package.
        if ($type->isBuiltin()) {
            return true;
        }

        if (in_array(strtolower($type->getName()), ['self', 'static', 'parent'], true)) {
            return true;
        }

        return \PDO::class === $type->getName()
            || \DateTimeInterface::class === $type->getName()
            || \DateTimeImmutable::class === $type->getName()
            || \Throwable::class === $type->getName();
    }
}
