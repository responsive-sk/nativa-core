<?php

declare(strict_types = 1);

namespace Modules\Blog\Application;

use Modules\Blog\Application\Services\ArticleOwnership;
use Modules\Blog\Application\Services\ArticleWriteGuard;
use Modules\Blog\Application\Services\AuthorAvatar;
use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\BlogAuthorResolver;
use Modules\Blog\Application\Services\BlogCrudService;
use Modules\Blog\Application\Services\BlogFeedService;
use Modules\Blog\Application\Services\BlogSearchService;
use Modules\Blog\Application\Services\TaxonomyService;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;

/**
 * BlogServices — lazy cached service factories for blog actions.
 *
 * The Kernel instantiates action classes without dependency injection, so
 * actions resolve shared services through these factories (same pattern as
 * CardboardServices). Connections are cached by DatabaseConnectionManager.
 */
final class BlogServices
{
    private static ?BlogAuthorResolver $authorResolver = null;

    private static ?ArticleOwnership $ownership = null;

    private static ?ArticleWriteGuard $writeGuard = null;

    private static ?AuthorAvatar $avatar = null;

    private static ?BlogArticlesService $articles = null;

    private static ?BlogCrudService $crud = null;

    private static ?BlogSearchService $search = null;

    private static ?BlogFeedService $feed = null;

    private static ?TaxonomyService $taxonomy = null;

    public static function authorResolver(): BlogAuthorResolver
    {
        return self::$authorResolver ??= new BlogAuthorResolver(
            DatabaseConnectionManager::instance(),
        );
    }

    /**
     * The per-article ownership predicate.
     *
     * It needs the author resolver (to turn an article's `author_email` into a
     * users.id), so it is built here rather than newed up at each call site —
     * a resolver with its own cached state must not be duplicated.
     */
    public static function ownership(): ArticleOwnership
    {
        return self::$ownership ??= new ArticleOwnership(self::authorResolver());
    }

    public static function avatar(): AuthorAvatar
    {
        return self::$avatar ??= new AuthorAvatar(new LocalStorageProvider());
    }

    /**
     * The ownership-aware entry point for article writes.
     *
     * Every caller that changes an article goes through this rather than
     * `crud()` directly, so the per-article rule cannot be forgotten by a new
     * action or bulk handler. `crud()` stays the raw file gateway.
     */
    public static function writeGuard(): ArticleWriteGuard
    {
        return self::$writeGuard ??= new ArticleWriteGuard(self::crud(), self::ownership());
    }

    public static function articles(): BlogArticlesService
    {
        return self::$articles ??= new BlogArticlesService(self::authorResolver());
    }

    /**
     * `Title ... Site` for document titles and feeds.
     *
     * The brand is the site's, read through the kernel shell port — the same
     * answer FrontendLayout puts in <head>. Hardcoding one here made every
     * blog title claim a name the rest of the page did not use the moment a
     * different site name was configured.
     */
    /**
     * The word this module calls itself, for the surfaces that have to name it:
     * the structured data on the two listings, and the tests that assert it.
     * One owner, so the next surface does not spell it a fourth time.
     */
    public static function label(): string
    {
        return 'Blog';
    }

    public static function brandSuffix(): string
    {
        return \Modules\Core\Application\Services\ShellContextProviderRegistry::siteName()
            ?? \Modules\Core\Interfaces\HTTP\FrontendLayout::DEFAULT_SITE_NAME;
    }

    public static function crud(): BlogCrudService
    {
        return self::$crud ??= new BlogCrudService();
    }

    public static function search(): BlogSearchService
    {
        return self::$search ??= new BlogSearchService(self::articles(), AppPaths::instance());
    }

    public static function feed(): BlogFeedService
    {
        return self::$feed ??= new BlogFeedService();
    }

    public static function taxonomy(): TaxonomyService
    {
        return self::$taxonomy ??= new TaxonomyService();
    }

    /**
     * Reset cached instances (test isolation).
     */
    public static function reset(): void
    {
        self::$authorResolver = null;

        self::$ownership = null;
        self::$writeGuard = null;
        self::$avatar = null;
        self::$articles = null;
        self::$crud = null;
        self::$search = null;
        self::$feed = null;
        self::$taxonomy = null;
    }
}
