<?php

declare(strict_types = 1);

namespace Modules\Questions\Interfaces\HTTP;

use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Questions\Application\QuestionsServices;
use Modules\Questions\Domain\Entities\Question;

/**
 * QuestionBlockRenderer — renders the reader-questions UI for articles and pages.
 *
 * The markup contract is shared and the classes are built from the ACTIVE
 * THEME'S PREFIX, exactly like `CommentSection::render(..., string $prefix)`:
 * a question on a magazine article is `mag-questions`, on starter
 * `st-questions`. Every theme therefore ships its own `questions.css`, stamped
 * from the starter copy by `bin/generate/generate-questions-css.mjs`.
 *
 * The block carries no persistence logic beyond the approved read it performs
 * — it renders what the repository hands over, and the repository hands over
 * what is APPROVED. Nothing a guest writes is public until an admin approves
 * it in `/mark/questions`, which is what makes this surface shippable at all.
 *
 * Security posture:
 *  - CSRF token via CsrfMiddleware::field() (enforced globally on POST).
 *  - A honeypot field (question_website) verified server-side: a filled-in
 *    honeypot is silently discarded.
 *  - Question and attempted answer render as ESCAPED plain text — a reader's
 *    own words are data, never markup.
 *
 * The subject is the DISCRIMINATED PAIR (type + slug + locale), never a bare
 * slug: `/blog/foo` and `/foo` are two different subjects and their questions
 * must never merge.
 */
final class QuestionBlockRenderer
{
    /**
     * Render the full question block: heading, confirmation, approved list and
     * the submission form.
     *
     * @param string       $subjectSlug the slug of the article/page
     * @param string       $subjectType 'article' or 'page'
     * @param string       $locale      the subject's locale
     * @param array<mixed> $errors      form validation errors
     * @param array<mixed> $old         previous submission values
     * @param string       $prefix      CSS prefix — `st` for the starter
     *                                  vocabulary (default) or the theme's
     *                                  own prefix
     * @param bool         $sent        the `question-sent` PRG marker is
     *                                  present: paint the confirmation
     */
    public static function render(
        string $subjectSlug,
        string $subjectType,
        string $locale,
        array $errors = [],
        array $old = [],
        string $prefix = 'st',
        bool $sent = false,
    ): string {
        $approved = QuestionsServices::repository()->findBySubject($subjectType, $subjectSlug, $locale);

        // findBySubject returns every status; only approved ones are public.
        $visible = array_values(array_filter(
            $approved,
            static fn (Question $q): bool => Question::STATUS_APPROVED === $q->status(),
        ));

        return '<section id="questions" class="' . $prefix . '-questions">'
            . self::heading($subjectType, count($visible), $prefix)
            . self::confirmation($sent, $prefix)
            . self::errors(self::strings($errors), $prefix)
            . self::list($visible, $prefix)
            . self::form($subjectType, $subjectSlug, $locale, self::fields($old), $prefix)
            . '</section>';
    }

    /**
     * The visitor state arrives as a REQUEST ATTRIBUTE, which is `mixed` by
     * construction — anything may have written it. Normalising here rather than
     * in each of the two actions keeps the cast in one place and keeps a
     * non-string field out of the rendered `value="…"`.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) || is_numeric($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function fields(array $values): array
    {
        $fields = [];

        foreach ($values as $field => $value) {
            if (is_string($field) && (is_string($value) || is_numeric($value))) {
                $fields[$field] = (string) $value;
            }
        }

        return $fields;
    }

    private static function heading(string $subjectType, int $count, string $prefix): string
    {
        $title = Question::SUBJECT_ARTICLE === $subjectType
            ? 'Reader questions'
            : 'Questions about this page';

        return '<h2 class="' . $prefix . '-questions__title">' . FrontendLayout::e($title)
            . ' <span class="' . $prefix . '-questions__count">(' . $count . ')</span></h2>';
    }

    /**
     * The PRG confirmation. The redirect that lands here is useless without it
     * — a reader who cannot tell their question arrived will ask again.
     *
     * @param list<Question> $questions Only approved questions — filtered in render().
     */
    private static function list(array $questions, string $prefix): string
    {
        if ([] === $questions) {
            return '<p class="' . $prefix . '-questions__empty">No questions yet — be the first to ask.</p>';
        }

        $items = '';

        foreach ($questions as $question) {
            $name = FrontendLayout::e($question->displayName());
            $date = FrontendLayout::e($question->createdAt()->format('Y-m-d'));
            $text = FrontendLayout::e($question->text());
            $answer = FrontendLayout::e($question->attemptedAnswer());

            $items .= '<li class="' . $prefix . '-questions__item">'
                . '<div class="' . $prefix . '-questions__meta">' . $name . ' · ' . $date . '</div>'
                . '<div class="' . $prefix . '-questions__body"><strong>Question</strong>: ' . nl2br($text) . '</div>'
                . '<div class="' . $prefix . '-questions__answer"><strong>What they tried</strong>: ' . nl2br($answer) . '</div>'
                . '</li>';
        }

        return '<ul class="' . $prefix . '-questions__list">' . $items . '</ul>';
    }

