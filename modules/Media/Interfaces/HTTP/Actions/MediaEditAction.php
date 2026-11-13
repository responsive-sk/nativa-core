<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\PageCache\Application\Services\CacheInvalidator;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Media edit — GET/POST /mark/media/{id}/edit.
 *
 * Edit form for alt text, caption, category, and tags on a media asset.
 */
#[Route(path: '/mark/media/{id}/edit', method: 'GET|POST', name: 'media.edit')]
final class MediaEditAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null === $id || '' === $id) {
            return Response::redirect($this->url('media.index'));
        }

        $service = MediaServices::media();
        $asset = $service->findById($id);

        if (null === $asset) {
            return Response::redirect($this->url('media.index'));
        }

        // Handle POST (save).
        if ($request->isMethod('POST')) {
            return $this->handleSave($request, $service, $id);
        }

        // GET — show form.
        return $this->renderForm($asset);
    }

    private function handleSave(Request $request, MediaService $service, string $id): Response
    {
        $altText = trim($request->bodyString('alt_text'));
        $caption = trim($request->bodyString('caption'));
        $category = trim($request->bodyString('category'));
        $folder = $request->bodyString('folder');
        $rawTags = $request->bodyString('tags');
        $tags = $this->parseTags($rawTags);

        // Update metadata (alt text + caption).
        $service->updateMetadata($id, $altText, $caption);

        // Update category + tags.
        $service->updateCategoryAndTags($id, $category, $tags);

        // Move to a folder (normalised inside the service).
        $service->updateFolder($id, $folder);

        CacheInvalidator::flush();
        AuditServices::log(
            action: 'media.update-metadata',
            target: 'media',
            detail: "Updated metadata for asset {$id}",
        );

        return Response::redirect($this->url('media.index'));
    }

    private function renderForm(MediaAsset $asset): Response
    {
        $id = AdminLayout::e($asset->id());
        $name = AdminLayout::e($asset->originalName());
        $url = MediaServices::urlFor($asset);
        $urlEscaped = AdminLayout::e($url);
        $altText = AdminLayout::e($asset->altText());
        $caption = AdminLayout::e($asset->caption());
        $category = AdminLayout::e($asset->category());
        $tagsStr = AdminLayout::e(implode(', ', $asset->tags()));
        $viewCount = $asset->viewCount();
        $providerName = AdminLayout::e($asset->provider() ?: MediaServices::storage()->getName());
        $csrf = AdminLayout::csrfField();

        $folder = AdminLayout::e($asset->folder());

        // Autocomplete datalists.
        $categories = MediaServices::media()->getCategories();
        $allTags = MediaServices::media()->getTags();
        $catOptions = '';
        foreach ($categories as $cat) {
            $catOptions .= '<option value="' . AdminLayout::e($cat) . '">';
        }
        $tagOptions = '';
        foreach ($allTags as $t) {
            $tagOptions .= '<option value="' . AdminLayout::e($t) . '">';
        }
        $folderOptions = '';
        foreach (MediaServices::media()->getFolders() as $knownFolder) {
            $folderOptions .= '<option value="' . AdminLayout::e($knownFolder) . '">';
        }

        $placeholderSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        $preview = ($asset->isImage() && '' !== $url)
            ? "<img src=\"{$urlEscaped}\" alt=\"{$altText}\" class=\"m-edit-preview__img\">"
            : '<div class="m-media-cell-placeholder">' . $placeholderSvg . '</div>';

        $viewIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

        $content = ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-edit.phtml', ['url' => $this->urlBuilder(), 'altText' => $altText, 'caption' => $caption, 'catOptions' => $catOptions, 'category' => $category, 'csrf' => $csrf, 'folder' => $folder, 'folderOptions' => $folderOptions, 'id' => $id, 'name' => $name, 'preview' => $preview, 'providerName' => $providerName, 'tagOptions' => $tagOptions, 'tagsStr' => $tagsStr, 'viewCount' => $viewCount, 'viewIcon' => $viewIcon]);

        return Response::html(AdminLayout::render(
            'Edit Media',
            $content,
            'media',
        router: $this->router()));
    }

    /**
     * @return list<string>
     */
    private function parseTags(string $raw): array
    {
        $tags = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($tags, static fn (string $t): bool => '' !== $t));
    }
}
