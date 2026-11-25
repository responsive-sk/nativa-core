<?php

declare(strict_types = 1);

namespace Tests\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\LocalizedNav;
use Shell\ShellNavItem;

/**
 * LocalizedNavTest — one stored menu, every language.
 *
 * Measured on the release's `/en/references`: the header rendered
 * `href="/blog"`, so the Articles item sent an English reader to the Slovak
 * blog. The row is stored once, canonically, and the rewrite is the same rule
 * for every provider (see `LocalizedNav`).
 *
 * The negative half matters as much as the positive one: a rewrite that
 * touches external links, anchors or an already-prefixed path turns a working
 * menu into a broken one, and it does so silently.
 */
final class LocalizedNavTest extends TestCase
{
    #[Test]
    public function theDefaultLocaleKeepsTheStoredHrefs(): void
    {
        $items = [new ShellNavItem('Home', '/'), new ShellNavItem('Articles', '/blog')];

        $this->assertEquals($items, LocalizedNav::apply($items, ''), 'The default locale owns the bare path — nothing to rewrite.');
    }

    #[Test]
    public function everyInternalPathMovesUnderThePrefix(): void
    {
        $items = [
            new ShellNavItem('Home', '/', active: false),
            new ShellNavItem('Articles', '/blog', active: true, external: false, children: [
                new ShellNavItem('PHP', '/blog/tags/php'),
            ]),
            new ShellNavItem('Docs', '/docs', active: false, external: false, children: [
                new ShellNavItem('Getting started', '/docs/getting-started', active: false, external: false, children: [
                    new ShellNavItem('Deep', '/docs/a/b'),
                ]),
            ]),
        ];

        $english = LocalizedNav::apply($items, '/en');

        $this->assertSame('/en', $english[0]->href, 'The root becomes the locale home, not `/`.');
        $this->assertSame('/en/blog', $english[1]->href);
        $this->assertSame('/en/blog/tags/php', $english[1]->children[0]->href, 'Nested items are rewritten too.');
        $this->assertSame('/en/docs', $english[2]->href);
        $this->assertSame('/en/docs/a/b', $english[2]->children[0]->children[0]->href, 'The whole tree, not just two levels.');
    }

    #[Test]
    public function theActiveItemStaysActive(): void
    {
        $items = [new ShellNavItem('Articles', '/blog', active: true)];

        $english = LocalizedNav::apply($items, '/en');

        $this->assertSame('/en/blog', $english[0]->href);
        $this->assertTrue($english[0]->active, 'Localizing the href must not un-light the page the reader is on.');
    }

    #[Test]
    public function linksThatAreNotPathsOfOursAreLeftAlone(): void
    {
        $items = [
            new ShellNavItem('GitHub', 'https://github.com/responsive-sk/nativa-php-core', external: true),
            new ShellNavItem('Top', '#top'),
            new ShellNavItem('Search', '/blog?q=php'),
            new ShellNavItem('Protocol-relative', '//example.test/x'),
            new ShellNavItem('Already localized', '/en/blog'),
        ];

        $hrefs = array_map(
            static fn (ShellNavItem $item): string => $item->href,
            LocalizedNav::apply($items, '/en'),
        );

        $this->assertSame([
            'https://github.com/responsive-sk/nativa-php-core',
            '#top',
            '/en/blog?q=php',
            '//example.test/x',
            '/en/blog',
        ], $hrefs, 'A URL, an anchor, a query string and an already-prefixed path are not ours to rewrite.');
    }
}
