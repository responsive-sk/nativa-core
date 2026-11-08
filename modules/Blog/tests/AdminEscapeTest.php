<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Interfaces\HTTP\Actions\BlogInlineEditAction;
use Modules\Blog\Interfaces\HTTP\ArticlesTableRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AdminEscapeTest — two escape regressions, pinned.
 *
 * - The articles table re-emits the admin `?locale=` param into row URLs.
 *   The action allowlists it at the boundary, and the renderer escapes it
 *   again at entry (defense in depth for future callers). The table is a
 *   public static, so the test drives it directly with a hostile locale.
 * - The inline-edit HTMX notice printed the `blog.saved` translation RAW —
 *   translator-controlled stored XSS on an admin surface. It goes through
 *   the same e() as its sibling in BlogInlineEditor. The test seeds a
 *   hostile value (snapshotting locales.db first, like TaxonomyRegistryTest
 *   snapshots its JSON registry) and drives the private saved() with a
 *   forged hx-request.
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * modules (or the locales database) are not installed.
 */
final class AdminEscapeTest extends TestCase
{
    private const string EVIL = '"><svg onload=alert(1)>';

    private const string LOCALES_DB = APP_BASE_PATH . '/storage/data/locales.db';

    /** Original `blog.saved` value, or null when the key did not exist. */
    private ?string $savedOriginal = null;

    private bool $savedExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Blog', 'Cardboard', 'Locales'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        if (!is_file(self::LOCALES_DB)) {
            $this->markTestSkipped('locales.db is not installed.');
        }
    }

    protected function tearDown(): void
    {
        // Restore through the normal write path (WAL-safe): file surgery
        // on locales.db breaks other tests' open handles (disk I/O error
        // in the full suite), so the evil row is overwritten/deleted, never
        // restored by overwriting the file.
        if ($this->savedSeeded) {
            if ($this->savedExisted) {
                LocalesServices::translations()->set('blog.saved', 'sk_SK', (string) $this->savedOriginal);
            } else {
                $existing = self::repository()->findByKey('blog.saved', 'sk_SK');

                if (null !== $existing) {
                    self::repository()->delete($existing->id());
                }
            }

            $this->savedSeeded = false;
        }

        parent::tearDown();
    }

    private bool $savedSeeded = false;

    private static function repository(): TranslationRepository
    {
        // Throwaway handle: opened, read, dropped — it never outlives the
        // call, so it cannot disturb other tests' connections.
        $pdo = new \PDO('sqlite:' . self::LOCALES_DB);
        $repo = new TranslationRepository($pdo);
        unset($pdo);

        return $repo;
    }

    #[Test]
    public function tableNeutralizesHostileLocaleAndSlug(): void
    {
        $html = ArticlesTableRenderer::table(
            [[
                'slug'         => 'hello"><svg onload=alert(2)>',
                'title'        => 'Hello',
                'date'         => '2026-10-13',
                'category'     => '',
                'author'       => 'Eva',
                'author_email' => 'eva@example.test',
                'tags'         => [],
                'members_only' => false,
                'published'    => true,
            ]],
            self::EVIL,
            router: self::router(),
        );

        $this->assertStringNotContainsString(self::EVIL, $html);
        $this->assertStringNotContainsString('hello"><svg', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function inlineSaveNoticeEscapesTheTranslation(): void
    {
        $existing = self::repository()->findByKey('blog.saved', 'sk_SK');
        $this->savedExisted = null !== $existing;
        $this->savedOriginal = $existing?->value();
        LocalesServices::translations()->set('blog.saved', 'sk_SK', 'Uložené' . self::EVIL);
        $this->savedSeeded = true;

        $method = new ReflectionMethod(BlogInlineEditAction::class, 'saved');
        $action = (new \ReflectionClass(BlogInlineEditAction::class))->newInstanceWithoutConstructor();
        $request = Request::create('POST', '/blog/hello', [], [], [], ['hx-request' => 'true']);

        /** @var \Modules\Core\Infrastructure\Http\Response $response */
        $response = $method->invoke($action, $request, 'hello', 'sk_SK');
        $html = $response->getContent();

        $this->assertStringNotContainsString(self::EVIL, $html);
        $this->assertStringContainsString('&lt;svg', $html);
    }
    private static function router(): \Modules\Core\Kernel\Contracts\RouterInterface
    {
        return new class implements \Modules\Core\Kernel\Contracts\RouterInterface {
            public function match(\Modules\Core\Infrastructure\Http\Request $request): ?array
            {
                return null;
            }

            public function url(string $name, array $params = []): string
            {
                return '/mark/' . $name;
            }
        };
    }
}

