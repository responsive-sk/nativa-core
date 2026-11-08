<?php

declare(strict_types = 1);

namespace Modules\Questions\Application\Services;

use Modules\Blog\Application\BlogServices;
use Modules\Pages\Application\Services\PageCrudService;
use Modules\Questions\Domain\Entities\Question;

/**
 * SubjectResolver — resolves the questions module's discriminated subject pair
 * against REAL, PUBLISHED content.
 *
 * `subject_type` and `subject_slug` arrive in the URL path, which makes them
 * untrusted input like any other. Writing the pair straight into the row
 * would let anyone file questions against an article that does not exist, and
 * — worse — against a DRAFT the public cannot read, so the author's unread
 * inbox would carry questions to text no visitor has seen.
 *
 * This is the same rule the two rendering surfaces apply: `BlogArticleAction`
 * 404s an unpublished article and `PageViewAction` 404s an unpublished page.
 * A question against either would be a question about a page that does not
 * exist as far as a reader is concerned.
 *
 * The module owns the pair, not the storage: the resolution is delegated to
 * the module that owns each content type, so Questions never learns how a
 * markdown file is laid out. Only another module's APPLICATION layer is
 * reached for (never its Infrastructure or Interfaces) — the boundary
 * `ModuleIsolationTest` enforces.
 */
final readonly class SubjectResolver
{
    /**
     * Is this subject published content a reader could actually be reading?
     *
     * An unknown subject TYPE is refused here too: the constant is matched
     * against the two known values rather than interpolated into a query, so
     * a forged `subjectType` can never name a third kind of thing.
     *
     * @param string $subjectType 'article' or 'page'
     * @param string $subjectSlug the slug, unique only within the type
     * @param string $locale      the canonical locale code (`sk_SK`)
     */
    public function isPublished(string $subjectType, string $subjectSlug, string $locale): bool
    {
        if (Question::SUBJECT_ARTICLE === $subjectType) {
            return BlogServices::crud()->isPublished($subjectSlug, $locale);
        }

        if (Question::SUBJECT_PAGE === $subjectType) {
            return (new PageCrudService())->isPublished($subjectSlug, $locale);
        }

        return false;
    }
}
