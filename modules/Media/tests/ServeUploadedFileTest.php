<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Media\Interfaces\HTTP\Actions\ServeUploadedFileAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ServeUploadedFileTest — /storage/uploads/{path*} actually answers.
 *
 * Every avatar, article image and media-picker URL is emitted as a
 * `/storage/uploads/...` path, but the kernel deleted its serving action in
 * Boot 7 and Media never picked the route up. The result was a file that
 * exists on disk and a URL that answers the generic page 404.
 *
 * The whitelist is the point: a file is served only when its server-detected
 * MIME matches its extension, and traversal/dotfile paths never reach the
 * filesystem. `MediaService` is only touched from the DisposalRegistry
 * callback, which this test does not run, so no media.db is needed.
 */
final class ServeUploadedFileTest extends TestCase
{
    /** @var list<string> files this test created, removed in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the uploads route ships with this module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function anAllowedUploadIsServedWithItsDetectedType(): void
    {
        $relative = 'tests/serve-' . bin2hex(random_bytes(8)) . '.png';
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($bytes);

        $full = AppPaths::instance()->storage('uploads/' . $relative);
        AppPaths::instance()->ensureDir(dirname($full));
        file_put_contents($full, $bytes);
        $this->created[] = $full;

        $request = Request::create('GET', '/storage/uploads/' . $relative);
        $request->setRouteParams(['path' => $relative]);

        $response = (new ServeUploadedFileAction())->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeader('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeader('Cache-Control'));
        $this->assertSame($bytes, $response->getContent());
    }

    #[Test]
    public function aMissingFileIsNotFound(): void
    {
        $relative = 'tests/missing-' . bin2hex(random_bytes(8)) . '.png';
        $request = Request::create('GET', '/storage/uploads/' . $relative);
        $request->setRouteParams(['path' => $relative]);

        $response = (new ServeUploadedFileAction())->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function traversalIsRejectedBeforeTheFilesystemIsRead(): void
    {
        $request = Request::create('GET', '/storage/uploads/../.env');
        $request->setRouteParams(['path' => '../.env']);

        $response = (new ServeUploadedFileAction())->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
