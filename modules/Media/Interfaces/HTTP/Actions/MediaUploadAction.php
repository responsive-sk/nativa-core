<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\PageCache\Application\Services\CacheInvalidator;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaServices;
use Modules\Media\Interfaces\HTTP\MediaUploadLimits;
use Modules\Media\Interfaces\HTTP\MediaUploadPayload;

/**
 * Media upload — GET/POST /mark/media/upload.
 *
 * GET: renders a file upload form. POST: validates and stores the file
 * via LocalStorageProvider, records metadata in media.db, and logs
 * the action to the audit log.
 */
#[Route(path: '/mark/media/upload', method: 'GET|POST', name: 'media.upload')]
final class MediaUploadAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUpload($request);
        }

        return Response::html($this->uploadForm());
    }

    /**
     * 413 response for a POST body dropped by PHP before it reached the
     * route (post_max_size exceeded) — $_FILES and $_POST are both empty
     * in that state, which distinguishes it from a missing file selection.
     */
    private function droppedBodyResponse(): Response
    {
        return Response::html($this->uploadForm([
            'Upload failed: the file exceeds the server upload limit ('
            . MediaUploadLimits::label() . ').',
        ]), 413);
    }

    private function handleUpload(Request $request): Response
    {
        $rawFile = MediaUploadPayload::files()['file'] ?? null;

        if (!is_array($rawFile)) {
            // Empty $_POST alongside a missing file means the request body
            // never arrived — PHP dropped it (post_max_size), not the user.
            if ([] === $request->getBodyParams()) {
                return $this->droppedBodyResponse();
            }

            return Response::html($this->uploadForm(['No file selected.']), 400);
        }

        /** @var array<string, mixed> $file */
        $file = $rawFile;

        try {
            $auth = AuditServices::auth();
            $userId = $auth->currentUserId();
            $actorName = null !== $userId ? ($auth->currentUser()?->name() ?? 'admin') : 'admin';

            $provider = $request->body('provider');
            $folder = $request->body('folder');
            $asset = MediaServices::media()->upload(
                $file,
                $actorName,
                is_string($provider) ? $provider : '',
                is_string($folder) ? $folder : '',
            );

            CacheInvalidator::flush();
            AuditServices::log(
                'media.upload',
                'media',
                'Uploaded: ' . $asset->originalName() . ' (' . $this->formatSize($asset->size()) . ')',
                $request->getClientIp(),
            );

            return Response::redirect($this->url('media.index'));
        } catch (\RuntimeException $e) {
            return Response::html($this->uploadForm([$e->getMessage()]), 400);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function uploadForm(array $errors = []): string
    {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $csrf = AdminLayout::csrfField();
        $cloudinaryConfigured = '' !== (Env::get('CLOUDINARY_CLOUD_NAME', '') ?? '');

        $providerHtml = '';
        if ($cloudinaryConfigured) {
            $providerHtml = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-upload-provider.phtml', []);
        } else {
            $providerHtml = '<input type="hidden" name="provider" value="local">';
        }

        $folderOptions = $this->localFolderOptions();
        $content = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-upload-form.phtml', ['url' => $this->urlBuilder(), 'csrf' => $csrf, 'errorHtml' => $errorHtml, 'providerHtml' => $providerHtml, 'folderOptions' => $folderOptions, 'uploadLimits' => MediaUploadLimits::formConfig(), 'serverLimitLabel' => MediaUploadLimits::iniLabel('upload_max_filesize')]);

        return AdminLayout::render('Upload media', $content, 'media', router: $this->router());
    }

    private function localFolderOptions(): string
    {
        $options = '';
        foreach (MediaServices::media()->getFolders('local') as $folder) {
            $escaped = AdminLayout::e($folder);
            $options .= '<option value="' . $escaped . '">';
        }

        return $options;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
