<?php

declare(strict_types = 1);

namespace Modules\Site\Interfaces\HTTP;

use Themes\AssetManager;
use Shell\AsHomepageSurface;
use Shell\Homepage;
use Shell\HomepageSurface;
use Shell\PageContext;
use Modules\ContentLocale\Interfaces\HTTP\ContentLocaleServices;
use Modules\Site\Application\SiteCopy;
use View\InlineCss;
use View\InlineJs;

/**
 * SiteHomepageSurface — the landing page this site owns, not the framework's.
 *
 * The kernel answers `/` with whatever the active theme composes, and every
 * bundled theme composes the Nativa pitch: measured 2026-11-19, the
 * responsive.sk artifact served "Keep PHP. Drop the framework." as its front
 * door, because that copy is spelled out inside `BosonTheme` and no translation
 * row could reach it.
 *
 * This surface replaces the CONTENT and the head it can name; the route, the
 * shell (header, footer, menu, language switcher), the canonical URL and the
 * `WebSite` JSON-LD stay the kernel's. It renders its own `site-` classes with a
 * small inline stylesheet, so it does not depend on a theme's vocabulary and a
 * theme swap does not silently unstyle the page.
 *
 * Words live in `SiteCopy` — one array per locale. Nothing here is translated
 * through `locales.db`: a landing page is a handful of sentences that belong to
 * the site, and a missing row would leave a hole rather than a sentence.
 */
