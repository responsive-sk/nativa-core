<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Users\Domain\Entities\User;

/**
 * ArticleOwnership — may this user edit this article?
 *
 * `content.manage` opens the whole articles area, which is right for a site
 * with one writer and wrong for a site with several: an author could rewrite a
 * colleague's post, and the audit log would faithfully record that they did.
 *
 * Ownership needs NO new column. Articles already carry `author_email` in
 * their front matter and `BlogAuthorResolver` already resolves that email to a
 * live `users.id` for the public author pages — the link is maintained, it was
 * just never used for authorization.
 *
 * Two rules make this safe rather than merely restrictive:
 *
 *  - **admin edits everything.** Deleting a user would otherwise orphan every
 *    article they wrote, with nobody able to correct it. An admin always can.
 *  - **an article with no author_email is admin-only.** Ownership has to be
 *    provable, and "nobody claimed it" is not a claim \u2014 otherwise every
 *    unattributed article becomes claimable by the first author who opens it.
 *
 * @internal
 *
 * @coversNothing
 */
final readonly class ArticleOwnership
{
    public function __construct(private BlogAuthorResolver $resolver)
    {
    }

    /**
     * @param array<string, mixed> $meta Article front matter
     */
    public function canEdit(array $meta, User $user): bool
    {
        $email = $meta['author_email'] ?? null;

        return $this->canEditByEmail(is_string($email) ? $email : '', $user);
    }

    /**
     * The same check from the byline alone.
     *
     * The admin list already carries `author_email` on every row, so filtering
     * the index reads this instead of re-reading each article's front matter.
     */
    public function canEditByEmail(string $email, User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $email = trim($email);

        if ('' === $email) {
            return false;
        }

        $profile = $this->resolver->profile($email);

        return null !== $profile && $profile['id'] === $user->id();
    }

    /**
     * The refusal, as a response body. A 403 and not a redirect: a redirect
     * looks like success to the person who clicked, and they would only learn
     * the post was never saved by noticing it unchanged.
     */
    public function refusal(string $slug): string
    {
        return sprintf(
            '<div class="m-card"><h2 class="m-heading">Not your article</h2>'
            . '<p>You can edit articles you wrote, but <code>%s</code> belongs to another author. '
            . 'Ask an administrator if it should be reassigned.</p></div>',
            self::e($slug),
        );
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
