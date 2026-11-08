<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\HTTP;

use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\Middleware\CsrfMiddleware;
use Modules\Locales\Interfaces\HTTP\LocalesServices;

/**
 * BlogInlineEditor — the textarea an article's author edits from the page.
 *
 * Module-owned markup, theme-styled, exactly like `CommentSection`: the form
 * posts to a Blog route and belongs to the Blog module, so every theme
 * rendering its own copy would be four copies to keep in step. The prefix is
 * the only thing the theme owns here, and each package ships the matching
 * styles.
 *
 * **Plain form, no JavaScript.** It posts to `/blog/{slug}/inline` and lands
 * back on the article with `?saved=1`. A progressive-enhancement HTMX path can
 * be added later without changing this contract.
 *
 * **Markdown, not WYSIWYG.** The textarea holds the article's raw Markdown,
 * which is what `BlogCrudService` stores and what `MarkdownRenderer` renders.
 * Nothing here interprets HTML, so the escape path is the same one the
 * non-JavaScript reader already uses.
 *
 * @internal
 *
 * @coversNothing
 */
final class BlogInlineEditor
{
    /**
     * @param string $prefix theme BEM prefix, from `ThemeModule::prefix()`
     */
    public static function render(string $prefix, string $slug, string $locale, string $body, string $label): string
    {
        $action = '/blog/' . rawurlencode($slug) . '/inline';

        // A COLLAPSED <details>, and it sits directly under the article
        // header. Both halves were found by measuring a real page: appended
        // after the body the editor sat 3,890px down a 5,177px document —
        // present, visible, and past the entire article, so the author who
        // opened their own post to fix a word scrolled past the whole post to
        // find the control. As a closed disclosure at the top it is one line
        // that says what it does, costs no vertical space, and needs no
        // JavaScript to open.
        return '<details class="' . $prefix . '-inline-edit" id="inline-edit">'
            . '<summary class="' . $prefix . '-inline-edit__summary">' . FrontendLayout::e($label) . '</summary>'
            . '<form class="' . $prefix . '-inline-edit__form" method="POST" action="' . FrontendLayout::e($action) . '">'
            . CsrfMiddleware::field()
            . '<input type="hidden" name="locale" value="' . FrontendLayout::e($locale) . '">'
            . '<label class="' . $prefix . '-inline-edit__label" for="inline-edit-body">' . FrontendLayout::e($label) . '</label>'
            // `spellcheck="false"` deliberately. The field holds Markdown SOURCE,
            // so a dictionary underlines `##`, `**`, URLs, code identifiers and
            // the authors' own slugs — with nothing left to trust the underline,
            // the squiggle is on almost every visible line and stops meaning
            // "typo". It also cannot be restyled: Chrome paints it, not the page.
            // Prose is checked where it is READ (the rendered article), not in
            // the markup that produces it.
            . '<textarea class="' . $prefix . '-inline-edit__textarea" id="inline-edit-body" name="body"'
            . ' rows="20" spellcheck="false" required>'
            . FrontendLayout::e($body)
            . '</textarea>'
            . '<div class="' . $prefix . '-inline-edit__actions">'
            . '<button type="submit" class="' . $prefix . '-inline-edit__save">Save</button>'
            . '<a class="' . $prefix . '-inline-edit__cancel" href="/blog/' . FrontendLayout::e(rawurlencode($slug))
            . '?locale=' . FrontendLayout::e(rawurlencode($locale)) . '">Close</a>'
            . '</div>'
            . '</form>'
            . '</details>';
    }

    /**
     * The confirmation a native form post lands on.
     *
     * `POST /blog/{slug}/inline` redirects to the article with `?saved=1`, and
     * without this the author is bounced back to the page with no sign the
     * text took — indistinguishable from a save that failed. That is the whole
     * reason the plain form is acceptable without JavaScript; an HTMX caller
     * gets the same string swapped in place by the POST route itself.
     *
     * A `role="status"` live region, because it is inserted above the editor
     * the reader is about to re-read.
     *
     * @param string $prefix theme BEM prefix, from `ThemeModule::prefix()`
     */
    public static function savedNotice(string $prefix, string $locale): string
    {
        return '<div class="' . $prefix . '-inline-edit__notice" role="status">'
            . FrontendLayout::e(LocalesServices::t('blog.saved', 'Saved.', $locale))
            . '</div>';
    }
}