#[AsHomepageSurface]
final class SiteHomepageSurface implements HomepageSurface
{
    public function homepage(PageContext $context): Homepage
    {
        $siteName = $context->copy['title'] ?? 'responsive.sk';
        $copy = SiteCopy::for($context->locale, $siteName);

        return new Homepage(
            content: self::stylesheet() . self::sections($copy, $context),
            title: is_string($copy['seo']['title'] ?? null) ? $copy['seo']['title'] : null,
            description: is_string($copy['seo']['description'] ?? null) ? $copy['seo']['description'] : null,
            lang: is_string($copy['lang'] ?? null) ? $copy['lang'] : null,
            // Absolute: a crawler does not resolve `og:image` against the page.
            ogImage: $context->request->absoluteUrl(SiteCopy::shareImage()),
            hreflang: self::hreflang($context),
        );
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function sections(array $copy, PageContext $context): string
    {
        return '<div class="site-home">'
            . self::hero($copy, $context)
            . self::cards(
                'sluzby',
                self::text($copy, 'services', 'label'),
                self::text($copy, 'services', 'title'),
                is_array($copy['services']['items'] ?? null) ? $copy['services']['items'] : [],
                numbered: false,
            )
            . self::cards(
                'postup',
                self::text($copy, 'process', 'label'),
                self::text($copy, 'process', 'title'),
                is_array($copy['process']['items'] ?? null) ? $copy['process']['items'] : [],
                numbered: true,
            )
            . self::cards(
                'pre-koho',
                self::text($copy, 'audience', 'label'),
                self::text($copy, 'audience', 'title'),
                is_array($copy['audience']['items'] ?? null) ? $copy['audience']['items'] : [],
                numbered: false,
                link: is_array($copy['audience']['link'] ?? null) ? $copy['audience']['link'] : null,
            )
            . self::stack($copy, $context)
            . self::contact($copy)
            . '</div>';
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function hero(array $copy, PageContext $context): string
    {
        $blogHref = self::blogHref($context);

        return '<section class="site-hero">'
            . '<div class="site-wrap site-hero__inner">'
            . '<div class="site-hero__copy">'
            . '<p class="site-hero__eyebrow">' . self::e(self::text($copy, 'hero', 'eyebrow')) . '</p>'
            . '<h1 class="site-hero__title">'
            . '<span class="site-hero__title-line">' . self::e(self::text($copy, 'hero', 'title')) . '</span>'
            . '<span class="site-hero__title-accent">' . self::e(self::text($copy, 'hero', 'accent')) . '</span>'
            . '</h1>'
            . '<p class="site-hero__text">' . self::e(self::text($copy, 'hero', 'text')) . '</p>'
            . '<p class="site-hero__actions">'
            . '<a class="site-button site-button--primary" href="#kontakt">'
            . self::e(self::text($copy, 'hero', 'primary')) . '</a>'
            . '<a class="site-button" href="' . self::e($blogHref) . '">'
            . self::e(self::text($copy, 'hero', 'secondary')) . '</a>'
            . '</p>'
            . '</div>'
            . '<div class="site-hero__visual">'
            // The reference's signature mark: a field of points that reacts to
            // the pointer. The field itself is drawn by the stylesheet below,
            // so the box is never a hole while the deferred theme bundle is on
            // its way — or if it never arrives. The script only takes the field
            // over, to move it; nothing is downloaded for the behaviour beyond
            // the bundle the page already loads. `aria-hidden`: it is
            // decoration.
            . '<div class="site-orb" data-orb aria-hidden="true">' . self::mark()
            . '<span class="site-orb__core"></span>'
            . '<span class="site-orb__field"></span></div>'
            . '</div>'
            . '</div>'
            // The band the reference closes its hero with: a ruled line and one
            // sentence telling a visitor the page keeps going. It is a link, not
            // a caption, so it also works without the pointer.
            . '<a class="site-discover" href="#sluzby"><span class="site-wrap site-discover__inner">'
            . '<span>' . self::e(self::text($copy, 'hero', 'discover')) . '</span>'
            . '<span class="site-discover__arrow" aria-hidden="true">' . "\u{2193}" . '</span>'
            . '</span></a>'
            . '</section>';
    }

    /**
     * The heading every band shares: a `//` label, the section's one title and,
     * when the copy has a sentence for it, the lead that sits beside the title.
     *
     * A section with no lead is a title across the full measure — the same
     * shape the reference gives its ruled bands, and the reason a section does
     * not need an empty second column to look unfinished.
     */
    private static function head(string $label, string $title, string $lead = ''): string
    {
        return '<header class="site-head">'
            . '<p class="site-head__label">' . self::e($label) . '</p>'
            . '<h2 class="site-head__title">' . self::e($title) . '</h2>'
            . ('' === $lead ? '' : '<p class="site-head__lead">' . self::e($lead) . '</p>')
            . '</header>';
    }

    /**
     * The site mark in the middle of the orb: the same artwork the browser tab
     * carries, inlined rather than linked.
     *
     * An `<img src="/favicon.svg">` here would be a SECOND request for a file
     * the head already asks for — measured 2026-11-19, that is exactly the
     * seven-request landing the copy's "6 requestov" cannot claim. The SVG is
     * 4.8 kB of path data, it is decoration inside an `aria-hidden` box, and
     * the file's own comment (which explains the artwork) is stripped on the
     * way in.
     */
    private static function mark(): string
    {
        static $mark = null;

        if (null === $mark) {
            $path = APP_BASE_PATH . '/public/favicon.svg';
            $svg = is_file($path) ? (string) file_get_contents($path) : '';
            $svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);
            $svg = preg_replace(
                '/<svg\b([^>]*)>/',
                '<svg$1 class="site-orb__mark" aria-hidden="true" focusable="false">',
                $svg,
                1,
            );

            $mark = is_string($svg) && str_starts_with(trim($svg), '<svg') ? trim($svg) : '';
        }

        return $mark;
    }

    /**
     * One labelled band of cards — the shape every content section shares.
     *
     * @param list<array{title: string, text: string}> $items
     */
    private static function cards(string $id, string $label, string $title, array $items, bool $numbered, ?array $link = null): string
    {
        $html = '<section class="site-section" id="' . self::e($id) . '">'
            . self::head($label, $title)
            . '<div class="site-band">'
            . '<span class="site-band__dots" aria-hidden="true"></span>'
            . '<div class="site-band__row site-band__row--' . count($items) . '">';

        foreach ($items as $index => $item) {
            $mark = $numbered
                ? '<span class="site-card__number">' . self::e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) . '</span>'
                : '<span class="site-card__mark" aria-hidden="true"></span>';

            $html .= '<article class="site-card">'
                . $mark
                . '<h3 class="site-card__title">' . self::e((string) ($item['title'] ?? '')) . '</h3>'
                . '<p class="site-card__text">' . self::e((string) ($item['text'] ?? '')) . '</p>'
                . '</article>';
        }

        $html .= '</div>'
            . '<span class="site-band__dots site-band__dots--right" aria-hidden="true"></span>'
            . '</div>';

        // The section's own link, when the copy names one: a claim that has a
        // page behind it should point at the page instead of restating it.
        $href = is_string($link['href'] ?? null) ? $link['href'] : '';
        $label = is_string($link['label'] ?? null) ? $link['label'] : '';

        if ('' !== $href && '' !== $label) {
            $html .= '<p class="site-section__more"><a href="' . self::e($href) . '">'
                . self::e($label) . '</a></p>';
        }

        return $html . '</section>';
    }

