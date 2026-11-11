<?php

declare(strict_types = 1);

namespace Modules\Markdown\Application\Services;

use Modules\Markdown\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Markdown\Interfaces\HTTP\Markdown\MarkdownBlockRegistry;

/**
 * MarkdownRenderer — zero-dependency Markdown renderer for blog articles.
 *
 * Covers the starter-kit article subset on purpose (no full CommonMark):
 * YAML front matter, headings, paragraphs, fenced code blocks, blockquotes,
 * horizontal rules, unordered/ordered lists, pipe tables, bold/italic, code
 * spans and links. All text is HTML-escaped first and markup is applied on
 * top, so article content can never inject raw HTML.
 */
final class MarkdownRenderer
{
    /**
     * Diacritics folding map for heading anchors — Slovak/Czech Latin
     * letters → plain ASCII (same folding convention the FTS search uses
     * via unicode61). Both cases included: strtolower() is not multibyte-
     * safe, so the fold runs BEFORE the lowercase.
     */
    private const array DIACRITICS = [
        'á' => 'a', 'Á' => 'a', 'à' => 'a', 'À' => 'a', 'â' => 'a', 'Â' => 'a', 'ä' => 'a', 'Ä' => 'a',
        'ã' => 'a', 'Ã' => 'a', 'å' => 'a', 'Å' => 'a', 'ā' => 'a', 'Ā' => 'a', 'ă' => 'a', 'Ă' => 'a', 'ą' => 'a', 'Ą' => 'a',
        'é' => 'e', 'É' => 'e', 'è' => 'e', 'È' => 'e', 'ê' => 'e', 'Ê' => 'e', 'ë' => 'e', 'Ë' => 'e',
        'ě' => 'e', 'Ě' => 'e', 'ē' => 'e', 'Ē' => 'e', 'ė' => 'e', 'Ė' => 'e', 'ę' => 'e', 'Ę' => 'e',
        'í' => 'i', 'Í' => 'i', 'ì' => 'i', 'Ì' => 'i', 'î' => 'i', 'Î' => 'i', 'ï' => 'i', 'Ï' => 'i',
        'ī' => 'i', 'Ī' => 'i', 'į' => 'i', 'Į' => 'i', 'ĭ' => 'i', 'Ĭ' => 'i',
        'ó' => 'o', 'Ó' => 'o', 'ò' => 'o', 'Ò' => 'o', 'ô' => 'o', 'Ô' => 'o', 'ö' => 'o', 'Ö' => 'o',
        'õ' => 'o', 'Õ' => 'o', 'ō' => 'o', 'Ō' => 'o', 'ŏ' => 'o', 'Ŏ' => 'o',
        'ú' => 'u', 'Ú' => 'u', 'ù' => 'u', 'Ù' => 'u', 'û' => 'u', 'Û' => 'u', 'ü' => 'u', 'Ü' => 'u',
        'ů' => 'u', 'Ů' => 'u', 'ū' => 'u', 'Ū' => 'u', 'ű' => 'u', 'Ű' => 'u', 'ŭ' => 'u', 'Ŭ' => 'u',
        'ý' => 'y', 'Ý' => 'y', 'ÿ' => 'y', 'Ÿ' => 'y',
        'č' => 'c', 'Č' => 'c', 'ć' => 'c', 'Ć' => 'c', 'ç' => 'c', 'Ç' => 'c',
        'ď' => 'd', 'Ď' => 'd', 'đ' => 'd', 'Đ' => 'd',
        'ň' => 'n', 'Ň' => 'n', 'ñ' => 'n', 'Ñ' => 'n', 'ń' => 'n', 'Ń' => 'n',
        'ř' => 'r', 'Ř' => 'r', 'ŕ' => 'r', 'Ŕ' => 'r',
        'š' => 's', 'Š' => 's', 'ś' => 's', 'Ś' => 's', 'ş' => 's', 'Ş' => 's', 'ș' => 's', 'Ș' => 's',
        'ť' => 't', 'Ť' => 't', 'ț' => 't', 'Ț' => 't',
        'ž' => 'z', 'Ž' => 'z', 'ź' => 'z', 'Ź' => 'z', 'ż' => 'z', 'Ż' => 'z',
        'ľ' => 'l', 'Ľ' => 'l', 'ĺ' => 'l', 'Ĺ' => 'l', 'ł' => 'l', 'Ł' => 'l',
        'ğ' => 'g', 'Ğ' => 'g',
        'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'œ' => 'oe', 'Œ' => 'oe',
    ];

