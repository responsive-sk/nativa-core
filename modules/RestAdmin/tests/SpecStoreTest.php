<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Spec\SpecStore;
use Modules\RestAdmin\Application\Spec\SpecValidationException;
use Modules\RestAdmin\Application\Spec\SpecValidator;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Infrastructure\Persistence\AtomicJsonFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SpecStoreTest — the portable snapshot is versioned, validated and atomic.
 *
 * Three properties, each with a failure that has no symptom until it is too
 * late:
 *
 * 1. **A version this build does not read is refused by number.** A silently
 *    ignored future field is a configured resource that does not work.
 * 2. **A loaded snapshot is re-validated.** The file is a document; it can be
 *    edited by hand or written by an older build, and the aggregate is only
 *    worth having if it is a valid one.
 * 3. **A replace is all-or-nothing.** The tests drive the primitive directly
 *    for the failure cases, because `rename()` failing under a process that is
 *    still alive is exactly the interruption the tmp-file exists for: the old
 *    bytes survive and no `.tmp` is left behind.
 */
final class SpecStoreTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-restadmin-store-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_dir($file)) {
                    foreach (glob($file . '/*') ?: [] as $child) {
                        @unlink($child);
                    }

                    @rmdir($file);

                    continue;
                }

                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function savingWritesAVersionedSnapshotWithATimestamp(): void
    {
        $path = $this->store()->save($this->spec());

        self::assertSame($this->tmpDir . '/snapshots/blog.json', $path);
        self::assertFileExists($path);

        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);
        self::assertSame(1, $document['version']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            (string) $document['updated_at'],
        );
        self::assertSame('blog', $document['spec']['service']['id']);
    }

    #[Test]
    public function loadRoundTripsTheWholeSpecification(): void
    {
        $spec = $this->spec();
        $store = $this->store();
        $store->save($spec);

        $loaded = $store->load('blog');

        self::assertInstanceOf(ServiceSpec::class, $loaded);
        self::assertSame($spec->toArray(), $loaded->toArray());
        self::assertCount(2, $loaded->resources);
        self::assertSame(['list', 'read', 'create'], $loaded->resources[0]->actions->toArray());
        self::assertSame('author_id', $loaded->resources[0]->relations[0]->foreignKey);
    }

    #[Test]
    public function loadReturnsNullWhenThereIsNoSnapshot(): void
    {
        self::assertNull($this->store()->load('blog'));
    }

    #[Test]
    public function aFutureVersionIsRefusedByNumber(): void
    {
        $directory = $this->tmpDir . '/snapshots';
        mkdir($directory, 0o775, true);

        (new AtomicJsonFile())->write($directory . '/blog.json', [
            'version'    => 99,
            'updated_at' => '2026-11-13T00:00:00+00:00',
            'spec'       => $this->spec()->toArray(),
        ]);

        try {
            $this->store()->load('blog');
        } catch (SpecValidationException $exception) {
            self::assertSame('version', $exception->field);
            self::assertStringContainsString('99', $exception->getMessage());

            return;
        }

        self::fail('A snapshot written by a newer build was read as if it were this one.');
    }

    #[Test]
    public function aHandEditedSnapshotIsReValidatedOnLoad(): void
    {
        $directory = $this->tmpDir . '/snapshots';
        mkdir($directory, 0o775, true);

        (new AtomicJsonFile())->write($directory . '/blog.json', [
            'version'    => 1,
            'updated_at' => '2026-11-13T00:00:00+00:00',
            'spec'       => [
                'service'   => ['id' => 'blog', 'name' => 'Blog API', 'base_url' => 'ftp://api.blog.test'],
                'resources' => [],
            ],
        ]);

        try {
            $this->store()->load('blog');
        } catch (SpecValidationException $exception) {
            self::assertSame('service.base_url', $exception->field);

            return;
        }

        self::fail('An invalid snapshot was loaded without validation.');
    }

    #[Test]
    public function removeDeletesTheSnapshotAndIsIdempotent(): void
    {
        $store = $this->store();
        $path = $store->save($this->spec());

        $store->remove('blog');

        self::assertFileDoesNotExist($path);
        $store->remove('blog');
    }

    #[Test]
    public function aPathTraversalIsRefusedBeforeItBecomesAPath(): void
    {
        $this->expectException(SpecValidationException::class);

        $this->store()->load('../etc/passwd');
    }

    #[Test]
    public function aFailedReplaceLeavesNoTemporaryFile(): void
    {
        $directory = $this->tmpDir . '/snapshots';
        mkdir($directory . '/blog.json', 0o775, true);

        $failed = false;

        try {
            $this->store()->save($this->spec());
        } catch (\RuntimeException) {
            // The target is a directory, so rename() must fail. That is the
            // interruption this test is about.
            $failed = true;
        }

        self::assertTrue($failed, 'rename() onto a directory must fail rather than half-replace a snapshot.');
        self::assertSame([], glob($directory . '/*.tmp') ?: []);
        self::assertDirectoryExists($directory . '/blog.json');
    }

    #[Test]
    public function aSuccessfulWriteLeavesNoTemporaryFileBehind(): void
    {
        $path = $this->tmpDir . '/atomic.json';

        (new AtomicJsonFile())->write($path, ['ok' => true]);

        self::assertSame([], glob($this->tmpDir . '/*.tmp') ?: []);
        self::assertSame(['ok' => true], json_decode((string) file_get_contents($path), true));
    }

    /**
     * Encoding happens before the temporary file is opened, so a payload that
     * cannot be encoded is the earliest possible interruption points — and the
     * previous bytes must still be there.
     */
    #[Test]
    public function aPayloadThatCannotBeEncodedLeavesThePreviousFileIntact(): void
    {
        $path = $this->tmpDir . '/atomic.json';
        $files = new AtomicJsonFile();
        $files->write($path, ['ok' => true]);
        $before = (string) file_get_contents($path);

        try {
            $files->write($path, ['n' => NAN]);
        } catch (\JsonException) {
            self::assertSame($before, (string) file_get_contents($path));

            return;
        }

        self::fail('A payload that cannot be encoded was written.');
    }

    private function store(): SpecStore
    {
        return new SpecStore($this->tmpDir . '/snapshots');
    }

    private function spec(): ServiceSpec
    {
        return (new SpecValidator())->validate([
            'service'   => [
                'id'       => 'blog',
                'name'     => 'Blog API',
                'base_url' => 'https://api.blog.test/v1',
                'auth'     => ['token_env' => 'RESTADMIN_BLOG_TOKEN'],
            ],
            'resources' => [
                [
                    'name'      => 'posts',
                    'path'      => '/posts',
                    'item_path' => '/posts/{id}',
                    'actions'   => ['list', 'read', 'create'],
                    'fields'    => [
                        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                        ['name' => 'published_at', 'label' => 'Published', 'type' => 'date', 'in_list' => false],
                    ],
                    'relations' => [
                        ['name' => 'author', 'kind' => 'belongs_to', 'target' => 'authors', 'foreign_key' => 'author_id'],
                    ],
                ],
                [
                    'name'      => 'authors',
                    'path'      => '/authors',
                    'item_path' => '/authors/{id}',
                    'actions'   => ['list', 'read'],
                    'fields'    => [['name' => 'name', 'label' => 'Name', 'type' => 'text']],
                ],
            ],
        ]);
    }
}
