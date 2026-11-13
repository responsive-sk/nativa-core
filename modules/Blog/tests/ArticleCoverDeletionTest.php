<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Cardboard\Application\Services\AdminLocale;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Settings\Infrastructure\Persistence\Migrations\SettingsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ArticleCoverDeletionTest — what deleting an article leaves behind, and what
 * it must not touch (module test contract: drives the INSTALLED copy, skips
 * on a kernel-only tree).
 *
 * `BlogCrudService::delete()` was a single `unlink()` of the markdown file, so
 * every article ever deleted left its generated SVG behind — still on disk,
 * still served by `ServeBlogImageAction` under `max-age=31536000`, reachable
 * at a URL nothing links to any more. `lekalo/modules/blog/commands.yaml`
 * described the command as removing "its tracked cover if owned" all along, so
 * the model and the code disagreed and nothing could catch it: the model has
 * no adapter (docs/modules.md, "The Lekalo model").
 *
 * Half this file is NEGATIVE, and that is the half that matters. A fix that
 * stops the leak by unlinking harder passes "the cover is gone" and breaks a
 * live article. Two things make that possible here, both in
 * `CoverPathResolver::existingCovers()`:
 *
 *  - the flat `blog-images/{slug}.svg` carries no locale, so a locale that
 *    gains an article AFTER the cover was written owns a file that is not its
 *    own — removing it blanks the other article's image;
 *  - `resolveCover()` ranks an explicit `image:` in front matter above the
 *    generated file, so one article can deliberately point at another's cover.
 *
 * `coverRemovalIsNotLoadBearingWithoutTheReferenceScan()` is not a test of
 * behaviour — it exists so the next person who simplifies the delete has to
 * delete a test, on purpose, rather than quietly ship the over-delete.
 *
 * AppPaths has no root override (measured — `storage()` is always the
 * checkout, the same fact the admin-smoke gate snapshots around), so this test
 * writes into the tree's own storage and removes exactly what it created,
 * under slugs no one else would use.
 */
final class ArticleCoverDeletionTest extends TestCase
{
    private string $slug;

    /** @var list<string> files and directories this test created, removed in tearDown */
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
        // test cannot read (or write) a developer's real configuration. The
        // fresh settings database carries sk_SK and en_US, which is what makes
        // the cross-locale cases below mean anything.
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

        $this->slug = 'zz-test-cover-del-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
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
    public function deletingAnArticleRemovesItsFlatCover(): void
    {
        $article = $this->writeArticle('sk', $this->slug);
        $cover = $this->writeCover($this->slug . '.svg');

        $this->assertTrue((new BlogCrudService())->delete($this->slug, 'sk'));
        $this->assertFileDoesNotExist($article);
        $this->assertFileDoesNotExist($cover);
    }

    #[Test]
    public function deletingAnArticleRemovesItsLocaleQualifiedCover(): void
    {
        $article = $this->writeArticle('sk', $this->slug);
        $cover = $this->writeCover('sk' . DIRECTORY_SEPARATOR . $this->slug . '.svg');

        $this->assertTrue((new BlogCrudService())->delete($this->slug, 'sk'));
        $this->assertFileDoesNotExist($article);
        $this->assertFileDoesNotExist($cover);
    }

    #[Test]
    public function aCoverAnotherArticlePointsAtSurvivesTheDelete(): void
    {
        $owner = $this->writeArticle('sk', $this->slug);
        $cover = $this->writeCover($this->slug . '.svg');

        $borrower = $this->writeArticle(
            'sk',
            $this->slug . '-borrower',
            '/storage/blog-images/' . $this->slug . '.svg',
        );

        $this->assertTrue((new BlogCrudService())->delete($this->slug, 'sk'));

        $this->assertFileDoesNotExist($owner, 'the article itself is gone');
        $this->assertFileExists($borrower, 'the borrowing article is untouched by the scan');
        $this->assertFileExists($cover, 'the borrowed cover is still what the surviving article renders');
    }

