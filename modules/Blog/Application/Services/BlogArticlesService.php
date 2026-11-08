<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Domain\Entities\Article;
use Modules\Blog\Domain\Entities\ArticleAuthor;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

/**
 * BlogArticlesService — discovers articles from storage/blog (plain .md files
 * with YAML front matter) and normalizes them into card arrays, newest first.
 *
 * Shared by BlogIndexAction (/blog) and AuthorProfileAction (/authors/{slug}):
 * the directory scan, front-matter normalization, sort and card markup live
 * here so both pages agree on ordering, metadata and HTML. Articles are
 * derived from the directory on every request (no generated map). Card arrays
 * carry the resolved author profile (id/name/avatar) and the raw author_email
 * (never rendered) so the author line can link to the profile page.
 */
final class BlogArticlesService
{
    /**
     * Cards in the first grid row (coupled to the 1080px main / 3-column
     * st-grid on desktop). Their covers are same-size LCP candidates — the
     * winner is whichever paints last — so the whole row is eager + high
     * priority. On mobile (1 column) this over-fetches card 2's image, but
     * the cost is trivial (~25 KiB) and the heuristic stays simple.
     */
    private const int FIRST_ROW_CARDS = 3;

    /**
     * Cloudinary illustrated code images (cloud epithemic, blog folder).
     *
     * The only external service the app allows (CSP img-src permits
     * res.cloudinary.com); each article card gets one picked deterministically
     * from the slug hash, so the illustration is stable per article (no
     * flicker on every request) but spread across the pool.
     *
     * @var list<string>
     */
    private const array COVER_IMAGES = [
        'https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v1773169415/blog/d1a18cb5ea2f538c0a8d06e4f6e74264_rdkl7a.webp',
        'https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v1782250908/blog/distroless-debug.webp',
        'https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v1782250908/blog/fingerprint-llm.webp',
        'https://res.cloudinary.com/epithemic/image/upload/c_fill,w_720,h_360,f_webp,q_auto/v1782250908/blog/llm-agent-exec.webp',
    ];

    /**
     * The deterministic cover pool (resolveCover's last rung), exposed for
     * the pin-policy contract: a front-matter pin must never freeze a
     * resolver output as content, so CoverPinPolicyTest compares pins
     * against this list instead of a private copy.
     *
     * @return list<string>
     */
    public static function coverPool(): array
    {
        return self::COVER_IMAGES;
    }

    private ?AuthorAvatar $avatar = null;

    public function __construct(
        private readonly BlogAuthorResolver $resolver,
        private readonly CoverGenerator $covers = new CoverGenerator(),
    ) {
    }

    /**
     * All articles in the given locale, newest first. The locale is the full
     * code (sk_SK, en_US): the default locale (settings.locale) reads
     * storage/blog, every other locale reads storage/blog/{lang}.
     *
     * @return list<Article>
     */
    public function all(string $locale): array
    {
        return $this->discover($locale);
    }

