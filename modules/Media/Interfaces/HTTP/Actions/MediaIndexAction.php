<?php

declare(strict_types = 1);

namespace Modules\Media\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\InlineJs;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Env;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Application\Services\MediaService;
use Modules\Media\Domain\Model\MediaAsset;
use Modules\Media\Interfaces\HTTP\MediaIcons;
use Modules\Media\Interfaces\HTTP\MediaServices;

/**
 * Media index — GET /mark/media.
 *
 * Card-based grid view of all tracked uploads with category/tag filters,
 * sort options, and HTMX partial updates.
 */
#[Route(path: '/mark/media', method: 'GET', name: 'media.index')]
final class MediaIndexAction
{
    use ResolvesRouteUrls;

    private const int PER_PAGE = 50;

    public function handle(Request $request): Response
    {
        $pageRaw = $request->query('page');
        $page = max(1, is_numeric($pageRaw) ? (int) $pageRaw : 1);
        $filters = self::filters();
        $typeRaw = $request->queryString('type');
        $type = array_key_exists($typeRaw, $filters) ? $typeRaw : '';
        $sort = $request->queryString('sort', 'newest');
        $category = $request->queryString('category');
        $tag = $request->queryString('tag');
        $source = self::source($request->queryString('source'));
        $folder = MediaService::normalizeFolder($request->queryString('folder'));

        $service = MediaServices::media();
        $assets = $service->listWithSorting($sort, $type, $category, $tag, $page, self::PER_PAGE, $source, $folder);
        $total = $service->countWithFilter($type, $category, $tag, $source, $folder);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $categories = $service->getCategories();
        $allTags = $service->getTags();
        $folders = $service->getFolders();

        $filterBar = $this->renderFilterBar($type, $sort, $category, $tag, $source, $folder, $filters, $categories, $allTags, $folders);
        $folderTiles = $this->renderFolders($service->folderCounts($type, $category, $tag, $source), $type, $sort, $category, $tag, $source, $folder);
        $folderManage = $this->renderFolderManage($folders);
        $grid = $this->renderGrid($assets);
        $pagination = $this->renderPagination($page, $totalPages, $type, $sort, $category, $tag, $source, $folder);
        $inner = $this->renderStats($total) . $filterBar . $folderTiles . $folderManage . $grid . $pagination;
        $content = '<div id="media-content">' . $inner . '</div>';

        return $this->page($request, $content);
    }

    /**
     * The swappable content wrapped in the admin page — or returned bare for an
     * HTMX swap, which is what lets the panel update itself without a reload.
     *
     * The select-all script rides OUTSIDE `#media-content` on purpose: a
     * fragment carrying its own listener would double-bind it on every swap,
     * which is the bug the bulk checks exist for.
     */
    private function page(Request $request, string $content): Response
    {
        if (AdminLayout::isHtmx($request)) {
            return Response::html($content);
        }

        $mediaBulkScript = InlineJs::file('Modules/Media/Interfaces/HTTP/assets/scripts/media-bulk.js');
        $selectAllScript = <<<HTML
            <script nonce="__CSP_NONCE_PLACEHOLDER__">{$mediaBulkScript}</script>
        HTML;

        $actions = [['label' => 'Upload file', 'href' => $this->url('media.upload')]];
        if (null !== MediaServices::cloudinaryApi()) {
            $actions[] = ['label' => 'Cloudinary library', 'href' => $this->url('media.cloudinary')];
        }

        return Response::html(AdminLayout::render(
            'Media',
            $content . $selectAllScript . $this->toast($request),
            'media',
            $actions,
        router: $this->router()));
    }

    /**
     * @param list<MediaAsset> $assets
     */
    private function renderGrid(array $assets): string
    {
        if ([] === $assets) {
            return '<div class="m-card"><p class="m-muted">No media files yet.</p></div>';
        }

        $csrf = AdminLayout::csrfField();
        $iconDelete = AdminLayout::iconDelete();
        $cells = '';
        foreach ($assets as $asset) {
            $cells .= $this->renderCell($asset);
        }

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/select-all-script.phtml', ['url' => $this->urlBuilder(), 'cells' => $cells, 'csrf' => $csrf, 'iconDelete' => $iconDelete]);
    }

