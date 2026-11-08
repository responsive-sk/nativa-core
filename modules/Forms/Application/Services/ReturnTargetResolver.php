<?php

declare(strict_types = 1);

namespace Modules\Forms\Application\Services;

use Modules\Blog\Application\BlogServices;
use Modules\Pages\Application\Services\PageCrudService;

/**
 * ReturnTargetResolver — where a submission is allowed to go back to.
 *
 * `form_return` is a hidden field, which makes it exactly as untrusted as a
 * query parameter: a form can be posted by a script that fills whatever it
 * likes. Writing it straight into a `Location` is an open redirect; using it
 * to re-render without checking is content the reader cannot see.
 *
 * So the path is accepted only if it is a LOCAL path naming a PUBLISHED page
 * or a published article, and the pair is re-resolved through the module that
 * owns the content (`PageCrudService`, `BlogServices`), the same delegation
 * `Questions\Application\Services\SubjectResolver` already makes.
 *
 * The resolver answers with the locale-free path (`/o-nas`, `/blog/foo`, `/`)
 * or null. Null is not an error here: the caller decides whether no target
 * means "fall back to the locale home" (success) or "answer 400" (failure).
 */
final readonly class ReturnTargetResolver
{
    /**
     * `/`, `/slug`, `/blog/slug`. No scheme, no host, no traversal: the two
     * leading characters cannot be `//`, and `.` is not in the character set.
     */
    private const string PATTERN = '#^/(?:blog/)?[a-z0-9][a-z0-9_-]*$#';

    public function resolve(string $path, string $locale): ?string
    {
        $path = trim($path);

        if ('/' === $path) {
            return '/';
        }

        if (1 !== preg_match(self::PATTERN, $path)) {
            return null;
        }

        if (str_starts_with($path, '/blog/')) {
            $slug = substr($path, \strlen('/blog/'));

            return BlogServices::crud()->isPublished($slug, $locale) ? $path : null;
        }

        $slug = substr($path, 1);

        return (new PageCrudService())->isPublished($slug, $locale) ? $path : null;
    }
}
