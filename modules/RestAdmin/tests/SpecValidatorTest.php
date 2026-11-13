<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Spec\SpecValidationException;
use Modules\RestAdmin\Application\Spec\SpecValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SpecValidatorTest — a document is refused by name, before anything is stored.
 *
 * The rules are not style preferences; each one prevents a specific silent
 * failure, and the table is grouped by that failure:
 *
 * - a typo in a key (`timout_seconds`, `lable`) would leave the operator
 *   believing a value they never set;
 * - a service id that is not a file name would escape the snapshot directory;
 * - a base URL with credentials or a query would put a secret in every log
 *   line or append it to every path;
 * - a path with `{slug}`, `..` or a full URL would leave the service or go
 *   nowhere;
 * - an unknown action or field type would be stored and render as nothing;
 * - a relation cycle has no end in a v1 that resolves nested relations.
 *
 * The default document is intentionally minimal in the places that matter:
 * `id_field`, `in_list`, `timeout_seconds` and the auth header are absent, so
 * the defaults are asserted rather than implied.
 */
final class SpecValidatorTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    #[Test]
    public function aDocumentBecomesAValidatedAggregate(): void
    {
        $spec = (new SpecValidator())->validate($this->document());

        self::assertSame('blog', $spec->service->id);
        self::assertSame('https://api.blog.test/v1', $spec->service->baseUrl);
        self::assertSame('Authorization', $spec->service->authHeader());
        self::assertSame('RESTADMIN_BLOG_TOKEN', $spec->service->tokenEnv());
        self::assertSame(10, $spec->service->timeoutSeconds);
        self::assertCount(2, $spec->resources);
        self::assertSame(['list', 'read', 'create'], $spec->resources[0]->actions->toArray());
        self::assertTrue($spec->resources[0]->actions->writes());
        self::assertFalse($spec->resources[1]->actions->writes());
        self::assertSame('page', $spec->resources[0]->pagination->strategy);
        self::assertSame('id', $spec->resources[0]->idField);
        self::assertSame('date', $spec->resources[0]->fields[1]->type->value);
        self::assertFalse($spec->resources[0]->fields[1]->inList);
        self::assertSame('authors', $spec->resources[0]->relations[0]->target);
    }

    #[Test]
    public function theDefaultActionSetIsReadOnly(): void
    {
        $spec = (new SpecValidator())->validate([
            'service'   => ['id' => 'blog', 'name' => 'Blog API', 'base_url' => 'https://api.blog.test'],
            'resources' => [['name' => 'posts', 'path' => '/posts', 'item_path' => '/posts/{id}']],
        ]);

        self::assertSame(['list', 'read'], $spec->resources[0]->actions->toArray());
        self::assertFalse($spec->resources[0]->actions->writes());
        self::assertSame('page', $spec->resources[0]->pagination->strategy);
        self::assertSame('id', $spec->resources[0]->idField);
        self::assertSame([], $spec->resources[0]->fields);
        self::assertSame([], $spec->resources[0]->relations);
        self::assertFalse($spec->service->auth->isConfigured());
        self::assertNull($spec->service->authHeader());
    }

    /**
     * @return iterable<string, array{string, \Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function unknownKeys(): iterable
    {
        yield 'document' => ['document.extra', static function (array $document): array {
            $document['extra'] = 1;

            return $document;
        }];

        yield 'service' => ['service.timout_seconds', static function (array $document): array {
            $document['service']['timout_seconds'] = 5;

            return $document;
        }];

        yield 'resource' => ['resources[0].extra', static function (array $document): array {
            $document['resources'][0]['extra'] = true;

            return $document;
        }];

        yield 'field' => ['resources[0].fields[0].lable', static function (array $document): array {
            $document['resources'][0]['fields'][0]['lable'] = 'Title';

            return $document;
        }];

        yield 'relation' => ['resources[0].relations[0].targt', static function (array $document): array {
            $document['resources'][0]['relations'][0]['targt'] = 'authors';

            return $document;
        }];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[Test]
    #[DataProvider('unknownKeys')]
    public function anUnknownKeyIsNamedRatherThanIgnored(string $field, \Closure $mutate): void
    {
        $this->assertRejected($field, $mutate($this->document()));
    }

    #[Test]
    public function aDocumentMustBeAnObject(): void
    {
        $this->assertRejected('document', []);
        $this->assertRejected('document', 'a specification');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeServiceIds(): iterable
    {
        yield 'space' => ['blog api'];
        yield 'traversal' => ['../blog'];
        yield 'upper case' => ['Blog'];
        yield 'over 32 characters' => [str_repeat('a', 33)];
    }

    #[Test]
    #[DataProvider('unsafeServiceIds')]
    public function theServiceIdIsASafeSnapshotFileName(string $id): void
    {
        $document = $this->document();
        $document['service']['id'] = $id;

        $this->assertRejected('service.id', $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBaseUrls(): iterable
    {
        yield 'relative' => ['/v1'];
        yield 'ftp' => ['ftp://api.blog.test'];
        yield 'query' => ['https://api.blog.test/v1?token=abc'];
        yield 'fragment' => ['https://api.blog.test/v1#posts'];
        yield 'credentials' => ['https://user:secret@api.blog.test'];
        yield 'no host' => ['https:///v1'];
    }

    #[Test]
    #[DataProvider('unusableBaseUrls')]
    public function theBaseUrlIsAbsoluteAndCredentialFree(string $url): void
    {
        $document = $this->document();
        $document['service']['base_url'] = $url;

        $this->assertRejected('service.base_url', $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableResourcePaths(): iterable
    {
        yield 'full URL' => ['https://evil.test/posts'];
        yield 'relative' => ['posts'];
        yield 'query' => ['/posts?limit=1'];
        yield 'dot dot' => ['/posts/../admin'];
    }

    #[Test]
    #[DataProvider('unusableResourcePaths')]
    public function aResourcePathStaysRelativeToTheBaseUrl(string $path): void
    {
        $document = $this->document();
        $document['resources'][0]['path'] = $path;

        $this->assertRejected('resources[0].path', $document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableItemPaths(): iterable
    {
        yield 'missing placeholder' => ['/posts'];
        yield 'other placeholder' => ['/posts/{postId}'];
        yield 'two placeholders' => ['/posts/{id}/{id}'];
        yield 'query' => ['/posts/{id}?draft=1'];
    }

    #[Test]
    #[DataProvider('unusableItemPaths')]
    public function theItemPathNeedsExactlyOneIdPlaceholder(string $path): void
    {
        $document = $this->document();
        $document['resources'][0]['item_path'] = $path;

        $this->assertRejected('resources[0].item_path', $document);
    }

    #[Test]
    public function anUnfilledPlaceholderIsNamedInTheMessage(): void
    {
        $document = $this->document();
        $document['resources'][0]['path'] = '/posts/{slug}';

        try {
            (new SpecValidator())->validate($document);
        } catch (SpecValidationException $exception) {
            self::assertSame('resources[0].path', $exception->field);
            self::assertStringContainsString('{slug}', $exception->getMessage());

            return;
        }

        self::fail('A list path with an unresolved placeholder was accepted.');
    }

    #[Test]
    public function thePaginationStrategyIsTheClosedVocabulary(): void
    {
        $document = $this->document();
        $document['resources'][0]['pagination'] = 'offset';

        $this->assertRejected('resources[0].pagination', $document);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableActionLists(): iterable
    {
        yield 'unknown action' => [['list', 'publish']];
        yield 'a string' => ['list,read'];
        yield 'empty' => [[]];
        yield 'duplicate' => [['list', 'list']];
    }

    #[Test]
    #[DataProvider('unusableActionLists')]
    public function actionsAreAnAllowlistOfKnownVerbs(mixed $actions): void
    {
        $document = $this->document();
        $document['resources'][0]['actions'] = $actions;

        $this->assertRejected('resources[0].actions', $document);
    }

    #[Test]
    public function duplicateFieldNamesAreRefused(): void
    {
        $document = $this->document();
        $document['resources'][0]['fields'][1]['name'] = 'title';

        $this->assertRejected('resources[0].fields[1].name', $document);
    }

    #[Test]
    public function anUnknownFieldTypeIsRefused(): void
    {
        $document = $this->document();
        $document['resources'][0]['fields'][0]['type'] = 'tags';

        $this->assertRejected('resources[0].fields[0].type', $document);
    }

    #[Test]
    public function aRequiredFieldCannotBeReadOnly(): void
    {
        $document = $this->document();
        $document['resources'][0]['fields'][0]['read_only'] = true;

        $this->assertRejected('resources[0].fields[0].required', $document);
    }

    #[Test]
    public function aBooleanIsNotAWord(): void
    {
        $document = $this->document();
        $document['resources'][0]['fields'][0]['required'] = 'yes';

        $this->assertRejected('resources[0].fields[0].required', $document);
    }

    #[Test]
    public function aRelationMustPointAtADefinedResource(): void
    {
        $document = $this->document();
        $document['resources'][0]['relations'][0]['target'] = 'readers';

        $this->assertRejected('resources[0].relations[0].target', $document);
    }

    #[Test]
    public function aRelationKindIsClosedToTheTwoV1Kinds(): void
    {
        $document = $this->document();
        $document['resources'][0]['relations'][0]['kind'] = 'owns';

        $this->assertRejected('resources[0].relations[0].kind', $document);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function cyclicDocuments(): iterable
    {
        yield 'two resources' => [static function (array $document): array {
            $document['resources'][1]['relations'] = [[
                'name'   => 'posts',
                'kind'   => 'has_many',
                'target' => 'posts',
            ]];

            return $document;
        }];

        yield 'self reference' => [static function (array $document): array {
            $document['resources'][0]['relations'][] = [
                'name'   => 'related',
                'kind'   => 'belongs_to',
                'target' => 'posts',
            ];

            return $document;
        }];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[Test]
    #[DataProvider('cyclicDocuments')]
    public function aRelationCycleIsRefused(\Closure $mutate): void
    {
        try {
            (new SpecValidator())->validate($mutate($this->document()));
        } catch (SpecValidationException $exception) {
            self::assertSame('resources', $exception->field);
            self::assertStringContainsString('→', $exception->getMessage());

            return;
        }

        self::fail('A relation cycle was accepted; v1 would recurse forever.');
    }

    #[Test]
    public function resourceNamesAreUniqueRegardlessOfCasing(): void
    {
        $document = $this->document();
        $document['resources'][1]['name'] = 'Posts';

        $this->assertRejected('resources[1].name', $document);
    }

    #[Test]
    public function aTokenEnvironmentIsAVariableNameAndTheHeaderDefaults(): void
    {
        $document = $this->document();
        $document['service']['auth']['token_env'] = 'restadmin_blog_token';

        $this->assertRejected('service.auth.token_env', $document);
    }

    #[Test]
    public function aHeaderWithoutATokenIsRefused(): void
    {
        $document = $this->document();
        $document['service']['auth'] = ['header' => 'X-Api-Key'];

        $this->assertRejected('service.auth.header', $document);
    }

    #[Test]
    public function anAuthHeaderMustBeAHeaderName(): void
    {
        $document = $this->document();
        $document['service']['auth']['header'] = 'Bad Header';

        $this->assertRejected('service.auth.header', $document);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'over two minutes' => [121];
        yield 'a string' => ['10'];
    }

    #[Test]
    #[DataProvider('unusableTimeouts')]
    public function theTimeoutFitsTheTransport(mixed $timeout): void
    {
        $document = $this->document();
        $document['service']['timeout_seconds'] = $timeout;

        $this->assertRejected('service.timeout_seconds', $document);
    }

    #[Test]
    public function headersAreASingleLineMap(): void
    {
        $document = $this->document();
        $document['service']['headers'] = ['X-Service' => "blog\r\nX-Admin: 1"];

        $this->assertRejected('service.headers.X-Service', $document);

        $asList = $this->document();
        $asList['service']['headers'] = ['X-Service'];

        $this->assertRejected('service.headers', $asList);
    }

    #[Test]
    public function resourcesMustBeAList(): void
    {
        $document = $this->document();
        $document['resources'] = ['posts' => $document['resources'][0]];

        $this->assertRejected('resources', $document);
    }

    private function assertRejected(string $field, mixed $document): void
    {
        try {
            (new SpecValidator())->validate($document);
        } catch (SpecValidationException $exception) {
            self::assertSame($field, $exception->field, $exception->getMessage());

            return;
        }

        self::fail(sprintf('The specification was accepted; expected "%s" to be refused.', $field));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'service'   => [
                'id'       => 'blog',
                'name'     => 'Blog API',
                'base_url' => 'https://api.blog.test/v1',
                'auth'     => ['token_env' => 'RESTADMIN_BLOG_TOKEN'],
            ],
            'resources' => [
                [
                    'name'       => 'posts',
                    'path'       => '/posts',
                    'item_path'  => '/posts/{id}',
                    'actions'    => ['list', 'read', 'create'],
                    'pagination' => 'page',
                    'fields'     => [
                        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                        ['name' => 'published_at', 'label' => 'Published', 'type' => 'date', 'in_list' => false],
                    ],
                    'relations'  => [
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
        ];
    }
}