    private function renderCell(MediaAsset $asset): string
    {
        $id = AdminLayout::e($asset->id());
        $name = AdminLayout::e($asset->originalName());
        $editHref = AdminLayout::e($this->url('media.edit', ['id' => $asset->id()]));
        // '' when the provider cannot be built here (a cloudinary row in an
        // environment without credentials) — the card links to its edit page
        // and shows the placeholder instead of taking the index down.
        $url = MediaServices::urlFor($asset);
        $urlEscaped = AdminLayout::e('' !== $url ? $url : $editHref);
        $size = $this->formatSize($asset->size());
        $mime = AdminLayout::e($asset->mimeType());
        $date = AdminLayout::e(substr($asset->createdAt(), 0, 10));
        $viewCount = $asset->viewCount();
        $category = $asset->category();
        $tags = $asset->tags();

        // Thumbnail or placeholder.
        $thumb = $this->thumbHtml($asset, $url);

        // Category badge.
        $categoryBadge = '' !== $category
            ? '<span class="m-badge">' . AdminLayout::e($category) . '</span>'
            : '';

        // Provider badge — which storage this file actually lives on.
        $providerBadge = self::providerBadge($asset);

        // Folder — a local reference to where the file lives (root = none).
        $folder = $asset->folder();
        $folderInfo = '' !== $folder ? AdminLayout::e($folder) . ' · ' : '';

        // Tags chips.
        $tagsHtml = '';
        foreach ($tags as $tag) {
            $escapedTag = AdminLayout::e($tag);
            $tagsHtml .= '<span class="m-media-card__tag">' . $escapedTag . '</span>';
        }

        $viewsHtml = self::viewsHtml($viewCount);

        $iconEdit = AdminLayout::iconEdit();

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-grid.phtml', ['categoryBadge' => $categoryBadge, 'date' => $date, 'editHref' => $editHref, 'folderInfo' => $folderInfo, 'iconEdit' => $iconEdit, 'id' => $id, 'mime' => $mime, 'name' => $name, 'providerBadge' => $providerBadge, 'size' => $size, 'tagsHtml' => $tagsHtml, 'thumb' => $thumb, 'urlEscaped' => $urlEscaped, 'viewsHtml' => $viewsHtml]);
    }

    /**
     * The card thumbnail, or the placeholder when the asset is not an image
     * or its URL cannot be resolved in this environment (an unconfigured
     * Cloudinary — see MediaServices::urlFor()).
     */
    /**
     * A card's thumbnail: the crop, never the original.
     *
     * The card is 150px tall, so the remote side answers with a 300px square
     * (2x) and a page of 50 files costs kilobytes instead of the originals.
     * `$url` stays as the fallback for a provider that cannot build a preview
     * here (a cloudinary row without credentials), which is also what decides
     * the placeholder. Lazy loading keeps a long page from decoding every
     * image at once.
     */
    private function thumbHtml(MediaAsset $asset, string $url): string
    {
        if ('' === $url || !$asset->isImage()) {
            return '<div class="m-media-card__thumb-icon">' . self::placeholderSvg() . '</div>';
        }

        $preview = MediaServices::previewUrl($asset, 300);
        $urlEscaped = AdminLayout::e('' !== $preview ? $preview : $url);
        $nameEscaped = AdminLayout::e($asset->originalName());

        return "<img src=\"{$urlEscaped}\" alt=\"{$nameEscaped}\" loading=\"lazy\" class=\"m-media-card__thumb-img\">";
    }

