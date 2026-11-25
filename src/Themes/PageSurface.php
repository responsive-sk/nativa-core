<?php

declare(strict_types = 1);

namespace Themes;

/**
 * PageSurface — the surface a page is, as one owned vocabulary.
 *
 * A page carries the CSS and the script of the surface it belongs to, so the
 * shell needs an answer to "which surface is this?". The answer is NOT
 * inferred from the URL: a URL is a module's route, and the kernel may not
 * know one (`make mago-guard` fails on the import that would let it). The
 * action that composes the page declares its surface instead, and every name
 * it may declare lives here — an action that spelled `'article'` itself would
 * be the second copy of a fact this class owns.
 *
 * `PAGE` is the whole-page answer and the default: a caller that passes no
 * surface asks for exactly the bytes the theme shipped before surfaces
 * existed, which is what keeps a single-sheet theme working unchanged.
 */
final class PageSurface
{
    /** The theme's complete sheet and script — the answer when nothing narrower is declared. */
    public const string PAGE = 'page';

    /** The landing page: the theme's homepage sections, no listing and no article rules. */
    public const string HOME = 'home';

    /** An index of many items: the blog index, a tag, a category, an author, the docs index. */
    public const string LISTING = 'listing';

    /** One long-form reading page: the reading rail, the article body and its comments. */
    public const string ARTICLE = 'article';

    /**
     * Every surface, in the order a budget file reads best.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PAGE, self::HOME, self::LISTING, self::ARTICLE];
    }

    /**
     * Whether a caller declared a surface this tree knows.
     *
     * An unknown name is not an error at the shell: it falls back to `PAGE`,
     * the complete sheet, so a stale action renders styled rather than bare.
     */
    public static function isKnown(string $surface): bool
    {
        return in_array($surface, self::all(), true);
    }

    /**
     * The surface an undeclared caller gets, or the declared one when it is known.
     */
    public static function orPage(?string $surface): string
    {
        if (null === $surface || '' === $surface || !self::isKnown($surface)) {
            return self::PAGE;
        }

        return $surface;
    }
}
