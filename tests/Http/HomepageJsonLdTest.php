<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Actions\HomepageAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HomepageJsonLdTest — the home surface announces the site itself.
 *
 * `WebsiteStructuredData::website()` exists and every other indexed surface
 * uses a builder of its own, but `/` once passed a canonical and nothing else:
 * the reader's first page
 * was the one page a rich-result crawler learnt least from. nativa-minimal's
 * home carried Organization + WebSite, and this tree's did not.
 *
 * The assertions are deliberately about the contract rather than the sentence:
 * the two types, the site's own URL, and a SearchAction that points at a search
 * the tree actually answers. A test that pinned the name would fail on every
 * rename of the site, which is a setting, not a decision.
 */
final class HomepageJsonLdTest extends TestCase
{
    #[Test]
    public function theHomepageAnnouncesTheSiteWithOrganizationAndWebSite(): void
    {
        self::assertSame(
            ['Organization', 'WebSite'],
            array_column(self::nodes(self::homepage()), '@type'),
            'The home document carries exactly one block, and it is these two nodes.',
        );
    }

    #[Test]
    public function bothNodesCarryTheSiteNameAndItsAbsoluteUrl(): void
    {
        [$organization, $website] = self::nodes(self::homepage());

        self::assertNotSame('', self::str($organization, 'name'), 'Organization must be named.');
        self::assertSame(
            self::str($organization, 'name'),
            self::str($website, 'name'),
            'Both nodes name one site.',
        );

        foreach ([$organization, $website] as $node) {
            self::assertMatchesRegularExpression(
                '~^https?://[^/]+/$~',
                self::str($node, 'url'),
                'The home URL must be absolute and terminate at the root.',
            );
        }
    }

    #[Test]
    public function theWebsiteOffersTheSearchTheBlogActuallyAnswers(): void
    {
        [, $website] = self::nodes(self::homepage());

        $action = self::map($website, 'potentialAction');
        $target = self::map($action, 'target');

        self::assertSame('SearchAction', self::str($action, '@type'));
        self::assertSame('EntryPoint', self::str($target, '@type'));
        self::assertStringContainsString(
            '/blog?q={search_term_string}',
            self::str($target, 'urlTemplate'),
            'The target must be the search route the blog serves.',
        );
        self::assertSame('required name=search_term_string', self::str($action, 'query-input'));
    }

    #[Test]
    public function theBlockSitsInTheHeadAndCarriesTheCspNoncePlaceholder(): void
    {
        $html = self::homepage();

        $head = substr($html, 0, (int) strpos($html, '</head>'));

        self::assertStringContainsString(
            '<script type="application/ld+json" nonce="__CSP_NONCE_PLACEHOLDER__">',
            $head,
            'Inline scripts are CSP-guarded, so the block needs the nonce placeholder.',
        );
    }

    private static function homepage(): string
    {
        return (new HomepageAction())->handle(Request::create('GET', '/'))->getContent();
    }

    /**
     * The nodes of the document's JSON-LD block.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function nodes(string $html): array
    {
        $found = preg_match('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        self::assertSame(1, $found, 'The home document carries no JSON-LD block.');

        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'A JSON-LD block must decode to an array of nodes.');

        $nodes = [];
        foreach ($decoded as $node) {
            self::assertIsArray($node, 'Every node of the block is an object.');
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function str(array $node, string $key): string
    {
        $value = $node[$key] ?? null;
        self::assertIsString($value, sprintf('`%s` must be a string.', $key));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $node, string $key): array
    {
        $value = $node[$key] ?? null;
        self::assertIsArray($value, sprintf('`%s` must be an object.', $key));

        return $value;
    }
}
