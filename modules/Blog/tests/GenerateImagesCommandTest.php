<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Interfaces\CLI\Command\GenerateImagesCommand;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GenerateImagesCommandTest — the operator command `bin/cms generate-images`
 * drives, beside the code it covers (module test contract: drives the
 * INSTALLED copy, skips on a kernel-only tree).
 *
 * The command used to be a `Symfony\...\Command`, unloadable without
 * `vendor/` — which is every production deployment. It is plain PHP now, and
 * what these tests pin is mostly its EXIT CODES: this is a bulk command an
 * operator runs to render every article, so "rendered nothing and reported
 * success" and "one of forty failed and reported success" are both worse than
 * a crash, because neither is visible.
 *
 * AppPaths has no root override (measured — `storage()` is always the
 * checkout, the same fact the admin-smoke gate snapshots around), so this test
 * writes into the tree's own storage and removes exactly what it created,
 * under a slug no one else would use.
 */
final class GenerateImagesCommandTest extends TestCase
{
    private ?string $slug = null;

    /** @var list<string> files this test created, removed in tearDown */
    private array $created = [];

    /** @var array<string, string> env name → the value it had, restored in tearDown */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Blog')) {
            $this->markTestSkipped('Blog is not installed.');
        }

        // The locale mapper reads settings; point it at a throwaway file so a
        // test cannot read (or write) a developer's real configuration.
        $this->useTemporaryDatabase('DB_SETTINGS');
        $this->useTemporaryDatabase('DB_CONFIG');

        $null = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        foreach (['settings', 'config'] as $module) {
            (new SettingsDatabaseMigrator(DatabaseConnectionManager::instance()->connection($module)))->run($null);
        }

        $this->slug = 'zz-test-cover-' . bin2hex(random_bytes(4));
        $article = AppPaths::instance()->storage('blog' . DIRECTORY_SEPARATOR . $this->slug . '.md');
        if (!is_dir(dirname($article))) {
            mkdir(dirname($article), 0o775, true);
        }
        file_put_contents(
            $article,
            "---\ntitle: Test Cover\ndate: 2026-10-16\n---\n\n# Test\n\n```php\necho 'hello';\n```\n",
        );
        $this->created[] = $article;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->created = [];

        foreach ($this->previousEnv as $name => $value) {
            putenv($name . '=' . $value);
            $this->previousEnv = [];
        }

        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();

        parent::tearDown();
    }

    #[Test]
    public function aSlugRendersOneFileAndExitsZero(): void
    {
        $output = $this->collector();
        $slug = (string) $this->slug;

        $exit = (new GenerateImagesCommand())->run(['generate-images', '--slug', $slug], $output);

        $svg = AppPaths::instance()->storage('blog-images' . DIRECTORY_SEPARATOR . $slug . '.svg');
        $this->created[] = $svg;

        $this->assertSame(0, $exit, $output->text());
        $this->assertFileExists($svg, 'the file is the assertion; the exit code only reports it.');
        $this->assertStringContainsString('Generated 1 image(s), 0 failed', $output->text());
    }

    #[Test]
    public function theShortFormSlugIsTheSameAsTheLongOne(): void
    {
        $output = $this->collector();
        $slug = (string) $this->slug;

        (new GenerateImagesCommand())->run(['generate-images', '-s', $slug], $output);

        $svg = AppPaths::instance()->storage('blog-images' . DIRECTORY_SEPARATOR . $slug . '.svg');
        $this->created[] = $svg;

        $this->assertFileExists($svg);
    }

    #[Test]
    public function aSlugThatMatchesNothingExitsOne(): void
    {
        $output = $this->collector();

        $exit = (new GenerateImagesCommand())->run(['generate-images', '--slug', 'no-such-article'], $output);

        // The whole point of `--slug` is to render exactly one article. A typo
        // there rendering nothing while printing a healthy summary is the one
        // outcome a bulk command cannot afford.
        $this->assertSame(1, $exit, $output->text());
        $this->assertStringContainsString('Generated 0 image(s)', $output->text());
    }

    #[Test]
    public function anUnknownTemplateExitsOneAndListsTheValidOnes(): void
    {
        $output = $this->collector();

        $exit = (new GenerateImagesCommand())->run(['generate-images', '-t', 'nesmysl'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid template: nesmysl', $output->text());
        $this->assertStringContainsString('Available templates:', $output->text());
    }

    #[Test]
    public function anUnknownColourExitsOneAndListsTheValidOnes(): void
    {
        $output = $this->collector();

        $exit = (new GenerateImagesCommand())->run(['generate-images', '-c', 'nesmysl'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid color: nesmysl', $output->text());
    }

    #[Test]
    public function aQualityOutsideTheRangeIsRefused(): void
    {
        $output = $this->collector();

        $exit = (new GenerateImagesCommand())->run(['generate-images', '--slug', (string) $this->slug, '--quality', '0'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Quality must be between 1 and 100', $output->text());
    }

    #[Test]
    public function anUnknownOptionExitsOneAndNamesIt(): void
    {
        $output = $this->collector();

        $exit = (new GenerateImagesCommand())->run(['generate-images', '--formt', 'svg'], $output);

        // A typo'd flag that rendered every article with the defaults and
        // printed a healthy summary would be worse than the crash it replaced.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown option "--formt"', $output->text());
    }

    #[Test]
    public function theCommandExtendsNothingAndNeedsNoComposerPackage(): void
    {
        // `symfony/console` is require-dev only, so a command extending it is
        // unloadable in a tree with no vendor/ — every production deployment.
        // A PHPUnit run always HAS vendor/, so it proves the shape, not the
        // absence: extending nothing leaves no class that could fail to load.
        $this->assertSame([], class_parents(GenerateImagesCommand::class));

        $source = file_get_contents(
            APP_BASE_PATH . '/src/Modules/Blog/Interfaces/CLI/Command/GenerateImagesCommand.php',
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use Symfony\\', $source);
    }

    private function useTemporaryDatabase(string $name): void
    {
        $this->previousEnv[$name] = getenv($name) ?: '';
        putenv($name . '=' . sys_get_temp_dir() . '/' . strtolower($name) . '-generate-images-' . bin2hex(random_bytes(4)) . '.db');
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
    }

    private function collector(): CliOutput
    {
        return new class implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }

            public function text(): string
            {
                return implode("\n", $this->lines);
            }
        };
    }
}