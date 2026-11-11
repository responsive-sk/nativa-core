<?php

declare(strict_types = 1);

namespace Tests\Discovery;

use Modules\Core\Interfaces\HTTP\Middleware\MiddlewareRegistry;
use Modules\Layout\ThemeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\ShellContextProviderRegistry;

/**
 * TextFirstFqcnTest (Boot 23 follow-up) — the discovery heuristic, and the prose
 * that used to defeat it.
 *
 * The registries find their implementations the same text-first way: read the
 * file, take the namespace from it, take the type name from it, and only then
 * load anything. That is deliberate — it is what keeps discovery from loading
 * every class in the tree just to find out what they are.
 *
 * The heuristic used to take the FIRST mention of the keyword anywhere in the
 * file. So a docblock sentence like "This class says the one thing…" resolved a
 * type named `says`; the class did not exist, the file was SKIPPED, and the
 * provider / theme / middleware simply disappeared — no error, no log line.
 * Measured on the Settings shell provider, whose nav menu vanished exactly that
 * way (docs/kernel-status/03-content-themes-and-the-admin-merge.md, Boot 23).
 *
 * The rule these tests pin is the one the resolver now follows: a declaration
 * is a line that begins with optional modifiers and the keyword — prose cannot
 * be one, because prose has something else first.
 *
 * One test file for all the registries on purpose: they hold copies of the
 * same resolver, and this is what keeps a future edit from fixing one and
 * leaving the other four behind — the fifth (`AuthProviderRegistry`) was
 * rebuilt on this very shape when attribute discovery returned.
 */
final class TextFirstFqcnTest extends TestCase
{
    /** The registries that resolve an FQCN from file text. */
    private const array REGISTRIES = [
        ShellContextProviderRegistry::class,
        ThemeRegistry::class,
        MiddlewareRegistry::class,
        'Modules\\Markdown\\Interfaces\\HTTP\\Markdown\\MarkdownBlockRegistry',
        'Modules\\Auth\\Application\\Services\\AuthProviderRegistry',
        'Modules\\Navigation\\Infrastructure\\Registry\\NavBadgeRegistry',
    ];

    /**
     * The registries present in this checkout. Markdown, Auth and Navigation
     * are modules; a kernel-only export must still exercise the three kernel
     * copies rather than fatal on a class the export deliberately does not
     * ship. The optional names stay strings: `::class` would make PHPStan
     * resolve a class the export does not carry, before the runtime filter can
     * skip it.
     *
     * @return list<class-string>
     */
    private static function registries(): array
    {
        return array_values(array_filter(
            self::REGISTRIES,
            static fn (string $registry): bool => class_exists($registry),
        ));
    }

    /**
     * A file-level docblock that names both keywords before either declaration.
     *
     * The order matters: this is the shape that shadowed the Settings provider,
     * and the docblock sits above the namespace, so the namespace mention is the
     * FIRST one in the file and could shadow that half too.
     */
    private const string PROSE_BEFORE_DECLARATION = <<<'SOURCE'
        <?php

        declare(strict_types = 1);

        /**
         * This class says the one thing nothing else in the tree says.
         *
         * Prose mentioning a namespace Modules\Wrong\Place; is not a declaration.
         */

        namespace Modules\Demo\Interfaces\HTTP;

        #[AsShellContextProvider]
        final class DemoShellContextProvider implements ShellContextProvider
        {
        }
        SOURCE;

    private const string EXPECTED = 'Modules\Demo\Interfaces\HTTP\DemoShellContextProvider';

    #[Test]
    public function proseBeforeTheDeclarationCannotBecomeTheTypeName(): void
    {
        foreach (self::registries() as $registry) {
            $this->assertSame(
                self::EXPECTED,
                self::resolve($registry, self::PROSE_BEFORE_DECLARATION),
                $registry . ' read a word out of the docblock as the type name — a discovery that silently skips the file.',
            );
        }
    }

    #[Test]
    public function proseMentioningANamespaceCannotBecomeTheNamespace(): void
    {
        foreach (self::registries() as $registry) {
            $this->assertStringStartsWith(
                'Modules\Demo\Interfaces\HTTP\\',
                (string) self::resolve($registry, self::PROSE_BEFORE_DECLARATION),
                $registry . ' read the namespace out of the docblock.',
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declarations(): iterable
    {
        yield 'plain' => ["namespace Demo;\nclass Thing {}"];

        yield 'final' => ["namespace Demo;\nfinal class Thing {}"];

        yield 'abstract' => ["namespace Demo;\nabstract class Thing {}"];

        yield 'readonly' => ["namespace Demo;\nreadonly class Thing {}"];

        yield 'final readonly' => ["namespace Demo;\nfinal readonly class Thing {}"];

        yield 'indented' => ["namespace Demo;\n    class Thing {}"];

        // Compact but legal: the heuristic must not trade one silent skip for another.
        yield 'namespace and type on one line' => ['namespace Demo; class Thing {}'];

        yield 'after a declare statement' => ["<?php\ndeclare(strict_types = 1);\n\nnamespace Demo;\n\nclass Thing {}"];

        yield 'with a docblock above the type' => ["namespace Demo;\n\n/** Prose about a class Thing here. */\nfinal class Thing {}"];
    }

    #[Test]
    #[DataProvider('declarations')]
    public function aRealDeclarationStillResolves(string $source): void
    {
        foreach (self::registries() as $registry) {
            $this->assertSame('Demo\Thing', self::resolve($registry, $source), $registry);
        }
    }

    #[Test]
    public function aFileWithNoDeclarationAnswersNullRatherThanGuessing(): void
    {
        $source = "<?php\n\n/** A file whose docblock talks about a class of things. */\nnamespace Demo;\n";

        foreach (self::registries() as $registry) {
            $this->assertNull(
                self::resolve($registry, $source),
                $registry . ' invented a type out of prose instead of answering null.',
            );
        }
    }

    /**
     * The resolvers are private: the heuristic IS the unit under test, and there
     * is no seam to drive it through without installing a module into the
     * working tree.
     */
    private static function resolve(string $registry, string $source): ?string
    {
        $method = new \ReflectionMethod($registry, 'resolveFqcn');

        /** @var string|null $fqcn */
        $fqcn = $method->invoke(null, $source);

        return $fqcn;
    }
}