    /**
     * Lightweight homepage cards — front matter only, without resolving
     * author profiles from users.db for every article in the corpus.
     *
     * @return list<array{title: string, slug: string, date: string, excerpt: string, category: string, author: string, image: string}>
     */
    public function latestCards(int $limit, string $locale): array
    {
        $renderer = new MarkdownRenderer();
        $cards = [];
        $files = glob(AppPaths::instance()->storage(self::directoryFor($locale) . DIRECTORY_SEPARATOR . '*.md')) ?: [];

        foreach ($files as $file) {
            $slug = basename($file, '.md');
            $source = file_get_contents($file);
            if (false === $source || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
                continue;
            }

            $meta = $renderer->extractFrontMatter($source)['meta'];
            if (true !== ($meta['published'] ?? true)) {
                continue;
            }

            $cards[] = $this->latestCard($slug, $meta, $locale);
        }

        usort($cards, static fn (array $a, array $b): int => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        return array_slice($cards, 0, max(0, $limit));
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{title: string, slug: string, date: string, excerpt: string, category: string, author: string, image: string}
     */
    private function latestCard(string $slug, array $meta, string $locale): array
    {
        return [
            'title' => is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
            'slug' => $slug,
            'date' => is_string($meta['date'] ?? null) ? $meta['date'] : '',
            'excerpt' => is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
            'category' => is_string($meta['category'] ?? null) ? $meta['category'] : '',
            'author' => is_string($meta['author'] ?? null) ? $meta['author'] : '',
            'image' => $this->resolveCover($slug, $meta, $locale),
        ];
    }

    /**
     * Articles whose resolved author profile IS the given user id — the
     * author profile page's article grid, keyed by resolved identity rather
     * than the raw front-matter email string. The page is addressed by id
     * (authors.show), so the grid matches on that same identity: an article
     * links to the page exactly when the page lists it.
     *
     * @return list<Article>
     */
    public function byAuthorProfileId(string $id, string $locale): array
    {
        $articles = [];

        foreach ($this->discover($locale) as $article) {
            $profile = $article->profile;
            if (null !== $profile && 0 === strcasecmp($profile->id, $id)) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /**
     * Articles for the given slugs, in the given order — the FTS search
     * results shaped back into the standard card arrays. Unknown slugs are
     * silently skipped (defensive: a deleted file must not break the page).
     *
     * @param list<string> $slugs
     *
     * @return list<Article>
     */
    public function bySlugs(array $slugs, string $locale): array
    {
        $bySlug = [];

        foreach ($this->discover($locale) as $article) {
            $bySlug[$article->slug] = $article;
        }

        $cards = [];

        foreach ($slugs as $slug) {
            if (isset($bySlug[$slug])) {
                $cards[] = $bySlug[$slug];
            }
        }

        return $cards;
    }

    /**
     * Render the shared card grid (st-grid + st-blog-index__card classes).
     *
     * @param list<Article>      $articles
     * @param array<string, int> $viewCounts slug → view count
     */
    public function renderCards(array $articles, array $viewCounts = []): string
    {
        $cards = '';

        foreach ($articles as $index => $article) {
            // The first grid row (3 cards on desktop, 1 on mobile) holds the
            // LCP candidate — all its images are the same size, so the LCP
            // winner is whichever paints last. Eager + fetchpriority=high for
            // the whole row; the rest stay lazy below the fold.
            $views = $viewCounts[$article->slug] ?? 0;
            $cards .= $this->renderCard($article, $index < self::FIRST_ROW_CARDS, $views);
        }

        return '<div class="st-blog-index__list st-grid">' . $cards . '</div>';
    }

    /**
     * Discover and sort articles in the given locale from storage/blog (the
     * default locale) or storage/blog/{lang}. The slug is the basename in the
     * locale's own directory — an alternate-locale article lives under its own
     * slug, with the counterpart linked through the front matter
     * 'translation' key.
     *
     * @return list<Article>
     */
    private function discover(string $locale): array
    {
        $dir = self::directoryFor($locale);
        $files = glob(AppPaths::instance()->storage($dir . DIRECTORY_SEPARATOR . '*.md')) ?: [];
        $articles = [];
        $renderer = new MarkdownRenderer();

        foreach ($files as $file) {
            // Only index slugs the article action can actually serve — a file
            // outside the safe slug pattern would otherwise appear as a dead
            // link (BlogArticleAction rejects it with a 404).
            $slug = basename($file, '.md');
            if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
                continue;
            }

            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $meta = $renderer->extractFrontMatter($source)['meta'];

            // Drafts (published: false) are hidden from every public surface —
            // index, search, tags, categories, author pages, feeds and the
            // sitemap all read this one list.
            if (true !== ($meta['published'] ?? true)) {
                continue;
            }

            $articles[] = $this->articleCard($slug, $meta, $locale);
        }

        usort($articles, static fn (Article $a, Article $b): int => [$b->date, $a->title] <=> [$a->date, $b->title]);

        return $articles;
    }

    /**
     * Normalize one article's front matter into an Article value object.
     * The author is resolved through the optional author_email key (users.db
     * profile, falling back to the plain author string) so renames propagate
     * and the card can link to the author page.
     *
     * @param array<string, mixed> $meta
     */
    private function articleCard(string $slug, array $meta, string $locale): Article
    {
        $fallback = is_string($meta['author'] ?? null) ? $meta['author'] : '';
        $email = is_string($meta['author_email'] ?? null) ? $meta['author_email'] : '';
        $rawProfile = '' !== $email ? $this->resolver->profile($email) : null;
        $profile = null !== $rawProfile ? ArticleAuthor::fromArray($rawProfile) : null;

        return new Article(
            slug: $slug,
            title: is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug),
            date: is_string($meta['date'] ?? null) ? $meta['date'] : '',
            excerpt: is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
            category: is_string($meta['category'] ?? null) ? $meta['category'] : '',
            author: null === $profile ? $fallback : $profile->name,
            authorEmail: $email,
            profile: $profile,
            tags: is_array($meta['tags'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
                    $meta['tags'],
                ), static fn (string $tag): bool => '' !== $tag))
                : [],
            image: $this->resolveCover($slug, $meta, $locale),
            membersOnly: true === ($meta['members_only'] ?? false),
            locale: $locale,
            translation: is_string($meta['translation'] ?? null) && '' !== $meta['translation']
                ? $meta['translation']
                : null,
            published: true === ($meta['published'] ?? true),
        );
    }

    /**
     * Resolve cover image for an article — priority:
     * 1. Front matter 'image' field (explicit URL)
     * 2. Local SVG in storage/blog-images/{slug}.svg (generated)
     * 3. Cloudinary pool (deterministic fallback)
     *
     * Single source of truth for cover resolution — shared by the article
     * cards (this service) and the article page (BlogArticleAction), so the
     * index and the article always agree on the same cover for a slug.
     *
     * @param array<string, mixed> $meta
     */
    public function resolveCover(string $slug, array $meta, string $locale = 'sk'): string
    {
        // 1. Explicit image from front matter
        if (is_string($meta['image'] ?? null) && '' !== $meta['image']) {
            return $meta['image'];
        }

        // 2a. Locale-qualified SVG (blog-images/{locale}/{slug}.svg) — the
        // collision-proof location. The generator writes here whenever two
        // locales share the same slug; the flat file of an ambiguous slug
        // can only ever hold ONE locale's identity.
        $qualifiedPath = AppPaths::instance()->storage(
            'blog-images' . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $slug . '.svg',
        );
        if (is_file($qualifiedPath)) {
            return $this->covers->versionedUrl('/storage/blog-images/' . $locale . '/' . $slug . '.svg', $qualifiedPath);
        }

        // 2b. Flat SVG (blog-images/{slug}.svg) — legacy and unique-slug
        // location. With a content-hash query param so a regenerated cover
        // gets a fresh URL instead of serving the year-old one from the
        // immutable cache (ServeBlogImageAction ships max-age=31536000).
        $svgPath = AppPaths::instance()->storage('blog-images' . DIRECTORY_SEPARATOR . $slug . '.svg');
        if (is_file($svgPath)) {
            return $this->covers->versionedUrl('/storage/blog-images/' . $slug . '.svg', $svgPath);
        }

        // 3. Cloudinary pool fallback
        return self::COVER_IMAGES[abs(crc32($slug)) % count(self::COVER_IMAGES)];
    }

    /**
     * @param Article $article
     */
    private function renderCard(Article $article, bool $inFirstRow, int $views = 0): string
    {
        // English articles live under their own slug in the /en/blog/…
        // prefix; the default locale (sk) keeps the plain /blog/… URL.
        $href = ('en_US' === $article->locale ? '/en' : '') . '/blog/' . $article->slug;
        $authorLine = null === $article->profile
            ? FrontendLayout::e($article->author)
            : $this->avatar()->authorLine(['id' => $article->profile->id, 'name' => $article->profile->name, 'avatar' => $article->profile->avatar], $article->author);

        $viewsBit = $views > 0 ? FrontendLayout::e(number_format($views) . ' view' . (1 === $views ? '' : 's')) : '';

        $metaBits = array_values(array_filter([
            '' !== $article->date ? FrontendLayout::e($article->date) : '',
            '' !== $article->category ? FrontendLayout::e($article->category) : '',
            $authorLine,
            $viewsBit,
        ], static fn (string $bit): bool => '' !== $bit));

        $tagChips = '';
        if ([] !== $article->tags) {
            $chips = [];
            foreach ($article->tags as $tag) {
                $tagHref = '/blog/tags/' . urlencode(strtolower($tag));
                $chips[] = '<a class="st-article__tag" href="' . FrontendLayout::e($tagHref) . '">' . FrontendLayout::e($tag) . '</a>';
            }
            $tagChips = '<p class="st-blog-index__tags">' . implode('', $chips) . '</p>';
        }

        // Cloudinary cover — fixed 720x360 aspect (c_fill) so the width/height
        // attributes prevent layout shift. The first grid row holds the LCP
        // candidate (all same-size on desktop), so those images are eager +
        // fetchpriority=high; the rest are lazy below the fold. The srcset
        // offers smaller crops for the ~330px card.
        $loading = $inFirstRow ? 'eager' : 'lazy';
        $priority = $inFirstRow ? ' fetchpriority="high"' : '';

        $cover = '<img class="st-blog-index__card-image" src="' . FrontendLayout::e($article->image)
            . '" alt="' . FrontendLayout::e($article->title) . '" loading="' . $loading . '"' . $priority
            . ' decoding="async" width="720" height="360"' . $this->coverSrcset($article->image) . '>';

        $badge = $article->membersOnly
            ? '<span class="st-blog-index__badge" title="Members only — sign in to read">Members only</span>'
            : '';

        return '<article class="st-blog-index__card">'
            . $cover
            . $badge
            . '<h2 class="st-blog-index__card-title"><a href="' . FrontendLayout::e($href) . '">' . FrontendLayout::e($article->title) . '</a></h2>'
            . '<p class="st-blog-index__card-meta">' . implode(' · ', $metaBits) . '</p>'
            . '<p class="st-blog-index__card-text">' . FrontendLayout::e($article->excerpt) . '</p>'
            . $tagChips
            . '</article>';
    }

    /**
     * Responsive srcset for Cloudinary covers — smaller c_fill crops (360w /
     * 634w) plus the full 720w, with a sizes hint matching the ~330px desktop
     * card. Local SVG covers are vector and resolution-independent — no
     * variants needed (returns '').
     */
    private function coverSrcset(string $image): string
    {
        if (!str_contains($image, 'res.cloudinary.com')) {
            return '';
        }

        return $this->cloudinarySrcset($image);
    }

    /**
     * Build responsive srcset for a Cloudinary URL.
     */
    private function cloudinarySrcset(string $image): string
    {
        // URLs with explicit w_720,h_360 — swap dimensions inline.
        if (str_contains($image, 'w_720,h_360')) {
            $small = str_replace('w_720,h_360', 'w_360,h_180', $image);
            $medium = str_replace('w_720,h_360', 'w_634,h_317', $image);

            return ' srcset="' . FrontendLayout::e($small) . ' 360w, ' . FrontendLayout::e($medium)
                . ' 634w, ' . FrontendLayout::e($image) . ' 720w"'
                . ' sizes="(min-width: 1080px) 330px, (min-width: 640px) 520px, 100vw"';
        }

        // Cloudinary URL without transforms — insert c_fill crops after upload/.
        $uploadPos = strpos($image, '/upload/');
        if (false === $uploadPos) {
            return '';
        }

        $prefix = substr($image, 0, $uploadPos + strlen('/upload/'));
        $rest = substr($image, $uploadPos + strlen('/upload/'));

        $small = $prefix . 'w_360,h_180,c_fill/' . $rest;
        $medium = $prefix . 'w_634,h_317,c_fill/' . $rest;
        $full = $prefix . 'w_720,h_360,c_fill/' . $rest;

        return ' srcset="' . FrontendLayout::e($small) . ' 360w, ' . FrontendLayout::e($medium)
            . ' 634w, ' . FrontendLayout::e($full) . ' 720w"'
            . ' sizes="(min-width: 1080px) 330px, (min-width: 640px) 520px, 100vw"';
    }

    /**
     * Resolve the blog's active locale from the request.
     *
     * LocaleMiddleware has already resolved this: the 'locale' attribute holds
     * an explicit choice (URL prefix, X-Locale header, cookie, session) or the
     * configured default (settings.locale). There is no second opinion to add
     * here — the previous literal 'sk_SK' for non-explicit requests made the
     * blog render Slovak copy under `<html lang="en">`.
     */
    public static function requestLocale(Request $request): string
    {
        $locale = $request->getAttribute('locale');

        if (is_string($locale) && '' !== $locale) {
            return $locale;
        }

        return ContentServices::mapper()->defaultLocale();
    }

    /**
     * Storage subdirectory for a locale within the blog family.
     *
     * The default locale (settings.locale) reads storage/blog directly; every
     * other locale reads storage/blog/{lang}. Delegated to ContentLocaleMapper
     * so Blog, Pages and the CLI share one rule — no second copy of the layout.
     */
    public static function directoryFor(string $locale): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, ContentServices::mapper()->directory('blog', $locale));
    }

    private function avatar(): AuthorAvatar
    {
        return $this->avatar ??= new AuthorAvatar(new LocalStorageProvider());
    }
}
