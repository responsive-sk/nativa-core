<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP\Actions;

use Modules\Blog\Application\BlogServices;
use Modules\Blog\Application\Services\BlogArticleHelper;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\ViewCounter;
use Modules\Blog\Interfaces\HTTP\BlogInlineEditor;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Application\Services\StructuredDataService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Core\Interfaces\HTTP\PageContext;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Interfaces\HTTP\LocalesServices;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Blog article action — renders a Markdown article from storage/blog/<slug>.md.
 *
 * Articles are plain .md files with YAML front matter (title, date, tags,
 * excerpt, author, category, optional author_email). They are discovered
 * dynamically by slug — no manual route registration per article. The menu
 * seed points at /blog (the index).
 *
 * This action handles routing, article resolution, data preparation and SEO
 * response shell. Theme-specific rendering is delegated to the resolved
 * `Themes\ThemeModule` through `ThemeKernel::for($theme)`. Shared helpers
 * (tagList, prevNext, related, authorHtml, metaLine, tagChips, coverImage)
 * are in BlogArticleHelper.
 */
#[Route(path: '/blog/{slug}', method: 'GET', name: 'blog.article')]
final class BlogArticleAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $slug = (string) ($request->routeParam('slug') ?? '');
        $resolved = $this->resolveArticle($slug, BlogArticlesService::requestLocale($request));

        if (null === $resolved) {
            return $this->notFound($request->getPathInfo());
        }

        // Draft gate — a direct URL to an unpublished article is a 404,
        // exactly like the Pages module treats unpublished pages. Drafts are
        // already absent from every list surface via BlogArticlesService::discover().
        if (true !== ($resolved['parsed']['meta']['published'] ?? true)) {
            return $this->notFound($request->getPathInfo());
        }

        $locale = $resolved['locale'];
        $this->syncActiveLocale($locale);
        $parsed = $resolved['parsed'];
        $theme = (new ThemeResolver())->resolve($request);
        $meta = $parsed['meta'];
        $title = is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug);
        $excerpt = is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '';

        // Members-only gate — guests get a teaser + login prompt instead of
        // the full article (no TOC, no comments). The auth check is lazy:
        // the AuthService is only built for members-only articles, so plain
        // public articles never open the users.db connection.
        if (true === ($meta['members_only'] ?? false) && !$this->isMember($request)) {
            return $this->membersGate($request, $slug, $title, $excerpt, $meta, $locale, $theme);
        }

        return $this->articlePage($request, $slug, $title, $excerpt, $meta, $parsed, $locale, $theme);
    }

    /**
     * Full article page — data preparation, view counting, theme-module
     * rendering and the SEO response shell.
     *
     * @param array<string, mixed>                            $meta
     * @param array{meta: array<string, mixed>, body: string} $parsed
     */
    private function articlePage(
        Request $request,
        string $slug,
        string $title,
        string $excerpt,
        array $meta,
        array $parsed,
        string $locale,
        string $theme,
    ): Response {
        $resolver = BlogServices::authorResolver();
        $articles = BlogServices::articles();
        $metaLine = BlogArticleHelper::metaLine($meta, BlogArticleHelper::authorHtml($meta, $resolver, BlogServices::avatar()));
        $coverUrl = $articles->resolveCover($slug, $meta, $locale);

        $renderer = new MarkdownRenderer();
        // The theme prefix travels in the context so an embedded block renders
        // native markup instead of the starter default.
        $bodyHtml = $renderer->render(
            $this->withEmbeddedForm($parsed['body'], $meta),
            new MarkdownBlockContext(
                $locale,
                ThemeKernel::for($theme)->prefix(),
                $request->getPathInfo(),
                $this->blockState($request),
            ),
        );

        // One ThemeKernel call per surface — page composition lives in the
        // theme module (theme-as-module); the comment prefix comes from the
        // module's declared BEM prefix instead of a hardcoded 'er'/'st'.
        $module = ThemeKernel::for($theme);

        $content = $module->blogArticle(PageContext::forBlogArticle(
            $request,
            $theme,
            $locale,
            $slug,
            $title,
            $bodyHtml,
            $metaLine,
            BlogArticleHelper::tagChips($meta),
            BlogArticleHelper::coverImage($slug, $meta, $articles, $locale),
            $coverUrl,
            $renderer->extractHeadings($bodyHtml),
            $meta,
            $this->recordView($slug),
            $this->commentsForTheme($request, $slug, $theme, $module->prefix()),
            $this->editUrl($slug, $locale),
            LocalesServices::t('blog.edit', 'Edit', $locale),
            $this->inlineEditHtml($request, $slug, $locale, $module->prefix()),
        ));

        // erik owns the comment slot inside its own article markup; every other
        // theme gets it appended after the composed page. The reader-questions
        // block is appended for EVERY theme — no theme owns that slot, and a
        // block only one theme renders is a block four readers never see.
        //
        // The tail goes back through the theme: appended raw, these blocks
        // land outside the theme's page container and run edge to edge. The
        // hook lets a theme place them in its own reading column; the default
        // keeps the old trailing slot for themes that do not compose one.
        $tail = '';

        if ('erik' !== $theme) {
            $tail .= $this->commentSection($request, $slug, $module->prefix());
        }

        $tail .= $this->questionBlock($request, $slug, $locale, $module->prefix());

        if ('' !== $tail) {
            $content .= $module->articleTail($tail);
        }

        // The reading surface, applied where every theme gets it: a prose column
        // bounded by measure beside a floating contents rail. A theme that
        // already composes a rail (Boson, Jednotka, Mono and Griffe do) is left
        // untouched — `ReaderLayout` reads the rail's own marker off the
        // composed HTML rather than guessing from the theme's name, so a theme
        // that starts or stops composing one needs no change here. Themes are a
        // copy of `nativa-themes` and a rule in five of them is a rule a sixth
        // silently misses; this is the one place that covers all of them.
        //
        // The headings are the ones the renderer generated, so a rail link can
        // never point at an id the article does not carry.
        $content = FrontendLayout::readerLayout($content, $renderer->extractHeadings($bodyHtml));

        return $this->articleResponse($request, $title, $content, $excerpt, $locale, $slug, $meta, $coverUrl);
    }

    /**
     * Only erik renders the comment section inside its own article markup;
     * for every other theme the slot stays empty here and the section is
     * appended to the composed page instead. The prefix is a plain string
     * because this action may not name the `Themes\` contract at all — only
     * `ThemeKernel` may.
     */
    private function commentsForTheme(Request $request, string $slug, string $theme, string $prefix): string
    {
        return 'erik' === $theme ? $this->commentSection($request, $slug, $prefix) : '';
    }

    /**
     * Record the view and return the new count. Called after the auth check,
     * so a members-only view is only counted for an authenticated reader.
     */
    private function recordView(string $slug): int
    {
        $counter = ViewCounter::instance();
        $counter->record($slug);

        return $counter->count($slug);
    }

    // ─── Article resolution ─────────────────────────────────────────

    private function syncActiveLocale(string $locale): void
    {
        LocalesServices::locale()->set($locale);
    }

    /**
     * @return array{locale: string, parsed: array{meta: array<string, mixed>, body: string}}|null
     */
    private function resolveArticle(string $slug, string $locale): ?array
    {
        $parsed = $this->loadArticle($slug, $locale);
        $default = ContentServices::mapper()->defaultLocale();

        if (null === $parsed && $locale !== $default) {
            $fallback = $this->loadArticle($slug, $default);

            if (null !== $fallback) {
                return ['locale' => $default, 'parsed' => $fallback];
            }
        }

        return null === $parsed ? null : ['locale' => $locale, 'parsed' => $parsed];
    }

    /**
     * @return array{meta: array<string, mixed>, body: string}|null
     */
    private function loadArticle(string $slug, string $locale): ?array
    {
        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            return null;
        }

        $dir = BlogArticlesService::directoryFor($locale);
        $file = AppPaths::instance()->storage($dir . DIRECTORY_SEPARATOR . $slug . '.md');
        $source = is_file($file) ? file_get_contents($file) : false;

        if (!is_string($source)) {
            return null;
        }

        return (new MarkdownRenderer())->extractFrontMatter($source);
    }

    // ─── SEO response shell ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function articleResponse(
        Request $request,
        string $title,
        string $content,
        string $excerpt,
        string $locale,
        string $slug,
        array $meta,
        string $coverUrl,
    ): Response {
        $links = $this->localeLinks($locale, $slug, $meta);
        $canonical = $this->canonicalUrl($request, $locale, $links);
        $ogImage = str_starts_with($coverUrl, 'http') ? $coverUrl : $request->absoluteUrl($coverUrl);

        $structuredData = StructuredDataService::article(
            $title,
            $excerpt,
            $canonical,
            is_string($meta['date'] ?? null) ? $meta['date'] : '',
            $ogImage,
            is_string($meta['author'] ?? null) ? $meta['author'] : '',
            BlogServices::brandSuffix(),
            BlogServices::label(),
        );

        return Response::html(FrontendLayout::render(
            $title . ' ... ' . BlogServices::brandSuffix(),
            $content,
            description: $excerpt,
            currentPath: $request->getPathInfo(),
            localeLinks: $links,
            lang: ContentServices::mapper()->languageCode($locale),
            hreflangLinks: $this->hreflangLinks($request, $links),
            canonicalUrl: $canonical,
            ogType: 'article',
            ogImage: $ogImage,
            structuredData: $structuredData,
            feedLinks: BlogArticleHelper::feedLinks($locale),
            theme: (new ThemeResolver())->resolve($request),
        ));
    }

    // ─── Members-only gate ──────────────────────────────────────────

    /**
     * Members-only gate — teaser article shell + sign-in prompt for guests.
     *
     * @param array<string, mixed> $meta
     */
    private function membersGate(
        Request $request,
        string $slug,
        string $title,
        string $excerpt,
        array $meta,
        string $locale,
        string $theme,
    ): Response {
        $articles = BlogServices::articles();
        $metaLine = BlogArticleHelper::metaLine($meta, BlogArticleHelper::authorHtml(
            $meta,
            BlogServices::authorResolver(),
            BlogServices::avatar(),
        ));
        $coverImage = BlogArticleHelper::coverImage($slug, $meta, $articles, $locale);
        $coverUrl = $articles->resolveCover($slug, $meta, $locale);

        $teaser = '' !== $excerpt
            ? '<p class="st-article__teaser">' . FrontendLayout::e($excerpt) . '</p>'
            : '';

        $returnTo = rawurlencode($this->blogHref($locale, $slug));

        $gate = '<aside class="st-article-gate">'
            . '<h2 class="st-article-gate__title">Member-only article</h2>'
            . '<p class="st-article-gate__text">This article is for logged-in readers. Sign in or create an account to read the full article.</p>'
            . '<p class="st-article-gate__actions">'
            . '<a class="st-article-gate__button st-article-gate__button--primary" href="/login?return_to=' . $returnTo . '">Sign in</a>'
            . '<a class="st-article-gate__button" href="/register?return_to=' . $returnTo . '">Create account</a>'
            . '</p>'
            . '</aside>';

        $content = '<article class="st-article">'
            . '<header class="st-article__header">'
            . $coverImage
            . '<h1 class="st-article__title">' . FrontendLayout::e($title) . '</h1>'
            . '<p class="st-article__meta">' . $metaLine . '</p>'
            . '</header>'
            . $teaser
            . '</article>'
            . $gate;

        return $this->articleResponse($request, $title, $content, $excerpt, $locale, $slug, $meta, $coverUrl);
    }

    /**
     * The inline Markdown editor, for the article's owner only.
     *
     * Rendered by the MODULE, not by the theme, for the same reason the
     * comment section is: the form posts to a Blog route, so every theme
     * rendering its own copy would be four copies to keep in step. The theme
     * prefix is the only thing the theme owns here.
     *
     * Empty for anyone who does not own the article, and empty for a draft —
     * a member-only teaser has no body to edit, and the caller has not even
     * read the full text at that point.
     *
     * The `?saved=1` confirmation rides in the SAME slot on purpose: the only
     * visitor who follows that redirect is the one who just saved, and anyone
     * who cannot see the editor must not be told their text was saved. Keeping
     * them together makes that impossible to get wrong, where a second slot
     * would be two rules to keep in step.
     */
    private function inlineEditHtml(Request $request, string $slug, string $locale, string $prefix): string
    {
        if (!$this->mayEdit($slug, $locale)) {
            return '';
        }

        $article = BlogServices::crud()->find($slug, $locale);

        if (null === $article) {
            return '';
        }

        $notice = $this->justSaved($request)
            ? BlogInlineEditor::savedNotice($prefix, $locale)
            : '';

        return $notice . BlogInlineEditor::render(
            $prefix,
            $slug,
            $locale,
            $article['body'],
            LocalesServices::t('blog.edit_body', 'Edit this article', $locale),
        );
    }

    /**
     * Did this GET come from a successful inline save?
     *
     * Only `?saved=1` counts: the parameter arrives from a URL, so a value the
     * reader typed by hand must not be able to render a confirmation.
     */
    private function justSaved(Request $request): bool
    {
        return '1' === $request->query('saved');
    }

    // ─── Auth ───────────────────────────────────────────────────────

    /**
     * The author-edit affordance for the public article page, or '' when this
     * visitor must not see one.
     *
     * Resolved HERE, kernel-side, and handed to the theme as a finished URL.
     * The theme owns markup but not policy: letting each theme ask "may I
     * edit?" would put the ownership rule in four places, which is how the
     * bulk endpoints ended up bypassing it in the first place.
     *
     * Empty for anonymous visitors and for a signed-in user who does not own
     * the article. Rendering it for the latter would only lead to a 403, and a
     * refusal on a public page reads as a broken site rather than as a rule.
     *
     * The session-COOKIE test first is what keeps the promise the article page
     * already makes: a plain public article must not open `users.db`. Only a
     * visitor who carries a session pays for the lookup, and they are the only
     * ones who could ever see a link.
     */
    private function editUrl(string $slug, string $locale): string
    {
        $name = session_name();

        if (!is_string($name) || !isset($_COOKIE[$name])) {
            return '';
        }

        return $this->mayEdit($slug, $locale)
            ? $this->url('articles.edit', ['slug' => rawurlencode($slug)]) . '?locale=' . rawurlencode($locale)
            : '';
    }

    /**
     * May this request's session edit this article?
     *
     * One ownership answer for the link and the editor, so the two can never
     * disagree — a link with no editor behind it is a dead end, and an editor
     * with no link is a mystery.
     */
    private function mayEdit(string $slug, string $locale): bool
    {
        $user = AuthServices::auth()->currentUser();

        return null !== $user && BlogServices::writeGuard()->owns($slug, $locale, $user);
    }

    private function isMember(Request $request): bool
    {
        $auth = AuthServices::auth();

        if ($auth->isAuthenticated()) {
            return true;
        }

        $user = $auth->authenticateFromRememberCookie();

        if (null === $user || !$user->isActive()) {
            return false;
        }

        $auth->establishSession($user, $request->getClientIp());

        return true;
    }

    // ─── Comments ───────────────────────────────────────────────────

    private function commentSection(Request $request, string $slug, string $prefix): string
    {
        // Guarded by class_exists, never imported: an article is complete
        // without its comments (same install-order rule as the questions
        // box below and the page embed in Pages).
        if (!class_exists('Modules\\Comments\\Interfaces\\HTTP\\CommentSection')) {
            return '';
        }

        $errors = $request->getAttribute('comment_errors', []);
        $old = $request->getAttribute('comment_old', []);

        return \Modules\Comments\Interfaces\HTTP\CommentSection::render(
            $slug,
            BlogArticleHelper::stringList($errors),
            BlogArticleHelper::stringMap($old),
            $prefix,
        );
    }

    /**
     * The reader-questions block, with the visitor's own validation errors and
     * old input when a submission came back from `QuestionSubmitAction`, and
     * the PRG confirmation when the `question-sent` marker is present.
     *
     * It is only ever reached for a PUBLISHED article — the draft gate above
     * returns a 404 first — so a reader can never see a box on text they
     * cannot read.
     */
    private function questionBlock(Request $request, string $slug, string $locale, string $prefix): string
    {
        if (!class_exists('Modules\\Questions\\Interfaces\\HTTP\\QuestionBlockRenderer')) {
            return '';
        }

        $errors = $request->getAttribute('question_errors', []);
        $old = $request->getAttribute('question_old', []);

        return \Modules\Questions\Interfaces\HTTP\QuestionBlockRenderer::render(
            $slug,
            \Modules\Questions\Domain\Entities\Question::SUBJECT_ARTICLE,
            $locale,
            is_array($errors) ? $errors : [],
            is_array($old) ? $old : [],
            $prefix,
            '1' === $request->query('question-sent'),
        );
    }

    // ─── Locale / SEO helpers ───────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, string>
     */
    private function localeLinks(string $locale, string $slug, array $meta): array
    {
        $links = [$locale => $this->blogHref($locale, $slug)];
        $translation = is_string($meta['translation'] ?? null) ? $meta['translation'] : '';

        if ('' !== $translation && 1 === preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $translation)) {
            $other = $this->otherLocale($locale);
            $otherFile = AppPaths::instance()->storage(
                BlogArticlesService::directoryFor($other) . DIRECTORY_SEPARATOR . $translation . '.md',
            );

            if (is_file($otherFile)) {
                $links[$other] = $this->blogHref($other, $translation);
            }
        }

        return $links;
    }

    /**
     * The single other locale of the system — the counterpart whose directory
     * holds the translated slug. Derived from the mapper rather than from a
     * 'en_US' === $locale ternary, which silently assumed Slovak was default.
     */
    private function otherLocale(string $locale): string
    {
        foreach (ContentServices::mapper()->locales() as $code) {
            if ($code !== $locale) {
                return $code;
            }
        }

        return $locale;
    }

    private function blogHref(string $locale, string $slug): string
    {
        return ContentServices::mapper()->urlPrefix($locale) . '/blog/' . $slug;
    }

    /**
     * @param array<string, string> $links
     */
    private function canonicalUrl(Request $request, string $locale, array $links): string
    {
        $canonical = $links[$locale] ?? (string) reset($links);

        return $request->absoluteUrl($canonical);
    }

    /**
     * @param array<string, string> $links
     *
     * @return array<string, string>|null
     */
    private function hreflangLinks(Request $request, array $links): ?array
    {
        if (count($links) < 2) {
            return null;
        }

        $baseUrl = $request->absoluteUrl('');
        $hreflang = [];

        foreach ($links as $code => $path) {
            $language = Locale::fromString($code)->language();
            $hreflang[$language] = $baseUrl . $path;
        }

        // x-default points at the configured default locale's version; if this
        // page has no translation for it (or it IS the default), fall back to
        // the first available link.
        $defaultCode = ContentServices::mapper()->defaultLocale();
        $fallback = $links[$defaultCode] ?? (string) reset($links);

        if ('' !== $fallback) {
            $hreflang['x-default'] = $baseUrl . $fallback;
        }

        return $hreflang;
    }

    private function notFound(string $currentPath = '/'): Response
    {
        $content = '<section class="st-hero">'
            . '<h1 class="st-hero__title">Article not found</h1>'
            . '<p class="st-hero__subtitle">The article you are looking for does not exist.</p>'
            . '</section>';

        return Response::html(
            FrontendLayout::render('Not Found ... ' . BlogServices::brandSuffix(), $content, currentPath: $currentPath),
            404,
        );
    }
    /**
     * `form: <slug>` in front matter embeds a form at the end of the body.
     *
     * The action only knows the front-matter KEY — the placeholder travels as
     * markdown and the Forms module's markdown block expands it, so neither
     * this action nor MarkdownRenderer imports `Modules\Forms`. The slug is
     * pattern-guarded here: front matter is content, and content must not be
     * able to inject anything into the placeholder.
     *
     * @param array<string, mixed> $meta
     */
    /**
     * The re-render state `FormSubmitAction` handed over, keyed by block.
     *
     * Same three attributes `PageViewAction` reads: the endpoint dispatches to
     * whichever surface embeds the form, so both must pass the reader's own
     * errors and words through to the block that will paint them.
     *
     * @return array<string, array{errors: list<string>, invalid: list<string>, old: array<string, string>, sent: bool}>
     */
    private function blockState(Request $request): array
    {
        return ['forms' => [
            'errors'  => $request->getAttribute('form_errors', []),
            'invalid' => $request->getAttribute('form_invalid', []),
            'old'     => $request->getAttribute('form_old', []),
            'sent'    => '1' === $request->query('form-sent'),
        ]];
    }

    private function withEmbeddedForm(string $body, array $meta): string
    {
        $slug = is_string($meta['form'] ?? null) ? trim($meta['form']) : '';

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return $body;
        }

        return $body . "\n\n{{form:" . $slug . "}}\n";
    }
}