    /**
     * The technology tree: backend in PHP with Nativa or Laravel, frontend in
     * TypeScript with React, Svelte or Vue.
     *
     * A flat row of chips listed every word once — `PHP 8.5`, `SQLite`,
     * `JavaScript a TypeScript` — and a visitor could not tell which tool went
     * with which layer. The nesting is the answer to "what do you build this in",
     * so it is the markup, not a sentence explaining the markup.
     *
     * @param array<string, mixed> $copy
     */
    private static function stack(array $copy, PageContext $context): string
    {
        // Where the blog lives for this visitor. Same source as the hero's CTA,
        // so the two links cannot disagree about the blog's address.
        $blog = self::blogHref($context);

        $groups = is_array($copy['stack']['groups'] ?? null) ? $copy['stack']['groups'] : [];

        $html = '<section class="site-section" id="technologie">'
            . self::head(
                self::text($copy, 'stack', 'label'),
                self::text($copy, 'stack', 'title'),
                self::text($copy, 'stack', 'text'),
            )
            . self::diagram($copy, $context)
            . '<div class="site-band">'
            . '<span class="site-band__dots" aria-hidden="true"></span>'
            . '<div class="site-band__row site-band__row--' . count($groups) . ' site-stack">';

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $html .= '<section class="site-stack__group">'
                . '<h3 class="site-stack__group-title">' . self::e((string) ($group['title'] ?? '')) . '</h3>'
                . '<ul class="site-stack__lines">';

            foreach (is_array($group['lines'] ?? null) ? $group['lines'] : [] as $line) {
                $html .= is_array($line) ? self::stackLine($line, $blog) : '';
            }

            $html .= '</ul></section>';
        }

        $html .= '</div>'
            . '<span class="site-band__dots site-band__dots--right" aria-hidden="true"></span>'
            . '</div>';

        // The one number in this section, and it is about THIS page: a measured
        // sentence is the only kind of performance claim the copy is allowed to
        // make. The profile is part of the sentence so the number cannot be read
        // as "loads in 1 s everywhere".
        $fact = self::text($copy, 'stack', 'fact');

        if ('' !== $fact) {
            $html .= '<p class="site-section__fact">' . self::e($fact) . '</p>';
        }

