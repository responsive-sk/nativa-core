<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\BlogServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TaxonomyRegistryTest — the registry half of /mark/categories (Boot 18).
 *
 * A pre-created category lives ONLY in the registry
 * (`storage/data/blog-taxonomy.json`); with no article using it, nothing else
 * knows it exists. Rename and Delete used to rewrite article front matter and
 * nothing else, so on such a category both answered 302 and changed nothing —
 * measured on a tree with no `storage/blog/` at all, where every category was
 * in that case.
 *
 * ## What this test is careful about
 *
 * - **The registry is shared runtime state**, not a fixture: `storage/data/`
 *   belongs to the running site. So the file is snapshotted in setUp() and
 *   restored in tearDown(), including "it did not exist".
 * - **Blog is a module.** A kernel-only tree has no registry to test, so the
 *   suite skips rather than fails — the same contract KernelOnlyBootTest uses
 *   for Cardboard. This is why the file lives HERE, beside the code it covers,
 *   and not under `tests/`: `tests/` is inside PHPStan's scope and `modules/`
 *   is not, so a test naming an optional module's class cannot make the
 *   analyser of a tree without that module fail (CI run #1 did exactly that).
 * - **It drives the service, not the HTTP action.** The action's article half
 *   needs `storage/blog/`; the registry half is what regressed.
 */
final class TaxonomyRegistryTest extends TestCase
{
    private const MODULE = 'Blog';

    /** Absolute path of the registry file the service reads and writes. */
    private string $registry;

    /** @var string|null file contents before the test, null when absent */
    private ?string $snapshot = null;

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — the taxonomy registry is a Blog service.');
        }

        $this->registry = APP_BASE_PATH . '/storage/data/blog-taxonomy.json';
        $this->snapshot = is_file($this->registry)
            ? (string) file_get_contents($this->registry)
            : null;

        // Start from an empty registry so the assertions cannot pass by
        // finding an entry a previous run left behind.
        $this->writeRegistry([]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->registry)) {
            return;
        }

        if (null === $this->snapshot) {
            if (is_file($this->registry)) {
                unlink($this->registry);
            }

            return;
        }

        file_put_contents($this->registry, $this->snapshot);
    }

    #[Test]
    public function renameMovesARegistryOnlyCategory(): void
    {
        $this->writeRegistry(['Alpha']);

        $this->assertTrue(BlogServices::taxonomy()->renameCategory('Alpha', 'Beta'));
        $this->assertSame(['Beta'], $this->categories(), 'The registry entry must carry the new name.');
    }

    /**
     * Case-insensitive match, like the readers' dedupe rule: an admin typing
     * `alpha` for a category stored as `Alpha` means that category.
     */
    #[Test]
    public function renameMatchesCaseInsensitively(): void
    {
        $this->writeRegistry(['Alpha']);

        $this->assertTrue(BlogServices::taxonomy()->renameCategory('alpha', 'Gamma'));
        $this->assertSame(['Gamma'], $this->categories());
    }

    /**
     * Renaming onto an existing entry is a MERGE, not a duplicate: the articles
     * move the same way, so one entry must survive.
     */
    #[Test]
    public function renameOntoAnExistingEntryMergesInsteadOfDuplicating(): void
    {
        $this->writeRegistry(['Alpha', 'Beta']);

        $this->assertTrue(BlogServices::taxonomy()->renameCategory('Alpha', 'beta'));
        $this->assertSame(['beta'], $this->categories());
    }

    #[Test]
    public function renameRefusesEmptyIdenticalAndUnknownNames(): void
    {
        $this->writeRegistry(['Alpha']);
        $taxonomy = BlogServices::taxonomy();

        $this->assertFalse($taxonomy->renameCategory('', 'Beta'), 'Empty source.');
        $this->assertFalse($taxonomy->renameCategory('Alpha', '  '), 'Empty target.');
        $this->assertFalse($taxonomy->renameCategory('Alpha', 'Alpha'), 'Identical name.');
        $this->assertFalse($taxonomy->renameCategory('Missing', 'Beta'), 'Nothing matched.');
        $this->assertSame(['Alpha'], $this->categories(), 'A refused rename changes nothing.');
    }

    #[Test]
    public function deleteDropsARegistryOnlyCategory(): void
    {
        $this->writeRegistry(['Alpha', 'Beta']);
        $taxonomy = BlogServices::taxonomy();

        $this->assertTrue($taxonomy->deleteCategory('alpha'));
        $this->assertSame(['Beta'], $this->categories());
        $this->assertFalse($taxonomy->deleteCategory('Alpha'), 'It is gone — a second delete is a no-op.');
        $this->assertSame(['Beta'], $this->categories());
    }

    /**
     * @param list<string> $categories
     */
    private function writeRegistry(array $categories): void
    {
        file_put_contents(
            $this->registry,
            json_encode(['tags' => [], 'categories' => $categories], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        /** @var array{categories: list<string>} $decoded */
        $decoded = json_decode((string) file_get_contents($this->registry), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['categories'];
    }
}
