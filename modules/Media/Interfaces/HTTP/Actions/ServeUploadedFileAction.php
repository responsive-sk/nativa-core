<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Core\Application\Services\DisposalRegistry;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ErrorPageRenderer;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * ServeUploadedFileAction — read-only serving of stored uploads at
 * /storage/uploads/{path*}.
 *
 * Media owns uploads (the kernel deleted its copy in Boot 7), so this is the
 * route that makes every `/storage/uploads/...` URL the rest of the app
 * emits — author avatars, article images, the media picker — answer with the
 * file instead of the generic page 404.
 *
 * The guards are the acceptance criteria: no dotfile basenames, no `..`
 * segments, no null bytes, realpath containment inside storage/uploads, and
 * the extension/MIME whitelist from LocalStorageProvider::isAllowed().
 * Any violation → 404. Content-Type is detected server-side (never trusted
 * from the client) and filenames are immutable random hex, so responses
 * carry a long-lived cache header. The body is emitted verbatim — files are
 * never executed.
 *
 * The hit count is registered on the DisposalRegistry rather than written
 * here: the response is `immutable`, and an UPDATE in front of it would turn
 * a static file into a write transaction on every uncached page load.
 * Running after the body is built also means a locked media.db can never
 * fail a file download. Cloudinary-hosted assets do not come through this
 * route and are therefore never counted.
 */
#[Route(path: '/storage/uploads/{path*}', method: 'GET', name: 'storage.uploads')]
final class ServeUploadedFileAction
{
    public function handle(Request $request): Response
    {
        $relative = (string) ($request->routeParam('path') ?? '');

        if (!$this->isSafePath($relative)) {
            return $this->notFound();
        }

        $full = AppPaths::instance()->storage('uploads' . DIRECTORY_SEPARATOR . $relative);
        $real = realpath($full);
        $base = realpath(AppPaths::instance()->storage('uploads'));

        // Defense-in-depth: the lexical checks above plus realpath containment
        // (a symlink inside uploads pointing outside must be rejected).
        if (false === $real || false === $base
            || !is_file($real)
            || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
        ) {
            return $this->notFound();
        }

        $storage = new LocalStorageProvider();
        if (!$storage->isAllowed($relative)) {
            return $this->notFound();
        }

        $body = file_get_contents($real);
        if (!is_string($body)) {
            return $this->notFound();
        }

        $response = new Response($body);
        $response->setHeader('Content-Type', $this->mimeOf($real));
        $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');

        $this->countHit($relative);

        return $response;
    }

    /**
     * Count the hit after the response is built, never before it.
     *
     * DisposalRegistry runs every callback and swallows failures, so a
     * missing or locked media.db cannot turn a served file into an error.
     * The path is used deliberately: an untracked file simply matches no row
     * and nothing is counted.
     */
    private function countHit(string $relative): void
    {
        DisposalRegistry::register(
            'media.view:' . $relative,
            static function () use ($relative): void {
                MediaServices::media()->recordView($relative);
            },
        );
    }

    /**
     * Lexical path guard — no empty path, null bytes or `..` segments, and
     * the basename must not be a dotfile.
     */
    private function isSafePath(string $relative): bool
    {
        if ('' === $relative || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return false;
        }

        $name = basename($relative);

        return '' !== $name && !str_starts_with($name, '.');
    }

    /**
     * Styled 404 — shared ErrorPageRenderer so file-serving rejections look
     * identical to every other 404 in the app (bare HTML fallback when the
     * layout shell cannot render, e.g. SQLite down).
     */
    private function notFound(): Response
    {
        return ErrorPageRenderer::response(404, 'Not Found', 'The requested file could not be found.');
    }

    /**
     * Server-side MIME detection — never trusts the client or the filename.
     */
    private function mimeOf(string $file): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);

        return false !== $mime ? $mime : 'application/octet-stream';
    }
}