    /**
     * Render the submission form.
     *
     * @param array<string, string> $old
     */
    private static function form(string $subjectType, string $subjectSlug, string $locale, array $old, string $prefix): string
    {
        $csrf = CsrfMiddleware::field();
        $localePrefix = ContentServices::mapper()->urlPrefix($locale);
        $action = $localePrefix . '/questions/' . $subjectType . '/' . rawurlencode($subjectSlug);

        $displayName = FrontendLayout::e($old['display_name'] ?? '');
        $text = FrontendLayout::e($old['text'] ?? '');
        $answer = FrontendLayout::e($old['attempted_answer'] ?? '');
        $noun = Question::SUBJECT_ARTICLE === $subjectType ? 'article' : 'page';

        return '<form class="' . $prefix . '-questions__form" method="post" action="' . FrontendLayout::e($action) . '">'
            . $csrf
            . '<!-- honeypot: leave blank — a bot that fills every field is silently discarded -->'
            . '<div class="' . $prefix . '-questions__hp" hidden>'
            . '<label class="' . $prefix . '-questions__label" for="' . $prefix . '-question-website">Website</label>'
            . '<input class="' . $prefix . '-questions__input" id="' . $prefix . '-question-website" type="text"'
            . ' name="question_website" value="" autocomplete="off" tabindex="-1">'
            . '</div>'
            . '<p class="' . $prefix . '-questions__hint">Stuck on something in this ' . $noun
            . '? Ask below. Your name is shown publicly; your email is never stored. '
            . 'A question appears here once a moderator approves it.</p>'
            . '<label class="' . $prefix . '-questions__label" for="' . $prefix . '-question-name">Your name</label>'
            . '<input class="' . $prefix . '-questions__input" id="' . $prefix . '-question-name" type="text"'
            . ' name="display_name" value="' . $displayName . '" maxlength="120" required>'
            . '<label class="' . $prefix . '-questions__label" for="' . $prefix . '-question-text">Your question</label>'
            . '<textarea class="' . $prefix . '-questions__textarea" id="' . $prefix . '-question-text"'
            . ' name="text" rows="3" maxlength="2000" required>' . $text . '</textarea>'
            . '<label class="' . $prefix . '-questions__label" for="' . $prefix . '-question-answer">What did you try?</label>'
            . '<textarea class="' . $prefix . '-questions__textarea" id="' . $prefix . '-question-answer"'
            . ' name="attempted_answer" rows="3" maxlength="2000" required>' . $answer . '</textarea>'
            . '<button class="' . $prefix . '-questions__button" type="submit">Submit question</button>'
            . '</form>';
    }

    private static function confirmation(bool $sent, string $prefix): string
    {
        if (!$sent) {
            return '';
        }

        return '<p class="' . $prefix . '-questions__sent" role="status">Thank you — your question is with the author. '
            . 'It appears here once it is approved.</p>';
    }

    /**
     * @param list<string> $errors
     */
    private static function errors(array $errors, string $prefix): string
    {
        $html = '';

        foreach ($errors as $error) {
            $html .= '<p class="' . $prefix . '-questions__error">' . FrontendLayout::e($error) . '</p>';
        }

        return $html;
    }
}