    private static function placeholderSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    }

    private static function viewsHtml(int $viewCount): string
    {
        if ($viewCount < 1) {
            return '';
        }

        $viewIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

        return '<span class="m-media-card__views">' . $viewIcon . ' ' . $viewCount . '</span>';
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

    /**
     * @return array<string, string> Filter key → label
     */
    private static function filters(): array
    {
        $cloudinaryConfigured = '' !== (Env::get('CLOUDINARY_CLOUD_NAME', '') ?? '');

        if ($cloudinaryConfigured) {
            return ['' => 'All', 'image' => 'Images'];
        }

        return ['' => 'All', 'image' => 'Images', 'document' => 'Documents', 'text' => 'Text'];
    }

    /**
     * @param array<string, string> $filters
     * @param list<string>          $categories
     * @param list<string>          $allTags
     * @param list<string>          $folders
     */
    private function renderFilterBar(
        string $currentType,
        string $currentSort,
        string $currentCategory,
        string $currentTag,
        string $currentSource,
        string $currentFolder,
        array $filters,
        array $categories,
        array $allTags,
        array $folders,
    ): string {
        // Type pills — HTMX links.
        $typeLinks = '';
        foreach ($filters as $key => $label) {
            $href = $this->buildFilterUrl($key, $currentSort, $currentCategory, $currentTag, $currentSource, $currentFolder);
            $isActive = $key === $currentType;
            $class = $isActive ? 'm-pill m-pill-active' : 'm-pill';
            $typeLinks .= "<a href=\"{$href}\" class=\"{$class}\" hx-get=\"{$href}\" hx-target=\"#media-content\" hx-swap=\"outerHTML\" hx-push-url=\"true\">" . AdminLayout::e($label) . '</a>';
        }

        // Sort dropdown — HTMX with hx-trigger change.
        $sortOptions = ['newest' => 'Newest', 'most_viewed' => 'Most viewed', 'name_asc' => 'Name A-Z'];
        $hxCommon = 'hx-get="' . $this->url('media.index') . '" hx-target="#media-content" hx-swap="outerHTML" hx-trigger="change" hx-include="closest form"';
        $sortSelect = '<select name="sort" class="m-input m-input--compact" ' . $hxCommon . '>';
        foreach ($sortOptions as $val => $label) {
            $selected = $val === $currentSort ? ' selected' : '';
            $sortSelect .= '<option value="' . $val . '"' . $selected . '>' . AdminLayout::e($label) . '</option>';
        }
        $sortSelect .= '</select>';

        // Category dropdown — HTMX with hx-trigger change.
        $catSelect = '<select name="category" class="m-input m-input--compact" ' . $hxCommon . '>';
        $catSelect .= '<option value="">All categories</option>';
        foreach ($categories as $cat) {
            $selected = $cat === $currentCategory ? ' selected' : '';
            $catSelect .= '<option value="' . AdminLayout::e($cat) . '"' . $selected . '>' . AdminLayout::e($cat) . '</option>';
        }
        $catSelect .= '</select>';

        // Tag pills — HTMX links.
        $tagLinks = '';
        if ([] !== $allTags) {
            $tagLinks = '<span class="m-media-filters__sep">·</span>';
            foreach ($allTags as $t) {
                $href = $this->buildFilterUrl($currentType, $currentSort, $currentCategory, $t, $currentSource, $currentFolder);
                $isActive = $t === $currentTag;
                $class = $isActive ? 'm-pill m-pill-active' : 'm-pill';
                $tagLinks .= "<a href=\"{$href}\" class=\"{$class}\" hx-get=\"{$href}\" hx-target=\"#media-content\" hx-swap=\"outerHTML\" hx-push-url=\"true\">" . AdminLayout::e($t) . '</a>';
            }
        }

        $hiddenFields = self::hiddenStateFields($currentType, $currentTag, $currentSource);
        $sourcePills = $this->sourcePills($currentSource, $currentType, $currentSort, $currentCategory, $currentTag, $currentFolder);
        $folderSelect = $this->folderSelect($currentFolder, $folders);

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-bulkbar.phtml', ['url' => $this->urlBuilder(), 'catSelect' => $catSelect, 'folderSelect' => $folderSelect, 'hiddenFields' => $hiddenFields, 'sortSelect' => $sortSelect, 'sourcePills' => $sourcePills, 'tagLinks' => $tagLinks, 'typeLinks' => $typeLinks]);
    }

    /**
     * A folder operation's answer, rendered where it lands.
     *
     * The actions redirect here with `?toast=`, and a redirect nobody renders
     * is a silent no-op: the user would return to the index with no sign the
     * rename took, which is indistinguishable from a failed save. Same wire
     * format the users/roles screens use.
     */
    private function toast(Request $request): string
    {
        $message = $request->query('toast');

        if (!is_string($message) || '' === $message) {
            return '';
        }

        $isError = 'error' === $request->query('toast-type');

        return AdminLayout::toast(urldecode($message), $isError ? 'err' : 'ok');
    }

    /**
     * The folder manage block — create, rename and delete, one row per folder.
     *
     * Kept OUT of the tile links above: a `<form>` inside an `<a>` is invalid
     * markup that browsers resolve by dropping one of the two, and which one
     * depends on the parser.
     *
     * @param list<string> $folders
     */
    private function renderFolderManage(array $folders): string
    {
        $rows = '';

        foreach ($folders as $path) {
            $rows .= $this->folderManageRow($path);
        }

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-folder-manage.phtml', ['url' => $this->urlBuilder(), 
            'csrf'        => AdminLayout::csrfField(),
            'rows'        => $rows,
            'folderCount' => (string) count($folders),
        ]);
    }

    /**
     * One folder's rename field and delete control.
     *
     * The path is the row's label, not the input's value: prefilling the
     * input with the path showed the same string twice and made "rename"
     * read as "edit this path in place". The field starts empty with the
     * current path as its placeholder, so it can only be submitted as an
     * actual new name.
     */
    private function folderManageRow(string $path): string
    {
        $escaped = AdminLayout::e($path);
        $id = 'folder-rename-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($path));
        $csrf = AdminLayout::csrfField();

        return '<div class="m-folder-row">'
            . '<form class="m-folder-row__rename" method="post" action="' . AdminLayout::e($this->url('media.folder-rename')) . '">'
            . $csrf
            . '<input type="hidden" name="from" value="' . $escaped . '">'
            . '<label class="m-folder-row__name" for="' . AdminLayout::e((string) $id) . '">'
            . '<span class="m-folder-row__icon">' . MediaIcons::folder() . '</span>'
            . '<span class="m-folder-row__path">' . $escaped . '</span>'
            . '</label>'
            . '<input class="m-input m-folder-row__input" id="' . AdminLayout::e((string) $id) . '" type="text" name="to" placeholder="New path" required>'
            . '<button class="m-btn m-btn-sm" type="submit">Rename</button>'
            . '</form>'
            . '<form class="m-folder-row__delete" method="post" action="' . AdminLayout::e($this->url('media.folder-delete')) . '">'
            . $csrf
            . '<input type="hidden" name="path" value="' . $escaped . '">'
            . '<label class="m-folder-row__cascade"><input type="checkbox" name="cascade" value="1"> delete its files too</label>'
            . '<button class="m-btn m-btn-sm m-btn-error" type="submit">Delete</button>'
            . '</form>'
            . '</div>';
    }

    /**
     * The folder overview — every folder that holds files, with its count.
     *
     * A `<select>All folders</select>` is a filter control: you have to open
     * it to learn anything, and until you do the library reads as a flat pile
     * of files even when folders exist. These tiles answer "where is
     * everything?" without opening anything, and each one carries the other
     * active filters so entering a folder does not reset the view.
     *
     * @param array<string, int> $counts
     */
    private function renderFolders(
        array $counts,
        string $type,
        string $sort,
        string $category,
        string $tag,
        string $source,
        string $currentFolder,
    ): string {
        if ([] === $counts) {
            return '';
        }

        // One image per folder, so a tile answers "what is in there" before
        // it is opened. A folder with no image keeps its glyph.
        $previews = MediaServices::media()->folderPreviews(array_keys($counts));

        $tiles = '';
        foreach ($counts as $path => $count) {
            $href = AdminLayout::e($this->buildFilterUrl($type, $sort, $category, $tag, $source, $path));
            $asset = $previews[$path] ?? null;

            $tiles .= ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-folder-tile.phtml', [
                'class'      => $path === $currentFolder ? 'm-folder-card m-folder-card--active' : 'm-folder-card',
                'href'       => $href,
                'linkAttrs'  => 'hx-get="' . $href . '" hx-target="#media-content" hx-swap="outerHTML" hx-push-url="true"',
                'previewUrl' => null !== $asset ? AdminLayout::e(MediaServices::previewUrl($asset)) : '',
                'icon'       => MediaIcons::folder(),
                'name'       => AdminLayout::e($path),
                'count'      => (string) $count,
            ]);
        }

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-folders.phtml', [
            'tiles'   => $tiles,
            'folderCount' => (string) count($counts),
        ]);
    }

    /**
     * The filters a dropdown cannot carry, so changing one dropdown keeps the
     * others. Without these, picking a sort order silently drops the type/tag
     * the visitor had already chosen.
     */
    private static function hiddenStateFields(string $currentType, string $currentTag, string $currentSource = ''): string
    {
        $fields = '';

        if ('' !== $currentType) {
            $fields .= '<input type="hidden" name="type" value="' . AdminLayout::e($currentType) . '">';
        }

        if ('' !== $currentTag) {
            $fields .= '<input type="hidden" name="tag" value="' . AdminLayout::e($currentTag) . '">';
        }

        if ('' !== $currentSource) {
            $fields .= '<input type="hidden" name="source" value="' . AdminLayout::e($currentSource) . '">';
        }

        return $fields;
    }

    /**
     * The source pills. Like the type pills they are links, so their hrefs
     * carry every other active filter — a pill that dropped the folder the
     * visitor had open would look like a broken filter.
     */
    private function sourcePills(string $current, string $type, string $sort, string $category, string $tag, string $folder): string
    {
        $pills = '<span class="m-media-filters__label m-muted">Source:</span>';

        foreach (['' => 'All', 'local' => 'Local', 'cloudinary' => 'Cloudinary'] as $key => $label) {
            $href = $this->buildFilterUrl($type, $sort, $category, $tag, $key, $folder);
            $isActive = $key === $current;
            $class = $isActive ? 'm-pill m-pill-active' : 'm-pill';
            $pills .= "<a href=\"{$href}\" class=\"{$class}\" hx-get=\"{$href}\" hx-target=\"#media-content\" hx-swap=\"outerHTML\" hx-push-url=\"true\">" . AdminLayout::e($label) . '</a>';
        }

        return $pills;
    }

    /**
     * The folder dropdown — a form control, so the shared form (with its
     * hidden type/tag/source fields) carries the rest of the state when it
     * changes.
     *
     * @param list<string> $folders
     */
    private function folderSelect(string $current, array $folders): string
    {
        $hxCommon = 'hx-get="' . $this->url('media.index') . '" hx-target="#media-content" hx-swap="outerHTML" hx-trigger="change" hx-include="closest form"';
        $select = '<select name="folder" class="m-input m-input--compact" ' . $hxCommon . '>';
        $select .= '<option value="">All folders</option>';
        foreach ($folders as $folder) {
            $selected = $folder === $current ? ' selected' : '';
            $select .= '<option value="' . AdminLayout::e($folder) . '"' . $selected . '>' . AdminLayout::e($folder) . '</option>';
        }
        $select .= '</select>';

        return $select;
    }

    /**
     * The storage-source filter value, validated: anything outside the
     * three pills falls back to "All" instead of filtering by a name no
     * row can carry.
     */
    private static function source(string $source): string
    {
        return in_array($source, ['', 'local', 'cloudinary'], true) ? $source : '';
    }

    private static function providerBadge(MediaAsset $asset): string
    {
        $provider = $asset->provider() ?: MediaServices::storage()->getName();
        $class = 'cloudinary' === $provider ? 'm-badge m-badge-info' : 'm-badge m-badge-gray';

        return '<span class="' . $class . '">' . AdminLayout::e($provider) . '</span>';
    }

    /**
     * A filter link, every other active filter carried along. NOT static: the
     * href comes from `$this->url('media.index')`, and a static declaration
     * made the whole index a 500 (`Using $this when not in object context`) the
     * first time a type/tag/source/folder pill rendered — which is always.
     */
    private function buildFilterUrl(string $type, string $sort, string $category, string $tag, string $source = '', string $folder = ''): string
    {
        $params = [];
        if ('' !== $type) {
            $params['type'] = $type;
        }
        if ('newest' !== $sort && '' !== $sort) {
            $params['sort'] = $sort;
        }
        if ('' !== $category) {
            $params['category'] = $category;
        }
        if ('' !== $tag) {
            $params['tag'] = $tag;
        }
        if ('' !== $source) {
            $params['source'] = $source;
        }
        if ('' !== $folder) {
            $params['folder'] = $folder;
        }

        return $this->url('media.index') . ([] !== $params ? '?' . http_build_query($params) : '');
    }

    private function renderStats(int $total): string
    {
        $totalDisplay = AdminLayout::e((string) $total);
        $provider = AdminLayout::e(MediaServices::storage()->getName());

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-edit-inline.phtml', ['provider' => $provider, 'totalDisplay' => $totalDisplay]);
    }

    private function renderPagination(int $current, int $totalPages, string $type, string $sort, string $category, string $tag, string $source = '', string $folder = ''): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $links = '';
        for ($i = 1; $i <= $totalPages; $i++) {
            $href = $this->buildPageUrl($i, $type, $sort, $category, $tag, $source, $folder);
            if ($i === $current) {
                $links .= "<span class=\"m-page-link m-page-link-active\">{$i}</span>";
            } else {
                $links .= "<a href=\"{$href}\" class=\"m-page-link m-muted\" hx-get=\"{$href}\" hx-target=\"#media-content\" hx-swap=\"outerHTML\" hx-push-url=\"true\">{$i}</a>";
            }
        }

        return ViewRenderer::render('Modules/Media/Interfaces/HTTP/templates/media-list.phtml', ['links' => $links]);
    }

    private function buildPageUrl(int $page, string $type, string $sort, string $category, string $tag, string $source = '', string $folder = ''): string
    {
        $params = ['page' => (string) $page];
        if ('' !== $type) {
            $params['type'] = $type;
        }
        if ('newest' !== $sort && '' !== $sort) {
            $params['sort'] = $sort;
        }
        if ('' !== $category) {
            $params['category'] = $category;
        }
        if ('' !== $tag) {
            $params['tag'] = $tag;
        }
        if ('' !== $source) {
            $params['source'] = $source;
        }
        if ('' !== $folder) {
            $params['folder'] = $folder;
        }

        return $this->url('media.index') . '?' . http_build_query($params);
    }
}
