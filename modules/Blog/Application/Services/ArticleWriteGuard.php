<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Users\Domain\Entities\User;

/**
 * ArticleWriteGuard — the ONE place an article write is authorized.
 *
 * `BlogCrudService` is a file gateway: it reads and writes markdown and has no
 * opinion about who may do so. That is correct for a gateway and dangerous as
 * the only thing callers reach for — every caller has to remember the ownership
 * rule for itself, and forgetting is silent. It already was: five actions
 * carried their own `owns()` copy, and the two bulk endpoints carried none, so
 * an author could delete and unpublish a colleague's article through
 * `/mark/bulk-*` while every single-article route returned 403. Verified live.
 *
 * So the rule moves down here. Actions and bulk handlers both call this, and a
 * new caller gets ownership by construction rather than by remembering.
 *
 * A refusal is `false` / a refusal body — never a silent success and never a
 * redirect, because both read as "saved" to the person who clicked.
 *
 * @internal
 *
 * @coversNothing
 */
final readonly class ArticleWriteGuard
{
    public function __construct(
        private BlogCrudService $crud,
        private ArticleOwnership $ownership,
    ) {
    }

    /**
     * May this user write to this article?
     *
     * Null user means nobody is signed in — treated as "not the owner" rather
     * than an error, because the auth gate has already refused such a request.
     *
     * @return bool
     */
    public function owns(string $slug, string $locale, ?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        $article = $this->crud->find($slug, $locale);

        if (null === $article) {
            // Nothing to own. Callers treat "no article" as a no-op, not a
            // refusal, so this must not be reported as an ownership failure.
            return true;
        }

        return $this->ownership->canEdit($article['meta'], $user);
    }

    /**
     * Publish/unpublish — refuses when the caller does not own the article.
     */
    public function setPublished(string $slug, bool $published, string $locale, ?User $user): bool
    {
        if (!$this->owns($slug, $locale, $user)) {
            $this->logRefusal($slug, 'publish', $user);

            return false;
        }

        return $this->crud->setPublished($slug, $published, $locale);
    }

    /**
     * Delete — refuses when the caller does not own the article.
     */
    public function delete(string $slug, string $locale, ?User $user): bool
    {
        if (!$this->owns($slug, $locale, $user)) {
            $this->logRefusal($slug, 'delete', $user);

            return false;
        }

        return $this->crud->delete($slug, $locale);
    }

    /**
     * Save an edited article — refuses when the caller does not own it.
     *
     * @param array<string, mixed> $meta
     */
    public function save(string $slug, array $meta, string $body, string $locale, ?User $user): bool
    {
        if (!$this->owns($slug, $locale, $user)) {
            $this->logRefusal($slug, 'save', $user);

            return false;
        }

        $this->crud->save($slug, $meta, $body, $locale);

        return true;
    }

    /**
     * Partition a bulk selection into the ids this user may act on and the
     * ones they may not.
     *
     * A skip rather than a whole-request refusal: a selection can legitimately
     * mix rows (an admin's list, a stale page), and refusing everything punishes
     * the author for a row the UI should never have shown them. The count the
     * caller reports back is what keeps the skip visible instead of silent.
     *
     * @param list<int|string> $ids
     *
     * @return array{allowed: list<string>, refused: list<string>}
     */
    public function partition(array $ids, string $locale, ?User $user): array
    {
        $allowed = [];
        $refused = [];

        foreach ($ids as $id) {
            $slug = (string) $id;

            if ($this->owns($slug, $locale, $user)) {
                $allowed[] = $slug;

                continue;
            }

            $refused[] = $slug;
            $this->logRefusal($slug, 'bulk', $user);
        }

        return ['allowed' => $allowed, 'refused' => $refused];
    }

    private function logRefusal(string $slug, string $op, ?User $user): void
    {
        error_log(sprintf(
            '[ownership] refused slug=%s user=%s op=%s',
            $slug,
            $user?->id() ?? 'anonymous',
            $op,
        ));
    }
}
