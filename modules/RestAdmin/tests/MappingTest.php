<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Tests;

use Modules\RestAdmin\Application\Mapping\Envelope;
use Modules\RestAdmin\Application\Mapping\FieldMapper;
use Modules\RestAdmin\Application\Mapping\MappingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MappingTest — the envelope vocabulary and the null-versus-missing rule.
 *
 * The three list wrappers are one meaning (`data`, `items`, `results`), and a
 * bare list is the same meaning again; the test pins that a detail object under
 * `data` is NOT a collection, because a reader that confused them would render
 * one row's fields as a one-row table.
 *
 * The field cases are the ones a form depends on: a dotted path reads a nested
 * object, an explicit null is present, a missing key is absent, and a JSON:API
 * row reads through `attributes` without losing `id`.
 */
final class MappingTest extends TestCase
{
    private const string MODULE = 'RestAdmin';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/' . self::MODULE)) {
            $this->markTestSkipped(self::MODULE . ' is not installed — its slice ships with the module.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listKeys(): iterable
    {
        yield 'data' => ['data'];
        yield 'items' => ['items'];
        yield 'results' => ['results'];
    }

    #[Test]
    #[DataProvider('listKeys')]
    public function everyWrapperMeansTheSameCollection(string $key): void
    {
        $items = (new Envelope())->items([$key => [['id' => 1], ['id' => 2]]]);

        self::assertCount(2, $items);
        self::assertSame(2, $items[1]['id']);
    }

    #[Test]
    public function aBareListIsACollection(): void
    {
        $items = (new Envelope())->items([['id' => 1]]);

        self::assertSame([['id' => 1]], $items);
    }

    #[Test]
    public function anEmptyBareListIsAnEmptyCollection(): void
    {
        self::assertSame([], (new Envelope())->items([]));
    }

    #[Test]
    public function anAnswerWithoutACollectionIsRefused(): void
    {
        $this->expectException(MappingException::class);

        (new Envelope())->items(['meta' => ['total' => 0]]);
    }

    #[Test]
    public function aCollectionOfScalarsIsRefused(): void
    {
        $this->expectException(MappingException::class);

        (new Envelope())->items(['data' => ['one', 'two']]);
    }

    #[Test]
    public function aDetailObjectIsUnwrappedFromData(): void
    {
        self::assertSame(['id' => 1, 'title' => 'Hello'], (new Envelope())->item(['data' => ['id' => 1, 'title' => 'Hello']]));
    }

    #[Test]
    public function anUnwrappedObjectIsItsOwnItem(): void
    {
        self::assertSame(['id' => 1], (new Envelope())->item(['id' => 1]));
    }

    #[Test]
    public function aListIsNotADetailAnswer(): void
    {
        $this->expectException(MappingException::class);

        (new Envelope())->item([['id' => 1]]);
    }

    #[Test]
    public function aDottedPathReadsANestedObject(): void
    {
        $mapper = new FieldMapper();
        $row = ['author' => ['name' => 'Ada', 'id' => 7]];

        self::assertSame('Ada', $mapper->read($row, 'author.name'));
        self::assertSame(7, $mapper->read($row, 'author.id'));
    }

    #[Test]
    public function anExplicitNullIsPresentAndAMissingKeyIsNot(): void
    {
        $mapper = new FieldMapper();
        $row = ['title' => 'Hello', 'published_at' => null];

        self::assertTrue($mapper->has($row, 'published_at'));
        self::assertNull($mapper->read($row, 'published_at'));
        self::assertFalse($mapper->has($row, 'body'));
        self::assertNull($mapper->read($row, 'body'));
    }

    #[Test]
    public function mapOmitsAbsentFieldsInsteadOfInventingNulls(): void
    {
        $mapper = new FieldMapper();

        self::assertSame(
            ['title' => 'Hello', 'published_at' => null],
            $mapper->map(['title' => 'Hello', 'published_at' => null], ['title', 'published_at', 'body']),
        );
    }

    #[Test]
    public function aJsonApiRowReadsThroughAttributesAndKeepsItsId(): void
    {
        $row = $this->payload('blog-item-jsonapi.json')['data'];
        self::assertIsArray($row);

        $mapper = new FieldMapper();

        self::assertSame('Hello, Nativa', $mapper->read($row, 'title'));
        self::assertTrue($mapper->has($row, 'published_at'));
        self::assertSame('1', $row['id']);
        self::assertSame('posts', $row['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/' . $name),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }
}
