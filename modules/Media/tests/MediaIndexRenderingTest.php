<?php

declare(strict_types = 1);

namespace Modules\Media\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Infrastructure\Persistence\Migrations\MediaDatabaseMigrator;
use Modules\Media\Interfaces\HTTP\Actions\MediaIndexAction;
use Modules\Media\Interfaces\HTTP\MediaServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MediaIndexRenderingTest — GET /mark/media renders, filter bar and all.
 *
 * The reported bug: the index answered 500 with `Using $this when not in
 * object context` the moment a filter pill rendered, because
 * `buildFilterUrl()` was declared static while resolving its href through
 * `$this->url('media.index')`. The suite was green — nothing rendered the
 * page, and `bin/build/admin-smoke.sh` never walks media — so the panel was
 * the first thing to notice.
 *
 * Nothing here asserts the styling of a pill. It asserts that the page is
 * RENDERED: the filter bar, the folder control and the pagination helper all
 * run, which is exactly the code the fatal lived in. The `HX-Request` header
 * keeps AdminLayout out of the picture (`handle()` then returns the bare
 * `#media-content` fragment), so the test needs no shell context and no theme
 * while still executing every filter-link path.
 *
 * ## The database
 *
 * A temp file through `DB_MEDIA`, schema built by the Media migrator itself —
 * the pattern SettingsShellContextProviderTest uses for `menu_items`. The
 * working tree's `storage/data/media.db` is never opened. Uploads are read
 * through the real local provider but the test writes nothing to them.
 *
 * ## Why it lives here and not under `tests/`
 *
 * Media is a module. `tests/` is inside PHPStan's scope and `modules/` is not,
 * so a test naming an optional module's class belongs beside the module (see
 * phpunit.xml). A kernel-only checkout skips it.
 */
final class MediaIndexRenderingTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            $this->markTestSkipped('Media is not installed — the media index ships with this module.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-media-index-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_MEDIA=' . $this->tmpDir . '/media.db');
        DatabaseConnectionManager::resetInstance();
        MediaServices::reset();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/media.db');
        (new MediaDatabaseMigrator($pdo))->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped, so nothing here
        // assumes the module is installed or the tmp dir exists.
        if (is_dir(APP_BASE_PATH . '/src/Modules/Media')) {
            putenv('DB_MEDIA');
            DatabaseConnectionManager::resetInstance();
            MediaServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }
    }

    /**
     * The filters are links the page builds itself. An empty library still
     * renders every pill, so the fatal fired on the first visit — not only
     * once an upload existed.
     */
    #[Test]
    public function theIndexRendersItsFilterBar(): void
    {
        $response = $this->action()->handle($this->htmxRequest('/mark/media'));

        self::assertSame(200, $response->getStatusCode());

        $html = $response->getContent();

        self::assertStringContainsString('id="media-content"', $html);
        self::assertStringContainsString('href="/mark/media?type=image"', $html, 'A type pill must carry its filter as a real href.');
        self::assertStringContainsString('href="/mark/media?source=local"', $html, 'A source pill must resolve through the router.');
        self::assertStringContainsString('name="folder"', $html, 'The folder control resolves its hx-get through the router too.');
    }

    /**
     * The same paths with filters already applied — the state the failing
     * request actually carried (`?type=…`, `?source=…`, `?sort=…`).
     */
    #[Test]
    public function everyFilterCombinationRenders(): void
    {
        foreach ([
            '/mark/media?type=image',
            '/mark/media?source=local',
            '/mark/media?sort=name_asc',
            '/mark/media?type=image&source=local&sort=name_asc&folder=none',
        ] as $path) {
            $response = $this->action()->handle($this->htmxRequest($path));

            self::assertSame(200, $response->getStatusCode(), $path . ' must render, not 500.');
            self::assertStringContainsString('name="sort"', $response->getContent(), $path . ' lost the filter bar.');
        }
    }

    /**
     * A folder tile carries one image from the folder, so the grid answers
     * "what is in there" before the folder is opened.
     */
    #[Test]
    public function aFolderTileCarriesAPreviewImage(): void
    {
        MediaServices::repository()->insert(MediaAsset::create(
            filename: 'cover.png',
            originalName: 'cover.png',
            path: 'photos/cover.png',
            size: 100,
            mimeType: 'image/png',
            uploadedBy: 'test',
            provider: 'local',
            folder: 'photos',
        ));

        $response = $this->action()->handle($this->htmxRequest('/mark/media'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('class="m-folder-card__thumb"', $response->getContent());
        self::assertStringContainsString('photos/cover.png', $response->getContent());
    }

    private function action(): MediaIndexAction
    {
        $router = Router::fromEntries([], [
            ['path' => '/mark/media', 'method' => 'GET', 'name' => 'media.index', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            ['path' => '/mark/media/upload', 'method' => 'GET', 'name' => 'media.upload', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            // A rendered card links to its edit page, so the tile test needs
            // the name a card resolves.
            ['path' => '/mark/media/{id}/edit', 'method' => 'GET|POST', 'name' => 'media.edit', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            ['path' => '/mark/media/{id}/delete', 'method' => 'POST', 'name' => 'media.delete', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            // The select-all script posts a batch delete by name too.
            ['path' => '/mark/media/batch-delete', 'method' => 'POST', 'name' => 'media.batch-delete', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            // The folder manager is part of the same page: its forms resolve
            // these names, so the router has to know them too.
            ['path' => '/mark/media/folders/create', 'method' => 'POST', 'name' => 'media.folder-create', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            ['path' => '/mark/media/folders/rename', 'method' => 'POST', 'name' => 'media.folder-rename', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
            ['path' => '/mark/media/folders/delete', 'method' => 'POST', 'name' => 'media.folder-delete', 'handlerMethod' => null, 'action' => MediaIndexAction::class],
        ]);

        return new MediaIndexAction($router);
    }

    private function htmxRequest(string $path): Request
    {
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return Request::create(
            'GET',
            (string) parse_url($path, PHP_URL_PATH),
            $query,
            headers: ['hx-request' => 'true'],
        );
    }

    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
