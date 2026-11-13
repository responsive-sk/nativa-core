<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\RestAdmin\Application\Spec\SpecRepository;
use Modules\RestAdmin\Application\Spec\SpecValidator;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Infrastructure\Persistence\Migrations\RestAdminDatabaseMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SpecRepositoryTest — the decomposed store, against a real SQLite file.
 *
 * The tests run through the module's own migrator and the kernel's connection
 * manager (`DB_RESTADMIN` → a temp file), because the point is that the rows
 * survive a restart, not that an in-memory fake says so.
 *
 * The interesting cases are the ones a naive upsert gets wrong:
 *
 * - saving the same service twice must REPLACE its fields, not accumulate
 *   them (a removed field that survives is a column nobody can delete);
 * - a failed save must leave the previous specification whole, which is why
 *   children are deleted and re-inserted inside one transaction;
 * - a duplicate service name is the database's rule, and the failure must not
 *   take the first service's rows with it.
 */
final class SpecRepositoryTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    private string $tmpDir = '';

    private ?string $envBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }

        $before = getenv('DB_RESTADMIN');
        $this->envBefore = false === $before ? null : $before;

        $this->tmpDir = sys_get_temp_dir() . '/nativa-restadmin-repo-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        $database = $this->tmpDir . '/restadmin.db';
        putenv('DB_RESTADMIN=' . $database);
        DatabaseConnectionManager::resetInstance();

        (new RestAdminDatabaseMigrator(new PDO('sqlite:' . $database)))->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            putenv(null === $this->envBefore ? 'DB_RESTADMIN' : 'DB_RESTADMIN=' . $this->envBefore);
            DatabaseConnectionManager::resetInstance();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function aSavedSpecificationComesBackWhole(): void
    {
        $spec = $this->spec();
        $this->repository()->save($spec);

        $loaded = $this->repository()->find('blog');

        self::assertInstanceOf(ServiceSpec::class, $loaded);
        self::assertSame($spec->toArray(), $loaded->toArray());
        self::assertSame('blog', $loaded->resources[0]->serviceId);
        self::assertCount(2, $loaded->resources);
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM fields'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM relations'));
    }

    #[Test]
    public function fieldsKeepTheOrderTheSpecificationListsThem(): void
    {
        $this->repository()->save($this->spec());

        $loaded = $this->repository()->find('blog');

        self::assertNotNull($loaded);
        self::assertSame(['title', 'published_at'], array_map(
            static fn ($field): string => $field->name,
            $loaded->resources[0]->fields,
        ));
    }

    #[Test]
    public function savingAgainReplacesTheChildrenInsteadOfAccumulatingThem(): void
    {
        $repository = $this->repository();
        $repository->save($this->spec());

        $smaller = (new SpecValidator())->validate([
            'service'   => [
                'id'       => 'blog',
                'name'     => 'Blog API',
                'base_url' => 'https://api.blog.test/v1',
            ],
            'resources' => [
                [
                    'name'      => 'posts',
                    'path'      => '/posts',
                    'item_path' => '/posts/{id}',
                    'fields'    => [['name' => 'title', 'label' => 'Title', 'type' => 'text']],
                ],
            ],
        ]);

        $repository->save($smaller);

        $loaded = $repository->find('blog');

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->resources);
        self::assertCount(1, $loaded->resources[0]->fields);
        self::assertSame([], $loaded->resources[0]->relations);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM resources'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM relations'));
    }

    #[Test]
    public function theServiceListIsAlphabetical(): void
    {
        $repository = $this->repository();
        $repository->save($this->spec());
        $repository->save($this->shopSpec());

        $services = $repository->listServices();

        self::assertSame(['Blog API', 'Shop API'], array_column($services, 'name'));
        self::assertSame('https://api.shop.test', $services[1]['base_url']);
        self::assertCount(2, $repository->all());
    }

    #[Test]
    public function anUnknownServiceIsNull(): void
    {
        self::assertNull($this->repository()->find('missing'));
    }

    #[Test]
    public function deletingAServiceRemovesItsResourcesFieldsAndRelations(): void
    {
        $repository = $this->repository();
        $repository->save($this->spec());

        self::assertTrue($repository->delete('blog'));
        self::assertFalse($repository->delete('blog'));
        self::assertNull($repository->find('blog'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM services'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM resources'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM fields'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM relations'));
    }

    /**
     * A duplicate service NAME is the database's rule; the store must refuse
     * it without touching the rows of the service that already owns the name.
     */
    #[Test]
    public function aRefusedSaveLeavesTheExistingSpecificationWhole(): void
    {
        $repository = $this->repository();
        $repository->save($this->spec());

        $refused = false;

        try {
            $repository->save($this->shopSpec(name: 'Blog API'));
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertTrue($refused, 'UNIQUE(name) must refuse the second service with the same name.');
        self::assertNotNull($repository->find('blog'));
        self::assertNull($repository->find('shop'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM services'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM resources'));
    }

    /**
     * The failure is INSIDE the replace: the children are deleted first, then
     * one insert violates UNIQUE(service_id, name) — so a rollback is the only
     * thing that brings the previous resources back.
     */
    #[Test]
    public function aFailureInsideTheReplaceRollsBackTheDeletedChildren(): void
    {
        $repository = $this->repository();
        $repository->save($this->spec());

        $published = $repository->find('blog');
        self::assertNotNull($published);

        $duplicate = new ServiceSpec($published->service, [
            $published->resources[0],
            new ResourceSpec(
                serviceId: 'blog',
                name: $published->resources[0]->name,
                path: '/other-posts',
                itemPath: '/other-posts/{id}',
            ),
        ]);

        $refused = false;

        try {
            $repository->save($duplicate);
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertTrue($refused, 'Duplicate resource names must be refused by the store.');
        $stillThere = $repository->find('blog');

        self::assertNotNull($stillThere);
        self::assertCount(2, $stillThere->resources);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM resources'));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM fields'));
    }

    private function repository(): SpecRepository
    {
        return new SpecRepository(DatabaseConnectionManager::instance()->connect('restadmin'));
    }

    private function scalar(string $sql): int
    {
        $pdo = DatabaseConnectionManager::instance()->connection('restadmin');
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function quietOutput(): CliOutput
    {
        return new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
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

    private function shopSpec(string $name = 'Shop API'): ServiceSpec
    {
        return (new SpecValidator())->validate([
            'service'   => [
                'id'       => 'shop',
                'name'     => $name,
                'base_url' => 'https://api.shop.test',
            ],
            'resources' => [],
        ]);
    }
}
