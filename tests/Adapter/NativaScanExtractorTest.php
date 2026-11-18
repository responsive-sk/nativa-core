<?php

declare(strict_types = 1);

namespace Tests\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NativaScanExtractorTest — the PHP shapes the scan extractor has to name
 * correctly, proved on source bytes alone.
 *
 * The extractor (`nativaDeclarationsIn`) is a pure function over a file's
 * bytes, so it needs no project view, no adapter exchange and no CLI: the
 * test defines `NATIVA_ADAPTER_LIBRARY` and loads the shipped adapter file
 * itself, which is the same code path the confined host runs.
 *
 * Three shapes are here because they are the ones that were silently wrong
 * while the scan looked healthy on a real tree:
 *
 *   - a member of an anonymous class is a `method` with a bare name; before
 *     the fix it was reported as a namespace `function` (`App\Demo\inner`),
 *     which is a symbol that does not exist,
 *   - `Foo::class` is a constant reference, not a class declaration, and the
 *     name token that follows the keyword is not a declaration name,
 *   - a closure or an arrow function declares no symbol.
 *
 * The first case pins the whole shape in source order, so a new branch that
 * reorders or duplicates entries fails loudly instead of drifting.
 */
final class NativaScanExtractorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('NATIVA_ADAPTER_LIBRARY')) {
            define('NATIVA_ADAPTER_LIBRARY', true);
        }

        require_once dirname(__DIR__, 2) . '/adapters/php-nativa/adapter.php';
    }

    #[Test]
    public function everyDeclarationShapeIsNamedByItsQualifiedName(): void
    {
        $source = <<<'PHP_SOURCE'
            <?php
            declare(strict_types=1);

            namespace App\Demo;

            interface Renderable
            {
                public function render(): string;
            }

            trait Labelled
            {
                public function label(): string { return 'label'; }
            }

            enum Kind: string
            {
                case Widget = 'widget';
            }

            const PREFIX = 'demo-';

            final class Widget implements Renderable
            {
                use Labelled;

                public const DEFAULTS = ['size' => 1];

                public function render(): string { return 'widget'; }
            }

            function makeWidget(): Widget { return new Widget(); }
            PHP_SOURCE;

        $this->assertSame(
            [
                ['kind' => 'interface', 'detail' => 'App\Demo\Renderable'],
                ['kind' => 'method', 'detail' => 'App\Demo\Renderable::render'],
                ['kind' => 'trait', 'detail' => 'App\Demo\Labelled'],
                ['kind' => 'method', 'detail' => 'App\Demo\Labelled::label'],
                ['kind' => 'enum', 'detail' => 'App\Demo\Kind'],
                ['kind' => 'constant', 'detail' => 'App\Demo\PREFIX'],
                ['kind' => 'class', 'detail' => 'App\Demo\Widget'],
                ['kind' => 'class-constant', 'detail' => 'App\Demo\Widget::DEFAULTS'],
                ['kind' => 'method', 'detail' => 'App\Demo\Widget::render'],
                ['kind' => 'function', 'detail' => 'App\Demo\makeWidget'],
            ],
            nativaDeclarationsIn($source),
            'Enum cases are not `const`, and the declaration order is the source order.',
        );
    }

    #[Test]
    public function anAnonymousClassMemberIsAMethodWithABareName(): void
    {
        $source = <<<'PHP_SOURCE'
            <?php
            namespace App\Demo;

            final class Host
            {
                public function build(): object
                {
                    return new class {
                        public function inner(): int { return 1; }
                    };
                }
            }
            PHP_SOURCE;

        $this->assertSame(
            [
                ['kind' => 'class', 'detail' => 'App\Demo\Host'],
                ['kind' => 'method', 'detail' => 'App\Demo\Host::build'],
                ['kind' => 'method', 'detail' => 'inner'],
            ],
            nativaDeclarationsIn($source),
            'An anonymous class has no name to qualify its members with; inventing one would name a symbol that does not exist.',
        );
    }

    #[Test]
    public function aClassConstantReferenceAndAClosureDeclareNothing(): void
    {
        $source = <<<'PHP_SOURCE'
            <?php
            namespace App\Demo;

            final class Widget
            {
                public function name(): string { return self::class; }
            }

            $closure = function (): string { return 'closure'; };
            $arrow = static fn (): string => 'arrow';
            PHP_SOURCE;

        $this->assertSame(
            [
                ['kind' => 'class', 'detail' => 'App\Demo\Widget'],
                ['kind' => 'method', 'detail' => 'App\Demo\Widget::name'],
            ],
            nativaDeclarationsIn($source),
            '`self::class` is a reference to an existing name; closures and arrow functions declare no symbol.',
        );
    }

    #[Test]
    public function theExtractorIsDeterministicOverTheSameBytes(): void
    {
        $source = "<?php\nnamespace App;\nfinal class A { public function b(): void {} }\n";

        $this->assertSame(nativaDeclarationsIn($source), nativaDeclarationsIn($source));
    }
}
