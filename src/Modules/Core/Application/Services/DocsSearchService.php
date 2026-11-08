<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

/**
 * DocsSearchService — search over the PUBLISHED guides, and nothing else.
 *
 * The blog has an FTS5 index because it has a corpus worth indexing: dozens of
 * articles, ranked with bm25, rebuilt per request. The guides here are a
 * catalogue of twenty-odd files that fits in memory many times over, so the
 * index would cost more than it saves. What this service shares with the blog
 * search is the boundary, and the boundary is the point: it reads through
 * `DocsCatalog`, so a document nobody published can never come back as a hit,
 * exactly as it cannot come back as a page.
 *
 * Ranking is deliberately plain and legible: a token found in a title counts
 * ten times a token found in the body, every token must appear somewhere (the
 * AND the blog's FTS query also requires), and a tie keeps catalogue order so
 * the list reads in the same sequence as the index. There is no stemming and no
 * fuzzy match: a guide is written in the same words it is searched for, and a
 * guess that returns the wrong guide is worse than a miss that says so.
 *
 * The query never reaches a parser. Tokens are extracted here, so quotes,
 * parentheses and operators are stripped with the punctuation that carries
 * them, and a hostile query degrades to the words it contains.
 */
final readonly class DocsSearchService
{
    /** Longer than any phrase a reader types into one field. */
    private const int MAX_QUERY_LENGTH = 100;

    /** Six tokens is a sentence, not a search. */
    private const int MAX_TOKENS = 6;

    /** How much of the matched line a result card shows. */
    private const int SNIPPET_LIMIT = 180;

    /** A title hit is worth this many body hits. */
    private const int TITLE_WEIGHT = 10;

    public function __construct(private DocsCatalog $catalog)
    {
    }

    /**
     * Published guides matching every token of the query, best match first.
     *
     * @return list<array{key: string, title: string, description: string}>
     */
    public function search(string $query): array
    {
        $tokens = $this->tokens($query);

        if ([] === $tokens) {
            return [];
        }

        $hits = [];

        foreach ($this->catalog->entries() as $entry) {
            $guide = $this->catalog->read($entry['key']);

            if (null === $guide) {
                continue;
            }

            $title = mb_strtolower($entry['title'], 'UTF-8');
            $body = mb_strtolower($guide['body'], 'UTF-8');
            $score = 0;

            // Every token has to stand somewhere: one that does not takes the
            // whole guide out of the list, which is the AND the blog's FTS
            // query also asks for.
            foreach ($tokens as $token) {
                $inTitle = $this->occurrences($title, $token);
                $inBody = $this->occurrences($body, $token);

                if (0 === $inTitle && 0 === $inBody) {
                    continue 2;
                }

                $score += $inTitle * self::TITLE_WEIGHT + $inBody;
            }

            $hits[] = [
                'key' => $entry['key'],
                'title' => $entry['title'],
                'description' => $this->snippet($guide['body'], $tokens) ?: $entry['description'],
                'score' => $score,
            ];
        }

        // PHP's sort has been stable since 8.0, so equal scores keep catalogue
        // order rather than a random one.
        usort($hits, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_map(
            static fn (array $hit): array => [
                'key' => $hit['key'],
                'title' => $hit['title'],
                'description' => $hit['description'],
            ],
            $hits,
        );
    }

    /**
     * How many times a search word stands on its own in the text.
     *
     * A plain substring count would make `or` a hit inside `for` and `not` a hit
     * inside `nothing`, which turns a two-letter word into "matches everything"
     * — the opposite of what the reader asked for. The boundaries are on letters
     * and digits rather than `\b`, so `boot` matches `boot` but not `booting`,
     * while `php2shell` still matches with its own digits inside.
     *
     * The parameter is named `$word` and not `$token` because a lint rule reads
     * `token` as a credential and demands `#[SensitiveParameter]` for it. A
     * search word is not a secret, and the attribute would have been the wrong
     * way to silence the rule.
     */
    private function occurrences(string $haystack, string $word): int
    {
        $count = preg_match_all(
            '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u',
            $haystack,
        );

        // The pattern is built from a quoted literal and is always valid, so a
        // failure here would be a broken UTF-8 subject rather than a bad regex;
        // no occurrences is the honest count for it either way.
        return false === $count ? 0 : $count;
    }

    /**
     * The query as lowercase tokens, split on everything that is not a letter
     * or a digit.
     *
     * A one-character token is dropped: it matches almost every guide and
     * ranks nothing, and it would let `q=a` return the whole catalogue.
     *
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query, 'UTF-8')) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') < 2) {
                continue;
            }

            if (!in_array($part, $tokens, true)) {
                $tokens[] = $part;
            }

            if (self::MAX_TOKENS === count($tokens)) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * The first prose line of the guide that carries a token, cut to card size.
     *
     * The line is taken from the BODY rather than from the description on the
     * card: a hit on a paragraph the reader cannot see on the index is the one
     * worth quoting, and quoting it is what tells them the match is real.
     *
     * @param list<string> $tokens
     */
    private function snippet(string $body, array $tokens): string
    {
        foreach (explode("\n", $body) as $line) {
            $text = $this->plain($line);

            if ('' === $text || str_starts_with($text, '#')) {
                continue;
            }

            $lower = mb_strtolower($text, 'UTF-8');

            foreach ($tokens as $token) {
                if (!str_contains($lower, $token)) {
                    continue;
                }

                if (mb_strlen($text, 'UTF-8') <= self::SNIPPET_LIMIT) {
                    return $text;
                }

                return rtrim(mb_substr($text, 0, self::SNIPPET_LIMIT - 1, 'UTF-8')) . '…';
            }
        }

        return '';
    }

    /** One line of plain text: inline markup resolved, whitespace collapsed. */
    private function plain(string $line): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $line);
        $text = str_replace(['**', '__', '`', '>'], '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