        return $html . '</section>';
    }

    /**
     * The stack as one picture: a Mermaid flowchart built from the SAME copy
     * array the list below it renders, so the drawing and the text cannot
     * disagree about what the tree is.
     *
     * Rendered only when the bundle that draws it exists — the diagram is an
     * addition to the list, never a replacement, so a tree without
     * `public/dist/<theme>/` (a fresh checkout, a kernel-only install) renders
     * the section exactly as it did before this existed. Mermaid is 600 kB of
     * gzip: the figure carries the source in `data-mermaid`, and the inline
     * module imports the chunk only when the figure nears the viewport, so the
     * landing's first paint keeps its measured request count.
     *
     * @param array<string, mixed> $copy
     */
    private static function diagram(array $copy, PageContext $context): string
    {
        $source = self::diagramSource($copy);

        if ('' === $source) {
            return '';
        }

        $chunk = (new AssetManager($context->theme))->jsUrl('diagram');

        if (null === $chunk) {
            return '';
        }

        $caption = self::text($copy, 'stack', 'diagram_caption');
        $aria = self::e('' === $caption ? self::text($copy, 'stack', 'title') : $caption);

        return '<figure class="site-diagram" data-mermaid="' . self::e($source) . '" data-diagram-chunk="' . self::e($chunk) . '" aria-busy="true" aria-label="' . $aria . '">'
            . ('' === $caption ? '' : '<figcaption class="site-diagram__caption">' . self::e($caption) . '</figcaption>')
            . '</figure>'
            . '<script type="module" nonce="__CSP_NONCE_PLACEHOLDER__">'
            . InlineJs::file('Modules/Site/Interfaces/HTTP/assets/scripts/homepage-diagram.js')
            . '</script>';
    }

    /**
     * The Mermaid source for the stack: one node per group with its lines under
     * it, left to right. Item names stay out — a diagram that repeats the list
     * word for word is a picture of the list, not a map of the tree.
     *
     * Labels are content, so they are quoted and their own quotes escaped: a
     * title carrying `"` must not be able to end the label and start Mermaid
     * syntax of its choosing.
     *
     * @param array<string, mixed> $copy
     */
    private static function diagramSource(array $copy): string
    {
        $groups = is_array($copy['stack']['groups'] ?? null) ? $copy['stack']['groups'] : [];
        $nodes = [];
        $edges = [];

        foreach ($groups as $index => $group) {
            if (!is_array($group)) {
                continue;
            }

            $title = self::label((string) ($group['title'] ?? ''));

            if ('' === $title) {
                continue;
            }

            $groupNode = 'g' . $index;
            // A stadium, not a colour class: the palette belongs to the theme
            // the reader is in, and a classDef hardcoded here painted light
            // boxes on the dark site (measured 2026-11-19). Mermaid's markdown
            // strings (`**bold**`) need backticks the label cleaner strips.
            $nodes[] = $groupNode . '(["' . $title . '"])';

            foreach (is_array($group['lines'] ?? null) ? $group['lines'] : [] as $lineIndex => $line) {
                if (!is_array($line)) {
                    continue;
                }

                $lineTitle = self::label((string) ($line['title'] ?? ''));

                if ('' === $lineTitle) {
                    continue;
                }

                $lineNode = 'l' . $index . '_' . $lineIndex;
                $nodes[] = $lineNode . '["' . $lineTitle . '"]';
                $edges[] = '  ' . $groupNode . ' --> ' . $lineNode;
            }
        }

        if ([] === $edges) {
            return '';
        }

        return 'flowchart LR' . "\n"
            . implode("\n", array_map(static fn (string $node): string => '  ' . $node, $nodes)) . "\n"
            . implode("\n", $edges);
    }

    /**
     * One Mermaid label: trimmed, with the characters that would end a quoted
     * label neutralised.
     */
    private static function label(string $text): string
    {
        return trim(str_replace(['"', '`', '<', '>'], ['', '', '', ''], $text));
    }

    /**
     * One line of a group: a language with the tools under it, or a tool that is
     * not a family — `SQLite` has no children and renders as itself.
     *
     * Every line and every item carries the moment we reach for it, because a
     * name alone tells a visitor what we have used and not what it is for.
     *
     * @param array<string, mixed> $line
     */
    private static function stackLine(array $line, string $blog): string
    {
        $html = '<li class="site-stack__line">'
            . self::stackName('site-stack__line-title', (string) ($line['title'] ?? ''), self::tagHref($blog, $line['tag'] ?? null))
            . self::stackNote('site-stack__line-note', $line['note'] ?? null);

        $items = is_array($line['items'] ?? null) ? $line['items'] : [];

        if ([] === $items) {
            return $html . '</li>';
        }

        $html .= '<ul class="site-stack__items">';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html .= '<li class="site-stack__item">'
                . self::stackName('site-stack__item-title', (string) ($item['title'] ?? ''), self::tagHref($blog, $item['tag'] ?? null))
                . self::stackNote('site-stack__item-note', $item['note'] ?? null)
                . '</li>';
        }

        return $html . '</ul></li>';
    }

    private static function stackName(string $class, string $text, string $href = ''): string
    {
        return '' === $href
            ? '<span class="' . $class . '">' . self::e($text) . '</span>'
            : '<a class="' . $class . ' site-stack__link" href="' . self::e($href) . '">' . self::e($text) . '</a>';
    }

    /**
     * The tag page for a name, or nothing when the copy names no tag: a link to
     * `/blog/tags/<tag>` that no post carries is a 404, so the absence of a tag
     * has to mean an unlinked name rather than a broken one.
     */
    private static function tagHref(string $blog, mixed $tag): string
    {
        return is_string($tag) && '' !== trim($tag)
            ? $blog . '/tags/' . rawurlencode($tag)
            : '';
    }

    /**
     * The note is optional: copy that has no sentence for a tool still renders a
     * tool, so a half-written section is thin rather than broken.
     */
    private static function stackNote(string $class, mixed $note): string
    {
        return is_string($note) && '' !== trim($note)
            ? '<span class="' . $class . '">' . self::e($note) . '</span>'
            : '';
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function contact(array $copy): string
    {
        $contacts = SiteCopy::contacts();
        $email = $contacts['email'];
        $phone = $contacts['phone'];
        $rows = '';

        // The address is the call itself below, so it is not repeated as a row;
        // a phone number has no button of its own and stays a row.
        if ('' !== $phone) {
            $rows .= '<li><span>' . self::e(self::text($copy, 'contact', 'phone_label')) . '</span>'
                . '<a href="tel:' . self::e(preg_replace('/\s+/', '', $phone) ?? $phone) . '">' . self::e($phone) . '</a></li>';
        }

        return '<section class="site-contact" id="kontakt">'
            . '<p class="site-head__label">' . self::e(self::text($copy, 'contact', 'label')) . '</p>'
            . '<h2 class="site-contact__title">' . self::e(self::text($copy, 'contact', 'title')) . '</h2>'
            . '<p class="site-contact__text">' . self::e(self::text($copy, 'contact', 'text')) . '</p>'
            . ('' === $email ? '' : '<p class="site-contact__actions">'
                . '<a class="site-button site-button--primary" href="mailto:' . self::e($email) . '">'
                . self::e(self::text($copy, 'hero', 'primary')) . '</a>'
                . '<a class="site-button" href="mailto:' . self::e($email) . '">' . self::e($email) . '</a>'
                . '</p>')
            . ('' === $rows ? '' : '<ul class="site-contact__rows">' . $rows . '</ul>')
            . '</section>';
    }

    /**
     * The blog in the visitor's language: `/blog` for the default locale,
     * `/en/blog` for English.
     *
     * `PageContext::$blogHref` is the port meant to carry this, but the homepage
     * context is built without one, so it answers `/blog` for every language and
     * `/en` sent its readers to the Slovak posts. The prefixes are the locator's
     * knowledge (`''` for the default, `/en` for the rest), so they come from the
     * ContentLocale mapper; no mapper means no prefix to claim.
     */
    private static function blogHref(PageContext $context): string
    {
        if (!class_exists(ContentLocaleServices::class)) {
            return rtrim($context->blogHref ?? '/blog', '/');
        }

        $prefix = ContentLocaleServices::mapper()->urlPrefix($context->locale);

        return rtrim($prefix . '/blog', '/');
    }

    /**
     * The page's language alternates: one absolute URL per published locale,
     * plus `x-default` pointing at the default locale's version.
     *
     * The prefixes are the locator's knowledge, not this module's, so they come
     * from the ContentLocale mapper (`''` for the default, `/en` for the rest).
     * Measured before this existed (2026-11-19): `/`, `/sk` and `/en` all served
     * the landing with one canonical and no alternates, so a crawler could not
     * tell the two languages apart at the front door, while `/blog` pages had
     * alternates from the Blog module's own pair logic.
     *
     * No mapper, no alternates: a tree without the module renders the page and
     * says nothing it cannot back up.
     *
     * @return array<string, string>
     */
    private static function hreflang(PageContext $context): array
    {
        if (!class_exists(ContentLocaleServices::class)) {
            return [];
        }

        $mapper = ContentLocaleServices::mapper();
        $base = $context->request->absoluteUrl('');
        $links = [];

        foreach ($mapper->locales() as $code) {
            $links[$mapper->languageCode($code)] = $base . $mapper->urlPrefix($code) . '/';
        }

        $default = $mapper->defaultLocale();
        $links['x-default'] = $base . $mapper->urlPrefix($default) . '/';

        return $links;
    }

    /**
     * @param array<string, mixed> $copy
     */
    private static function text(array $copy, string $section, string $key): string
    {
        $value = $copy[$section][$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The page's own stylesheet, inlined with the CSP nonce placeholder the
     * renderer replaces (the same technique `AuthTemplate` and the themes use).
     *
     * Deliberately self-contained: it styles the `site-` classes this file
     * emits and nothing else, so it neither depends on nor disturbs whichever
     * theme's shell wraps it.
     */
    private static function stylesheet(): string
    {
        return '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . "\n"
            . InlineCss::file('Modules/Site/Interfaces/HTTP/assets/styles/homepage.css')
            . '</style>';
    }
}
