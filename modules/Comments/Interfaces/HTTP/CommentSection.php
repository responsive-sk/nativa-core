<?php

declare(strict_types = 1);

namespace Modules\Comments\Interfaces\HTTP;

use Modules\Comments\Domain\Entities\Comment;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Users\Domain\Entities\User;

/**
 * CommentSection — public comments list + comment form for the article page.
 *
 * Rendered by BlogArticleAction under the article body. Approved comments
 * are listed oldest-first with escaped plain-text bodies; the form shows
 * guest name/email fields for anonymous readers and a name-only attribution
 * line for signed-in users. Form errors (from CommentSubmitAction) render
 * inline above the form.
 */
final class CommentSection
{
    /**
     * @param list<string>          $errors
     * @param array<string, string> $old
     * @param string                $prefix CSS class/id prefix — `st` for the
     *                                      starter vocabulary (default) or `er` for
     *                                      the erik theme (each theme styles its own
     *                                      vocabulary; the markup contract is shared)
     */
    public static function render(string $slug, array $errors = [], array $old = [], string $prefix = 'st'): string
    {
        $comments = CommentsServices::comments()->approved($slug);
        $list = self::list($comments, $prefix);
        $form = self::form($slug, CommentsServices::auth()->currentUser(), $errors, $old, $prefix);
        $errorHtml = self::errors($errors, $prefix);

        return '<section id="comments" class="' . $prefix . '-comments">'
            . '<h2 class="' . $prefix . '-comments__title">Comments <span class="' . $prefix . '-comments__count">('
            . count($comments) . ')</span></h2>'
            . $errorHtml
            . $list
            . $form
            . '</section>';
    }

    /**
     * @param list<Comment> $comments
     */
    private static function list(array $comments, string $prefix): string
    {
        if ([] === $comments) {
            return '<p class="' . $prefix . '-comments__empty">No comments yet — be the first to react.</p>';
        }

        $items = '';

        foreach ($comments as $comment) {
            $name = FrontendLayout::e($comment->displayName());
            $date = FrontendLayout::e($comment->createdAt()->format('Y-m-d'));
            $body = FrontendLayout::e($comment->body());

            $items .= '<li class="' . $prefix . '-comments__item">'
                . '<div class="' . $prefix . '-comments__meta">' . $name . ' · ' . $date . '</div>'
                . '<div class="' . $prefix . '-comments__body">' . nl2br($body) . '</div>'
                . '</li>';
        }

        return '<ul class="' . $prefix . '-comments__list">' . $items . '</ul>';
    }

    /**
     * @param list<string>          $errors
     * @param array<string, string> $old
     */
    private static function form(string $slug, ?User $user, array $errors, array $old, string $prefix): string
    {
        $csrf = CsrfMiddleware::field();

        if (null !== $user) {
            $identity = '<p class="' . $prefix . '-comments__identity">Commenting as <strong>'
                . FrontendLayout::e($user->name()) . '</strong>.</p>';
            $guestFields = '';
        } else {
            $identity = '';
            $name = FrontendLayout::e($old['guest_name'] ?? '');
            $email = FrontendLayout::e($old['guest_email'] ?? '');
            $guestFields = '<label class="' . $prefix . '-comments__label" for="' . $prefix . '-comment-name">Name</label>'
                . '<input class="' . $prefix . '-comments__input" id="' . $prefix . '-comment-name" type="text" name="guest_name" value="' . $name
                . '" maxlength="120" required>'
                . '<label class="' . $prefix . '-comments__label" for="' . $prefix . '-comment-email">Email (never shown)</label>'
                . '<input class="' . $prefix . '-comments__input" id="' . $prefix . '-comment-email" type="email" name="guest_email" value="' . $email
                . '" maxlength="255" required>';
        }

        $body = FrontendLayout::e($old['body'] ?? '');

        return '<form class="' . $prefix . '-comments__form" method="post" action="' . FrontendLayout::e('/blog/' . $slug . '/comments') . '">'
            . $csrf
            . self::honeypot($prefix)
            . $identity
            . $guestFields
            . '<label class="' . $prefix . '-comments__label" for="' . $prefix . '-comment-body">Comment</label>'
            . '<textarea class="' . $prefix . '-comments__textarea" id="' . $prefix . '-comment-body" name="body" rows="5" maxlength="4000" required>'
            . $body . '</textarea>'
            . '<button class="' . $prefix . '-comments__button" type="submit">Post comment</button>'
            . '</form>';
    }

    /**
     * The honeypot field — hidden from readers, filled by bots that complete
     * every field they find. `CommentSubmitAction` discards such a submission
     * silently, so this field is a filter and never an input: it is not
     * required, it is not validated, and its value is read nowhere else.
     *
     * Markup mirrors `QuestionBlockRenderer`'s honeypot (same wrapper, label
     * and attributes), so the tree's two public forms cannot drift apart.
     */
    private static function honeypot(string $prefix): string
    {
        return '<!-- honeypot: leave blank — a bot that fills every field is silently discarded -->'
            . '<div class="' . $prefix . '-comments__hp" hidden>'
            . '<label class="' . $prefix . '-comments__label" for="' . $prefix . '-comment-website">Website</label>'
            . '<input class="' . $prefix . '-comments__input" id="' . $prefix . '-comment-website" type="text"'
            . ' name="comment_website" value="" autocomplete="off" tabindex="-1">'
            . '</div>';
    }

    /**
     * @param list<string> $errors
     */
    private static function errors(array $errors, string $prefix): string
    {
        $html = '';

        foreach ($errors as $error) {
            $html .= '<p class="' . $prefix . '-comments__error">' . FrontendLayout::e($error) . '</p>';
        }

        return $html;
    }
}
