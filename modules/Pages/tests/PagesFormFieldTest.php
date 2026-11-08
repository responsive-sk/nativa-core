<?php

declare(strict_types = 1);

namespace Modules\Pages\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Kernel\Contracts\RouterInterface;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Pages\Interfaces\HTTP\Actions\PagesCreateAction;
use Modules\Pages\Interfaces\HTTP\Actions\PagesEditAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PagesFormFieldTest — the page editor owns `form:` as a string.
 *
 * Pages does not resolve the slug against the Forms module: the block decides
 * at render time whether the form is published, and that is what keeps
 * `Pages -> Forms` out of the closure (so Forms may depend on Pages for its
 * return resolver without a cycle).
 *
 * Three behaviours are pinned: the round trip, the refusal/removal pair, and
 * the unrelated edit that must not detach a page's form.
 */
final class PagesFormFieldTest extends TestCase
{
    private RouterInterface $router;

    /** @var list<string> */
    private array $slugs = [];

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Pages')) {
            $this->markTestSkipped('Pages is not installed.');
        }

        $this->router = new class implements RouterInterface {
            public function match(Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return match ($name) {
                    'pages.index' => '/mark/pages',
                    'pages.create' => '/mark/pages/create',
                    'pages.edit' => '/mark/pages/' . (string) ($params['slug'] ?? '') . '/edit',
                    default => '/mark/stub/' . $name,
                };
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->slugs as $slug) {
            $file = APP_BASE_PATH . '/storage/pages/' . $slug . '.md';

            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, string> $body
     */
    private static function request(string $method, array $body, array $query = [], ?string $slug = null): Request
    {
        $request = Request::create($method, '/mark/pages', $query, $body);
        $request->setAttribute('locale', 'sk_SK');

        if (null !== $slug) {
            $request->setRouteParams(['slug' => $slug]);
        }

        return $request;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function body(array $overrides = []): array
    {
        return [
            'locale'          => 'sk',
            'title'           => 'About us',
            'slug'            => 'about-forms-field-test',
            'translation'     => '',
            'seo_title'       => '',
            'seo_description' => '',
            'image'           => '',
            'published'       => '1',
            'body'            => 'Body',
            ...$overrides,
        ];
    }

    private function slug(): string
    {
        return 'about-forms-field-test';
    }

    private function create(string $form = 'contact'): int
    {
        $this->slugs[] = $this->slug();

        return (new PagesCreateAction($this->router))->handle(
            self::request('POST', self::body(['form' => $form])),
        )->getStatusCode();
    }

    #[Test]
    public function theSlugRoundTripsThroughTheEditor(): void
    {
        self::assertSame(302, $this->create('contact'));

        $page = (new PageCrudService())->find($this->slug(), 'sk_SK');

        self::assertSame('contact', $page['meta']['form'] ?? null);

        $edit = (new PagesEditAction($this->router))->handle(
            self::request('GET', [], ['locale' => 'sk'], $this->slug()),
        );

        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('name="form" value="contact"', $edit->getContent());
    }

    #[Test]
    public function anInvalidSlugIsRefusedAndAnEmptyOneRemovesTheKey(): void
    {
        $this->create('contact');

        $refused = (new PagesEditAction($this->router))->handle(
            self::request('POST', self::body(['form' => 'Contact us!']), ['locale' => 'sk'], $this->slug()),
        );

        self::assertSame(400, $refused->getStatusCode());
        self::assertStringContainsString('form slug must be lowercase', $refused->getContent());
        self::assertSame('contact', (new PageCrudService())->find($this->slug(), 'sk_SK')['meta']['form'] ?? null);

        $removed = (new PagesEditAction($this->router))->handle(
            self::request('POST', self::body(['form' => '']), ['locale' => 'sk'], $this->slug()),
        );

        self::assertSame(302, $removed->getStatusCode());
        self::assertArrayNotHasKey('form', (new PageCrudService())->find($this->slug(), 'sk_SK')['meta']);
    }

    #[Test]
    public function anEditWithoutTheFieldKeepsIt(): void
    {
        $this->create('contact');

        $data = self::body();
        unset($data['form']);

        $response = (new PagesEditAction($this->router))->handle(
            self::request('POST', $data, ['locale' => 'sk'], $this->slug()),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('contact', (new PageCrudService())->find($this->slug(), 'sk_SK')['meta']['form'] ?? null);
    }
}