    #[Test]
    public function theOtherLocalesSameSlugCoverSurvivesAndSoDoesTheFlatOne(): void
    {
        $sk = $this->writeArticle('sk', $this->slug);
        $en = $this->writeArticle('en', $this->slug);

        $skCover = $this->writeCover('sk' . DIRECTORY_SEPARATOR . $this->slug . '.svg');
        $enCover = $this->writeCover('en' . DIRECTORY_SEPARATOR . $this->slug . '.svg');
        $flat = $this->writeCover($this->slug . '.svg');

        $this->assertTrue((new BlogCrudService())->delete($this->slug, 'sk'));

        $this->assertFileDoesNotExist($sk);
        $this->assertFileDoesNotExist($skCover, 'the deleted locale cannot render it');

        $this->assertFileExists($en);
        $this->assertFileExists($enCover);
        // The flat file carries no locale. Once two locales share the slug it
        // can only be a leftover from before the collision — and it may equally
        // be the OTHER locale's cover, written before this article existed.
        $this->assertFileExists($flat);
    }

    #[Test]
    public function deletingAnArticleThatIsNotThereIsStillFalse(): void
    {
        $this->assertFalse((new BlogCrudService())->delete('zz-no-such-article-' . bin2hex(random_bytes(4)), 'sk'));
    }

    #[Test]
    public function theDeleteWorksWithTheFullLocaleCodeTheAdminActuallyPasses(): void
    {
        // This test exists because the admin-smoke gate caught the defect it
        // was written for. Every other test here passes 'sk', because that is
        // what the generator's `localeFor()` returns — but the admin delete
        // goes through `AdminLocale::canonical()`, which returns `sk_SK`.
        //
        // Handed `sk_SK`, `collides()` compares by language code, so its
        // `continue` never fires, the article matches ITSELF as "another
        // locale's", and the flat cover is excluded from the delete. The
        // article vanished and its cover stayed — with the whole module suite
        // green. Asserting through the real entry point is the only reason
        // that spelling is covered at all.
        $article = $this->writeArticle('sk', $this->slug);
        $cover = $this->writeCover($this->slug . '.svg');

        $locale = AdminLocale::canonical('sk_SK');
        $this->assertSame('sk_SK', $locale, 'the admin really does pass the full code');

        $this->assertTrue((new BlogCrudService())->delete($this->slug, $locale));
        $this->assertFileDoesNotExist($article);
        $this->assertFileDoesNotExist($cover);
    }

    #[Test]
    public function coverRemovalIsNotLoadBearingWithoutTheReferenceScan(): void
    {
        // Not a behaviour test. It pins the shape of the fix so the next
        // simplification has to delete this test on purpose instead of
        // quietly reintroducing the over-delete: the scan is what makes
        // `aCoverAnotherArticlePointsAtSurvivesTheDelete` pass, and removing it
        // turns that case red without changing any other assertion here.
        $source = file_get_contents(
            APP_BASE_PATH . '/src/Modules/Blog/Application/Services/BlogCrudService.php',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('coverReferences', $source);
        $this->assertStringContainsString('existingCovers', $source);
    }

    /**
     * Write an article, optionally carrying an explicit `image:`.
     *
     * Placed through the mapper, not by hand: the default locale lives at the
     * family ROOT and only a second locale gets a `{lang}/` subdirectory, so
     * building the path by concatenation writes the fixture somewhere
     * `delete()` never looks — which is exactly the assumption this test is
     * most likely to make by accident.
     */
    private function writeArticle(string $locale, string $slug, ?string $image = null): string
    {
        $dir = AppPaths::instance()->storage(BlogArticlesService::directoryFor($locale));

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
            $this->created[] = $dir;
        }

        $front = [
            '---',
            'title: "Cover deletion case ' . $slug . '"',
            'date: 2026-10-16',
            'published: true',
        ];

        if (null !== $image) {
            $front[] = 'image: "' . $image . '"';
        }

        $front[] = '---';

        $file = $dir . DIRECTORY_SEPARATOR . $slug . '.md';
        file_put_contents($file, implode("\n", $front) . "\n\nBody.\n");
        $this->created[] = $file;

        return $file;
    }

    private function writeCover(string $relative): string
    {
        $path = AppPaths::instance()->storage('blog-images' . DIRECTORY_SEPARATOR . $relative);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
            $this->created[] = $dir;
        }

        file_put_contents(
            $path,
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
        );
        $this->created[] = $path;

        return $path;
    }

    private function useTemporaryDatabase(string $name): void
    {
        $this->previousEnv[$name] = getenv($name) ?: '';
        putenv($name . '=' . sys_get_temp_dir() . '/' . strtolower($name) . '-cover-del-' . bin2hex(random_bytes(4)) . '.db');
        AppPaths::resetInstance();
        DatabaseConnectionManager::resetInstance();
    }
}