    /**
     * Slugify a human string (tag, category, heading) into a URL-safe slug
     * with the same diacritics folding the heading anchors use — č→c, š→s,
     * ž→z, … so Slovak values yield clean ASCII slugs. Shared by the blog
     * tags/categories routes and the heading anchors.
     */
    public static function slugify(string $text): string
    {
        $slug = strtolower(strtr($text, self::DIACRITICS));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Render Markdown to HTML.
     */
    public function render(string $markdown, ?MarkdownBlockContext $context = null): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = '';
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $block = $this->renderBlock($lines, $count, $i);
            $html .= $block['html'];
            $i = $block['next'];
        }

        // Feature placeholders (`{{form:slug}}` and friends) are expanded here,
        // after the HTML exists: a block works on rendered markup, and the
        // registry is what keeps this renderer free of any module import.
        return MarkdownBlockRegistry::expand($html, $context);
    }

    /**
     * Render one block starting at line $i. Always consumes at least one line.
     *
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderBlock(array $lines, int $count, int $i): array
    {
        $line = $lines[$i];

        // Blank line — separator only.
        if ('' === trim($line)) {
            return ['html' => '', 'next' => $i + 1];
        }

        // Fenced code block ``` / ~~~
        if (1 === preg_match('/^\s*(```|~~~)/', $line, $fenceMatch)) {
            return $this->renderFence($lines, $count, $i, $fenceMatch[1]);
        }

        // Heading ###### → #.
        if (1 === preg_match('/^(#{1,6})\s+(.*)$/', $line, $headingMatch)) {
            return $this->renderHeading($headingMatch, $i);
        }

        // Horizontal rule.
        if (1 === preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            return ['html' => "<hr>\n", 'next' => $i + 1];
        }

        // Blockquote — consecutive > lines merge into one paragraph.
        if (1 === preg_match('/^\s*>/', $line)) {
            return $this->renderBlockquote($lines, $count, $i);
        }

        // Pipe table — a | line followed by a |---| separator row.
        if (1 === preg_match('/^\s*\|/', $line)
            && isset($lines[$i + 1])
            && 1 === preg_match('/^\s*\|?\s*:?-{2,}:?\s*(?:\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
            return $this->renderTable($lines, $count, $i);
        }

        // Unordered list.
        if (1 === preg_match('/^\s*[-*+]\s+(.*)$/', $line)) {
            return $this->renderListItems($lines, $count, $i, '/^\s*[-*+]\s+(.*)$/', '<ul>', '</ul>');
        }

        // Ordered list.
        if (1 === preg_match('/^\s*\d+\.\s+(.*)$/', $line)) {
            return $this->renderListItems($lines, $count, $i, '/^\s*\d+\.\s+(.*)$/', '<ol>', '</ol>');
        }

        // Paragraph — accumulate until a blank line or a new block starts.
        return $this->renderParagraph($lines, $count, $i);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderFence(array $lines, int $count, int $i, string $fence): array
    {
        // Extract language from info string: ```php → 'php'
        $lang = '';
        if (1 === preg_match('/^\s*`{3,}\s*(\S+)/', $lines[$i], $langMatch)) {
            $lang = htmlspecialchars(strtolower($langMatch[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $i++;
        $code = [];

        while ($i < $count && 0 === preg_match('/^\s*' . preg_quote($fence, '/') . '/', $lines[$i])) {
            $code[] = $lines[$i];
            $i++;
        }
        $i++; // skip the closing fence

        $langAttr = '' !== $lang ? ' class="language-' . $lang . '"' : '';
        $dataLang = '' !== $lang ? ' data-lang="' . $lang . '"' : '';

        return [
            'html' => '<pre' . $dataLang . '><code' . $langAttr . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>' . "\n",
            'next' => $i,
        ];
    }

    /**
     * @param array<int, string> $match
     *
     * @return array{html: string, next: int}
     */
    private function renderHeading(array $match, int $i): array
    {
        $level = strlen($match[1]);
        $text = $this->inline($match[2]);
        // Decode entities AFTER stripping tags — inline() escapes first, so
        // the anchor and TOC text must see "A & B", not "A &amp; B" (which
        // would slug to "a-amp-b" and double-escape on output).
        $anchor = $this->headingAnchor(
            html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $level,
        );
        $id = '' !== $anchor ? ' id="' . $anchor . '"' : '';

        // A level-2 heading of the `Časť I > Title` shape is a section label,
        // not a sentence: split it so a theme can set the numbering quietly
        // instead of repeating it at display size on every section. The split
        // runs on the RAW heading text, before inline(), so a title carrying
        // `code` still splits; each part is inlined on its own, and the
        // separator span echoes the character that was written instead of
        // inserting one (no em dash comes out of this renderer).
        if (2 === $level) {
            $parts = preg_split('/\s+([>—–])\s+/u', $match[2], 2, PREG_SPLIT_DELIM_CAPTURE);

            if (is_array($parts) && 3 === count($parts)
                && '' !== trim($parts[0]) && '' !== trim($parts[2])
                && mb_strlen(trim($parts[0]), 'UTF-8') <= 24) {
                $text = '<span data-heading-prefix>' . $this->inline(trim($parts[0])) . '</span>'
                    . '<span data-heading-dash> ' . $this->inline(trim($parts[1])) . ' </span>'
                    . '<span data-heading-title>' . $this->inline(trim($parts[2])) . '</span>';
            }
        }

        return [
            'html' => '<h' . $level . $id . '>' . $text . '</h' . $level . '>' . "\n",
            'next' => $i + 1,
        ];
    }

    /**
     * Extract the "On this page" heading list (h2/h3 with a non-empty id)
     * from rendered article HTML — the same HTML the browser sees, so TOC
     * anchors always match the heading ids actually present.
     *
     * @return list<array{level: int, text: string, anchor: string}>
     */
    public function extractHeadings(string $html): array
    {
        if (false === preg_match_all('/<h([23])\s+id="([^"]+)"[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $headings = [];

        foreach ($matches as $match) {
            $headings[] = [
                'level'  => (int) $match[1],
                // inline() escapes first — decode so the TOC text is the
                // visible "A & B" and not re-escaped on output.
                'text'   => trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'anchor' => $match[2],
            ];
        }

        return $headings;
    }

    /**
     * Slugified anchor for a heading — h2/h3 only (TOC levels), diacritics
     * folded (č→c, š→s, ž→z, …) so Slovak headings yield clean ASCII slugs
     * (same convention as the FTS search diacritics folding). Empty string
     * when the heading is only punctuation/symbols (no usable id).
     */
    private function headingAnchor(string $text, int $level): string
    {
        if (2 !== $level && 3 !== $level) {
            return '';
        }

        // Fold diacritics FIRST (strtolower is not multibyte-safe), then
        // lowercase the now-ASCII slug.
        $slug = strtolower(strtr($text, self::DIACRITICS));
        $slug = (string) (preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? '');
        $slug = (string) (preg_replace('/[\s-]+/', '-', $slug) ?? '');

        return trim($slug, '-');
    }

    /**
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderBlockquote(array $lines, int $count, int $i): array
    {
        $quote = [];

        while ($i < $count && 1 === preg_match('/^\s*>/', $lines[$i])) {
            $quote[] = (string) preg_replace('/^\s*>\s?/', '', $lines[$i]);
            $i++;
        }

        return [
            'html' => '<blockquote><p>' . $this->inline(implode(' ', $quote)) . '</p></blockquote>' . "\n",
            'next' => $i,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderTable(array $lines, int $count, int $i): array
    {
        $rows = [];

        while ($i < $count && 1 === preg_match('/^\s*\|/', $lines[$i]) && '' !== trim($lines[$i])) {
            if (0 === preg_match('/^\s*\|?\s*:?-{2,}:?\s*(?:\|\s*:?-{2,}:?\s*)*\|?\s*$/', $lines[$i])) {
                $rows[] = array_map(trim(...), explode('|', trim($lines[$i], "| \t")));
            }
            $i++;
        }

        if ([] === $rows) {
            return ['html' => '', 'next' => $i];
        }

        $html = '<table><thead><tr>';
        foreach ($rows[0] as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        for ($r = 1, $rowCount = count($rows); $r < $rowCount; $r++) {
            $html .= '<tr>';
            foreach ($rows[$r] as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>' . "\n";

        return ['html' => $html, 'next' => $i];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderListItems(array $lines, int $count, int $i, string $itemPattern, string $openTag, string $closeTag): array
    {
        $items = [];

        while ($i < $count && 1 === preg_match($itemPattern, $lines[$i], $nextItem)) {
            $items[] = $this->inline($nextItem[1]);
            $i++;
        }

        return [
            'html' => $openTag . implode('', array_map(static fn (string $item): string => '<li>' . $item . '</li>', $items)) . $closeTag . "\n",
            'next' => $i,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{html: string, next: int}
     */
    private function renderParagraph(array $lines, int $count, int $i): array
    {
        $paragraph = [$lines[$i]];
        $i++;

        while ($i < $count
            && '' !== trim($lines[$i])
            && 0 === preg_match('/^\s*(?:#{1,6})\s/', $lines[$i])
            && 0 === preg_match('/^\s*(?:```|~~~)/', $lines[$i])
            && 0 === preg_match('/^\s*\|/', $lines[$i])
            && 0 === preg_match('/^\s*[-*+]\s/', $lines[$i])
            && 0 === preg_match('/^\s*\d+\.\s/', $lines[$i])
            && 0 === preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $lines[$i])
            && 0 === preg_match('/^\s*>/', $lines[$i])) {
            $paragraph[] = $lines[$i];
            $i++;
        }

        // A paragraph that is nothing but a block placeholder is not a
        // paragraph. `{{form:<slug>}}` expands to a `<section>`, so wrapping it
        // in `<p>` shipped `<p><section>` on every page carrying a form —
        // nesting the browser silently closes, one empty paragraph beside each
        // block. The placeholder is left bare and the expansion lands where its
        // own markup expects to. A placeholder INSIDE a sentence keeps its
        // paragraph, because that text is a sentence with a block in it.
        if (1 === \count($paragraph)
            && 1 === preg_match('/^\s*\{\{[a-z0-9_-]+:[^{}]*\}\}\s*$/', $paragraph[0])) {
            return ['html' => trim($paragraph[0]) . "\n", 'next' => $i];
        }

        // A source line ends where the reader should see a line end, in two
        // shapes: a sentence that is finished, because the rule is one sentence
        // per line and joining them would undo the rhythm, and a colon, which
        // introduces what the next line says. Any other break is a soft wrap
        // and joins with a space, and a blank line still ends the paragraph.
        // The `>` that used to mark a mid-sentence break was retired: the break
        // is a wrap, and a reader does not need to see it.
        $html = '';
        $breaks = false;

        foreach ($paragraph as $index => $line) {
            $line = rtrim($line);
            $html .= 0 === $index ? $this->inline($line) : ($breaks ? '<br>' : ' ') . $this->inline($line);
            $bare = rtrim($line, '*_`„“"\')]»');
            $breaks = 1 === preg_match('/[.!?…:]$/u', $bare);
        }

        return [
            'html' => '<p>' . $html . '</p>' . "\n",
            'next' => $i,
        ];
    }

    /**
     * Split YAML front matter (--- delimited) from the article body.
     *
     * @return array{meta: array<string, mixed>, body: string}
     */
    public function extractFrontMatter(string $markdown): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $meta = [];
        $body = [];

        if ('---' === trim($lines[0])) {
            $i = 1;
            $inMeta = true;
            $count = count($lines);

            while ($i < $count) {
                $line = $lines[$i];

                if ($inMeta && '---' === trim($line)) {
                    $inMeta = false;
                    $i++;

                    continue;
                }

                // Keys may carry hyphens (series-part, author-email, …).
                if ($inMeta && 1 === preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*):\s*(.*)$/', $line, $match)) {
                    $value = trim($match[2]);

                    if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                        $meta[$match[1]] = array_values(array_filter(
                            array_map(
                                static fn (string $part): string => trim(stripslashes($part), " \t\"'"),
                                explode(',', substr($value, 1, -1)),
                            ),
                            static fn (string $part): bool => '' !== $part,
                        ));
                    } elseif (in_array(strtolower($value), ['true', 'false'], true)) {
                        $meta[$match[1]] = 'true' === strtolower($value);
                    } else {
                        // Unescape the addslashes() quoting the file writers
                        // apply (BlogCrudService / PageFileStorage serialize) —
                        // without this, every load+save round trip grew
                        // backslashes in front of quotes (\" → \\").
                        $meta[$match[1]] = trim(stripslashes($value), " \t\"'");
                    }
                }

                if (!$inMeta) {
                    $body[] = $line;
                }
                $i++;
            }
        } else {
            $body = $lines;
        }

        return ['meta' => $meta, 'body' => implode("\n", $body)];
    }

    /**
     * Inline formatting for a single escaped text run.
     *
     * Order matters: HTML-escape → code spans → bold → italic → links.
     */
    private function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Code spans — keep content verbatim (already escaped above).
        $text = (string) (preg_replace_callback(
            '/`([^`]+)`/',
            static fn (array $m): string => '<code>' . $m[1] . '</code>',
            $text,
        ) ?? $text);

        // Bold **text** / __text__.
        $text = (string) (preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text);
        $text = (string) (preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text);

        // Italic *text* / _text_ — never inside a word.
        $text = (string) (preg_replace(
            '/(^|\s|&gt;|\()\*([^*\s][^*]*)\*(?=\s|$|<|[.,;:!?)])/',
            '$1<em>$2</em>',
            $text,
        ) ?? $text);

        // Links [label](url) — http(s), root-relative and anchor only.
        $text = (string) (preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function (array $m): string {
                $url = $m[2];

                if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, '/') && !str_starts_with($url, '#')) {
                    return $m[0];
                }

                return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $m[1] . '</a>';
            },
            $text,
        ) ?? $text);

        return $text;
    }
}
