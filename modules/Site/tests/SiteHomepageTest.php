<?php

declare(strict_types = 1);

namespace Modules\Site\Tests;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Interfaces\HTTP\Kernel;
use Modules\Core\Interfaces\HTTP\Router;
use Modules\Site\Application\SiteCopy;
use Modules\Site\Interfaces\HTTP\SiteHomepageSurface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shell\HomepageSurfaceRegistry;
use Themes\AssetManager;

/**
 * SiteHomepageTest — `/` answers the site's own page, in the visitor's language.
 *
 * Measured before this module existed (2026-11-19, the responsive.sk artifact):
 * `/` served "Keep PHP. Drop the framework." and "Under the hood of Nativa",
 * because the landing came from the theme and its copy is partly written into
 * the theme's code. Translating rows could not reach it.
 *
 * Two things this pins that a copy test alone would not:
 *
 *   1. the ORDER of the port — the surface is discovered and used at all, not
 *      merely constructible;
 *   2. the locale — `/en` answers the English copy, which is what makes the
 *      language switcher in the shell honest on the front door.
 */
final class SiteHomepageTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(SiteHomepageSurface::class)) {
            $this->markTestSkipped('Site is not installed — the landing lives in this module.');
        }

        HomepageSurfaceRegistry::reset();
    }

    protected function tearDown(): void
    {
        HomepageSurfaceRegistry::reset();
    }

    #[Test]
    public function theLandingIsTheSitesOwnPageAndNotTheThemesPitch(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString('Weby a aplikácie', $html, 'The Slovak landing copy must render at `/`.');
        self::assertStringContainsString('id="kontakt"', $html, 'The closing call is the page\'s contact target.');
        self::assertStringNotContainsString('Drop the framework', $html, 'The framework pitch is not this site\'s home page.');
        self::assertStringNotContainsString('Under the hood of Nativa', $html);
    }

    #[Test]
    public function theHeadCarriesTheSitesTitleAndItsOwnDescription(): void
    {
        $html = $this->page('/');

        self::assertMatchesRegularExpression(
            '/<title>[^<]* ' . "\u{2014}" . ' weby a aplikácie na mieru<\/title>/',
            $html,
            'The landing title names what the site does, not only the site name.',
        );
        self::assertStringContainsString(
            '<meta name="description" content="Tvoríme weby, webové aplikácie a integrácie na mieru',
            $html,
            'The landing description comes from the surface, not from the site-wide fallback.',
        );
    }

    #[Test]
    public function theHeadDeclaresTheLanguageAlternatesAndTheShareImage(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<link rel="alternate" hreflang="sk"',
            $html,
            'A bilingual landing with no `sk` alternate is two languages a crawler cannot pair.',
        );
        self::assertStringContainsString('<link rel="alternate" hreflang="en"', $html);
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="x-default"',
            $html,
            'x-default is what a visitor from an unlisted language is served.',
        );
        self::assertMatchesRegularExpression(
            '/<meta property="og:image" content="http:\/\/[^"]+\/og\/home\.png">/',
            $html,
            'A shared link without og:image shows no image at all.',
        );
    }

    #[Test]
    public function theLandingCarriesThePublishedContactAddress(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            'href="mailto:ahoj@responsive.sk"',
            $html,
            'The closing call is where a visitor writes; an empty contact block is a dead end.',
        );
    }

    /**
     * The stack answers "what would you build this in", so it has to nest:
     * backend in PHP, with Nativa or Laravel under that language. A visitor who
     * reads a flat row of chips learns the vocabulary and not the pairing.
     */
    #[Test]
    public function theStackIsReadTopDownGroupThenLanguageThenTools(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<h3 class="site-stack__group-title">Backend</h3>',
            $html,
            'The stack groups what belongs to a layer before naming tools.',
        );
        self::assertStringContainsString('<h3 class="site-stack__group-title">Frontend</h3>', $html);

        $php = self::stackLine($html, 'PHP 8.5');
        foreach (['Nativa', 'Laravel', 'Symfony', 'Nette'] as $tool) {
            self::assertStringContainsString($tool, $php, $tool . ' is a PHP framework and belongs under PHP.');
        }

        $typescript = self::stackLine($html, 'TypeScript');
        foreach (['React', 'Svelte', 'Vue'] as $tool) {
            self::assertStringContainsString($tool, $typescript, $tool . ' belongs under TypeScript, not beside it.');
        }

        self::assertStringContainsString(
            '<span class="site-stack__line-title">SQLite</span>',
            $html,
            'A tool with no family of its own still has to appear.',
        );
        self::assertStringContainsString(
            'Keď sa dáta zmestia do jedného súboru',
            $html,
            'A tool name alone says what we have used, not when we reach for it.',
        );
    }

    /**
     * The blog is the only place that says how a tool was actually used, so the
     * name of a tool the blog has written about is a door to those posts. A name
     * with nothing behind it stays plain text.
     */
    #[Test]
    public function aToolTheBlogHasWrittenAboutOpensThosePosts(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<a class="site-stack__item-title site-stack__link" href="/blog/tags/nativa">Nativa</a>',
            $html,
            'Nativa has twelve posts behind it; the name should reach them.',
        );
        self::assertStringContainsString(
            'href="/blog/tags/php">PHP 8.5</a>',
            $html,
            'PHP is the language those posts are written in, so it links too.',
        );
        self::assertStringContainsString(
            'href="/blog/tags/laravel"',
            $html,
            'The Laravel post exists, so the framework name reaches it.',
        );
        self::assertStringNotContainsString(
            'href="/blog/tags/symfony"',
            $html,
            'No post carries that tag; /blog/tags/symfony answers 404.',
        );
    }

    /**
     * The homepage context carries no `blogHref`, so the CTA defaulted to
     * `/blog` in every language and `/en` handed its readers the Slovak posts.
     * The English page has to stay inside `/en`.
     */
    #[Test]
    public function theEnglishLandingKeepsItsBlogLinksInEnglish(): void
    {
        $html = $this->page('/en');

        self::assertStringContainsString(
            'href="/en/blog"',
            $html,
            'The English CTA belongs on the English blog.',
        );
        self::assertStringNotContainsString(
            'class="site-button" href="/blog"',
            $html,
            'The surface\'s own CTA must not fall back to the Slovak blog.',
        );
        self::assertStringNotContainsString(
            '/blog/tags/',
            $html,
            'The English blog has no posts, so tagging nothing is the honest answer.',
        );
    }

    /**
     * A tag link is only as good as the posts behind it: `/blog/tags/<tag>` with
     * nothing tagged answers 404, so the day the last post of a tag is retagged
     * or removed this fails instead of shipping a dead link from the front door.
     */
    #[Test]
    public function everyStackLinkPointsAtATagTheBlogActuallyUses(): void
    {
        $posts = glob(APP_BASE_PATH . '/storage/blog/*.md') ?: [];

        if ([] === $posts) {
            $this->markTestSkipped('No blog posts on this install — the links have nothing to point at yet.');
        }

        $tags = [];
        foreach ($posts as $post) {
            $frontMatter = (string) file_get_contents($post);

            if (preg_match('/^tags:\s*\[(.*?)\]/m', $frontMatter, $match) !== 1) {
                continue;
            }

            foreach (explode(',', $match[1]) as $tag) {
                $tags[trim($tag, " \t\"'")] = true;
            }
        }

        preg_match_all('#href="/blog/tags/([^"]+)"#', $this->page('/'), $matches);
        $links = array_values(array_unique($matches[1]));

        self::assertNotSame([], $links, 'The stack should link the names the blog has written about.');

        foreach ($links as $tag) {
            self::assertArrayHasKey(
                urldecode($tag),
                $tags,
                'Nothing in storage/blog is tagged "' . $tag . '", so that link answers 404.',
            );
        }
    }

    #[Test]
    public function theShareImageTheSurfaceNamesActuallyExists(): void
    {
        self::assertFileExists(APP_BASE_PATH . '/public' . SiteCopy::shareImage(), 'The head points at a file the deployment must serve.');
    }

    /**
     * A translated landing is its own page. Before this, `/en` carried the same
     * canonical and og:url as `/`, so the English page told a crawler it WAS the
     * Slovak one — and a canonical that contradicts `hreflang` wins, taking the
     * English home out of the index while every gate stayed green.
     */
    #[Test]
    public function theCanonicalIsTheLanguagesOwnUrlAndNotTheDefaultOne(): void
    {
        $base = Request::create('GET', '/')->absoluteUrl('/');

        self::assertSame($base, self::canonical($this->page('/')), 'The default locale owns `/`.');
        self::assertSame($base, self::canonical($this->page('/sk')), '`/sk` is an alias of `/`, and says so.');
        self::assertSame(
            $base . 'en/',
            self::canonical($this->page('/en')),
            'The English landing must not claim to be the Slovak one.',
        );
        self::assertStringContainsString(
            '<meta property="og:url" content="' . $base . 'en/">',
            $this->page('/en'),
            'og:url mirrors the canonical; a scraper that disagrees with it shows the wrong language.',
        );
    }

    #[Test]
    public function theEnglishRootRendersTheEnglishCopy(): void
    {
        $html = $this->page('/en');

        self::assertStringContainsString('Websites and apps', $html, '`/en` must answer English copy.');
        self::assertStringNotContainsString('Weby a aplikácie', $html, '`/en` must not answer the Slovak group.');
        self::assertStringContainsString(
            '<html lang="en"',
            $html,
            'English copy under `<html lang="sk">` is a Slovak page as far as a crawler knows.',
        );
    }

    /**
     * The markup under one line title, up to the end of that line — enough to
     * tell whether a tool sits inside the language or somewhere else on the page.
     */
    #[Test]
    public function everyToolCarriesTheMomentWeReachForItInBothLanguages(): void
    {
        $sk = $this->page('/');
        $en = $this->page('/en');

        $slovakNote = 'Keď má aplikácia vydržať roky a nemá so sebou ťahať aj framework.';
        $englishNote = 'When the application has to last for years without dragging a framework along.';

        self::assertStringContainsString($slovakNote, $sk, 'Nativa needs its sentence in Slovak.');
        self::assertStringContainsString($englishNote, $en, 'Nativa needs its sentence in English.');
        self::assertStringNotContainsString($slovakNote, $en, 'The English page must not fall back to the Slovak copy.');
    }

    #[Test]
    public function theAudienceSectionLinksToAReferencesPageThatAnswers(): void
    {
        if (!class_exists(\Modules\Pages\Interfaces\HTTP\Actions\PageViewAction::class)) {
            $this->markTestSkipped('Pages is not installed — the references page is a page.');
        }

        $slovak = $this->page('/');
        self::assertMatchesRegularExpression(
            '#<p class="site-section__more"><a href="(/referencie)">Referencie: čo si viete overiť</a></p>#',
            $slovak,
            'The audience cards claim a track record; the link must name the page behind it.',
        );

        // The link is only worth having when the page answers: a section that
        // points at a 404 is the defect this test exists to catch.
        self::assertStringContainsString(
            'Prečo tu nie sú logá',
            $this->page('/referencie'),
            'The references page must render its own body, not a shell around an empty page.',
        );

        $english = $this->page('/en');
        self::assertMatchesRegularExpression(
            '#<p class="site-section__more"><a href="(/en/references)">References: what you can check</a></p>#',
            $english,
            'The English landing keeps its references in English.',
        );

        self::assertStringContainsString(
            'Why there are no logos',
            $this->page('/en/references'),
            'The English references page is its own page, not a fallback to the Slovak one.',
        );
    }

    #[Test]
    public function theMeasuredRequestCountInTheCopyMatchesTheLanding(): void
    {
        // The copy states a measured number ("6 requestov"), so the markup has
        // to keep making exactly that many requests: the document, the deferred
        // bundle, the two preloaded fonts and the two icons a desktop browser
        // asks for. A theme or bundle change that adds a request must fail here
        // and send someone back to the measurement, not quietly falsify a
        // published number.
        $slovak = $this->page('/');
        $this->requireBuiltTheme($slovak);

        $english = $this->page('/en');

        self::assertMatchesRegularExpression('#(\d+) requestov#', $slovak, 'The landing states how many requests it makes.');
        self::assertMatchesRegularExpression('#(\d+) requests#', $english, 'The English landing states the same.');

        preg_match('#(\d+) requestov#', $slovak, $skCount);
        preg_match('#(\d+) requests#', $english, $enCount);

        self::assertSame((int) $skCount[1], self::requestsIn($slovak), 'The Slovak number is stale — re-measure before changing it.');
        self::assertSame((int) $enCount[1], self::requestsIn($english), 'The English number is stale — re-measure before changing it.');
    }

    /**
     * The hero carries the reference's orb, and the orb is COMPLETE in the
     * markup: the theme's script only takes the dot field over to move it, so
     * the stylesheet has to be the one that draws the field the deferred
     * bundle has not arrived for. It is decoration, so it is hidden from
     * assistive tech by the markup itself.
     */
    #[Test]
    public function theHeroCarriesTheOrbAsDecorationOnly(): void
    {
        $html = $this->page('/');

        self::assertStringContainsString(
            '<div class="site-orb" data-orb aria-hidden="true">',
            $html,
            'The hero must ship the orb box; the script fills the rings into it.',
        );
        // The mark is the tab's artwork, INLINED. An <img> here would be a
        // second request for a file the head already asks for, and the landing
        // states six.
        self::assertMatchesRegularExpression(
            '#<div class="site-orb" data-orb aria-hidden="true"><svg[^>]*class="site-orb__mark"#',
            $html,
            'The site mark belongs inside the orb, inlined from the favicon.',
        );
        self::assertStringNotContainsString('site-orb"><img', $html);
        self::assertStringContainsString('.site-orb{position:relative', $html, 'The orb needs its own box to size the rings.');
        // The field is a real child of the orb, so the stylesheet can draw it
        // before the script runs. `:empty` cannot: the orb always carries the
        // mark and the disc, so a rule that waits for it to be empty is dead.
        self::assertStringContainsString(
            '<span class="site-orb__field"></span>',
            $html,
            'The orb needs the box the stylesheet draws its dot field into.',
        );
        self::assertStringContainsString('.site-orb__field{', $html, 'Without the script the field must still be drawn.');
        self::assertStringNotContainsString('.site-orb:empty{', $html, 'The orb is never empty; that selector can never match.');
        self::assertStringContainsString(
            '.site-orb--live .site-orb__field{display:none}',
            $html,
            'Once the script builds its rings the static field must yield, or two dot grids read through each other.',
        );
    }

    /**
     * The stack's picture is the same tree the list renders, and it is NOT part
     * of the first paint: Mermaid arrives only when the figure is scrolled
     * into view, so the six-request number the copy states stays true.
     *
     * The figure ships as a chunk of a built theme's bundle, so a run that
     * renders an unbuilt theme skips: the section then renders without the
     * figure, which is the whole fallback.
     */
    #[Test]
    public function theStackDiagramIsTheSameTreeAndItIsNotInTheFirstPaint(): void
    {
        $html = $this->page('/');
        $this->requireBuiltTheme($html);

        self::assertStringContainsString('class="site-diagram"', $html, 'The stack section carries a picture of the tree.');
        // The attribute is escaped in the document: `"` reaches the browser as
        // `&quot;`, and the assertion reads the markup, not the DOM.
        self::assertStringContainsString('data-mermaid="flowchart LR', $html, 'The diagram is a Mermaid flowchart.');
        self::assertStringContainsString('Strom, na ktorom tento web stojí.', $html);

        foreach (['Backend', 'Frontend', 'Rozhrania'] as $group) {
            self::assertStringContainsString(
                '([&quot;' . $group . '&quot;])',
                $html,
                'The figure must be drawn from the copy the list renders, not from a second list: ' . $group . ' is missing.',
            );
        }

        // Lazily: the figure names the chunk in a data attribute and the inline
        // module imports it, so it is never declared as a <script src> and is
        // not a request the count has to carry.
        self::assertMatchesRegularExpression(
            '#<figure[^>]*data-diagram-chunk="/dist/[^"]+/assets/diagram-[^"]+\.js"#',
            $html,
            'The figure must name the diagram chunk its module imports.',
        );
        self::assertMatchesRegularExpression(
            '#<script type="module"[^>]*>.*?import\([^)]*dataset\.diagramChunk#s',
            $html,
            'The diagram chunk must be imported on demand, not loaded with the page.',
        );
        self::assertDoesNotMatchRegularExpression('#<script[^>]+src="[^"]*diagram-#', $html);
    }

    /**
     * The requests a first visit makes, as the markup declares them.
     */
    private static function requestsIn(string $html): int
    {
        $scripts = preg_match_all('#<script[^>]+src="[^"]+"#', $html);
        $preloads = preg_match_all('#<link[^>]+rel="preload"#', $html);
        $icons = preg_match_all('#<link[^>]+rel="icon"#', $html);

        return 1 + $scripts + $preloads + $icons;
    }

    private static function stackLine(string $html, string $title): string
    {
        preg_match(
            '/<(?:span|a) class="site-stack__line-title[^"]*"(?: href="[^"]*")?>' . preg_quote($title, '/') . '<\/(?:span|a)>(.*?)<\/ul><\/li>/s',
            $html,
            $match,
        );

        self::assertArrayHasKey(1, $match, $title . ' must have a line of its own in the stack.');

        return $match[1];
    }

    private static function canonical(string $html): string
    {
        preg_match('/<link rel="canonical" href="([^"]*)">/', $html, $match);

        return $match[1] ?? '';
    }

    private function page(string $path): string
    {
        $kernel = new Kernel(new Router([APP_BASE_PATH . '/src/Modules']));
        $response = $kernel->handle(Request::create('GET', $path));

        self::assertSame(200, $response->getStatusCode(), $path . ' must render.');

        return $response->getContent();
    }

    /**
     * The theme the page loads, or a skip when that theme has no build.
     *
     * The published request count and the stack diagram describe a built
     * deployment, not every theme the suite can be pointed at: the kernel
     * theme the database jobs seed has neither, and the diagram is a chunk of
     * the bundle. The name is read from the markup, so the test neither spells
     * a theme nor depends on a theme module.
     */
    private function requireBuiltTheme(string $html): void
    {
        $theme = 1 === preg_match('#/dist/([a-z0-9_-]+)/assets/#i', $html, $match) ? $match[1] : '';

        if ('' === $theme || !(new AssetManager($theme))->isAvailable()) {
            $this->markTestSkipped(
                'The landing claims describe a built deployment; this run renders '
                . ('' === $theme ? 'a theme without a bundle' : 'an unbuilt ' . $theme) . '.',
            );
        }
    }
}
