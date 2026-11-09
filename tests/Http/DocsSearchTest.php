<?php

declare(strict_types = 1);

namespace Tests\Http;

use Modules\Core\Application\Services\DocsCatalog;
use Modules\Core\Application\Services\DocsSearchService;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Actions\DocsIndexAction;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
use Modules\Core\Interfaces\HTTP\ThemeKernel;
use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use Modules\Core\Interfaces\HTTP\ThemeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * DocsSearchTest — `GET /docs?q=` answers from the published guides, and the
 * header's field points at it while a reader is inside `/docs`.
 *
 * The surface had no search until now, so the header's field — which is the
 * theme's markup and searches `storage/blog` — sent a reader looking for a guide
 * into the articles, where the guide is not a document at all. Two things are
 * checked here because two things can regress separately: the answer (which
 * documents come back) and the destination (which surface the field submits to).
 *
 * @internal
 *
 * @coversNothing
 */
final class DocsSearchTest extends TestCase
{
    /** The theme-test idiom: the theme is named once, as every theme test names it. */
    private const string THEME = 'boson';

    /**
     * Every assertion here measures `boson`'s own markup: the header field it
     * ships, the `bo-` cards its index composes. A kernel-only tree renders the
     * fallback theme instead, which carries none of them, so the suite skips
     * rather than failing on a surface it cannot see. `ThemeKernel::for()`
     * answers with the kernel default when the theme module is absent, which is
     * the measurement: no module, no named theme.
     */
    protected function setUp(): void
    {
        if (ThemeKernel::for(self::THEME)->name() !== self::THEME) {
            $this->markTestSkipped(self::THEME . ' is not installed, so its header and index markup do not exist here.');
        }
    }

    /**
     * A word from a guide's body comes back with that guide's own URL, and
     * nothing that is not in `PUBLISHED` can appear in the list: the search and
     * the index are two views of one publication boundary, not two lists.
     */
    #[Test]
    public function aQueryAnswersFromThePublishedGuides(): void
    {
        $body = self::search('guide');

        self::assertMatchesRegularExpression(
            '#href="/docs/[a-z0-9/._-]+"#',
            $body,
            'The query returned no guide links at all, so the page proves nothing about the search.',
        );

        preg_match_all('#href="/docs/([a-z0-9/._-]+)"#', $body, $matches);

        $published = (new DocsCatalog())->keys();

        foreach ($matches[1] as $key) {
            self::assertContains(
                $key,
                $published,
                sprintf(
                    '"%s" came back from a query but is not a published key. A document nobody'
                    . ' published cannot be a page — and it cannot be a search hit either.',
                    $key,
                ),
            );
        }
    }

    /**
     * A source that matches nothing says so in its own words. The theme's index
     * empty state ("No guides are published in this deployment.") is true of an
     * empty deployment and false of a miss, which is why the results are composed
     * in the kernel's own vocabulary.
     */
    #[Test]
    public function aQueryWithNoHitsSaysSo(): void
    {
        $body = self::search('xyzzyplughqqq');

        self::assertStringContainsString('No guide matches', $body);
        self::assertStringNotContainsString(
            'No guides are published in this deployment.',
            $body,
            'A miss rendered the empty-deployment sentence, which tells the reader the guides are'
            . ' gone instead of that their word is not in them.',
        );
    }

    /**
     * The query comes back in the sentence, escaped. A page that echoes what was
     * typed unescaped is an injection point with a result list under it, and a
     * page that drops the query leaves a reader guessing what they asked.
     */
    #[Test]
    public function theQueryComesBackEscaped(): void
    {
        $body = self::search('prose"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertMatchesRegularExpression(
            '#(No guide matches|guides? matches?) "[^"]*prose#',
            $body,
            'The page lost the query it was asked to narrow, so the reader cannot see what it answered.',
        );
    }

    /**
     * The tokens are extracted here, so a query full of FTS syntax is a query of
     * words and nothing more: it neither throws nor turns into "match
     * everything", which is what an operator reaching the parser would do.
     */
    #[Test]
    public function syntaxInTheQueryIsOnlyPunctuation(): void
    {
        self::requirePublishedGuides();

        $service = new DocsSearchService(new DocsCatalog());

        self::assertSame(
            count($service->search('guide plugin')),
            count($service->search('"* OR (NOT guide) AND plugin')),
            'A query with FTS syntax and the same words in plain prose must answer the same;'
            . ' anything else means an operator reached something that parsed it.',
        );
        self::assertNotSame(0, count($service->search('guide')), 'The control query finds nothing, so the comparison above is between two empty lists.');
    }

    /**
     * Inside `/docs` the header's field submits to `/docs`; everywhere else it
     * keeps the destination the theme shipped with. The theme is a copy of
     * `nativa-themes` (`make themes-check`), so this routing lives in the kernel.
     */
    #[Test]
    public function theHeaderFieldFollowsTheSection(): void
    {
        $docsPath = FrontendLayout::render('t', 'c', currentPath: '/docs', theme: self::THEME);
        $blogPath = FrontendLayout::render('t', 'c', currentPath: '/blog', theme: self::THEME);

        self::assertStringContainsString('action="/docs" role="search"', $docsPath);
        self::assertStringNotContainsString('action="/blog" role="search"', $docsPath);
        self::assertStringContainsString('action="/blog" role="search"', $blogPath);
        self::assertStringNotContainsString('action="/docs" role="search"', $blogPath);
    }

    /**
     * The hits come straight after the count, under one heading.
     *
     * The theme's `docsIndex()` is the INDEX: it opens with a page head that
     * reads "Documentation" whatever was typed, and its block carries the page's
     * top padding, the head's own padding and the list's margin. Composed under
     * the kernel's summary that put a second `<h1>` over the results and more than
     * three hundred pixels of air between the count and the first row, so a reader
     * had to scroll to learn whether the word matched at all. This pins the
     * opposite: one heading on the page, and the first hit inside the summary's
     * own section.
     */
    #[Test]
    public function theHitsFollowTheCountUnderOneHeading(): void
    {
        $body = self::search('guide');

        // GET /docs renders in the ACTIVE theme, whatever the deployment picked
        // (the modules job picks mono). The vocabulary is that theme's, so it is
        // read here instead of assumed: a prefix spelled into this file would
        // pin the test to one theme and call a correct page a failure under
        // another.
        $prefix = ThemeKernel::for((new ThemeResolver())->resolvePath('/'))->prefix();

        self::assertSame(
            1,
            substr_count($body, '<h1'),
            'The search page carries a second top-level heading, which is the theme index composed under the summary.',
        );
        self::assertStringContainsString('Search</h1>', $body);
        self::assertStringContainsString('guides match', $body);
        // The class names are asserted with their `class="…"` context: the
        // theme's stylesheet is inlined into the page, so a bare `.bo-page` or
        // `bo-docs__item` search would hit the CSS rather than the markup.
        self::assertStringNotContainsString('class="' . $prefix . '-page ' . $prefix . '-docs"', $body, 'The theme page head is the index surface, not a search page.');
        self::assertStringNotContainsString('class="' . $prefix . '-docs__item"', $body, 'The index cards are the index surface, not a search page.');

        $summary = strpos($body, 'guides match');
        $firstHit = strpos($body, '<div class="' . $prefix . '-article__body"><ul>');

        self::assertIsInt($summary);
        self::assertIsInt($firstHit);
        self::assertGreaterThan($summary, $firstHit, 'The hits render before the count.');
    }

    /**
     * One field, not two: the kernel adds a form only when the shell has none.
     *
     * The index used to compose a field under the header's, and on `/docs` both
     * asked `q` of the same URL: the same input twice on one page. The answer is
     * not "remove ours" but "ask the shell", because the themes disagree: Boson
     * ships a header field, Jednotka, Erik, Griffe and Mono ship none. The
     * difference between the page and the shell is therefore one field at most,
     * and exactly one when the shell has none.
     */
    #[Test]
    public function theIndexAddsNoSecondField(): void
    {
        $request = Request::create('GET', '/docs');
        $theme = (new ThemeResolver())->resolve($request);
        $shellHasField = FrontendLayout::headerCarriesSearchField('/docs', $theme);

        $method = new ReflectionMethod(DocsIndexAction::class, 'handle');
        $response = $method->invoke(new DocsIndexAction(), $request);

        self::assertInstanceOf(Response::class, $response);

        $body = $response->getContent();

        // The shell alone, rendered for the same path and the same theme: the
        // difference between the two counts is what the kernel contributed.
        $shell = FrontendLayout::render('t', 'c', currentPath: '/docs', theme: $theme);

        self::assertSame(
            substr_count($shell, 'name="q"'),
            substr_count($body, 'name="q"') - ($shellHasField ? 0 : 1),
            'The index composed more than the one field a shell without its own leaves it, so `/docs` carries the same input twice.',
        );

        // The kernel's own section, read directly under both answers: a shell
        // with a field gets nothing from the kernel, a shell without one gets a
        // single form posting to the route this action reads.
        $content = new ReflectionMethod(DocsIndexAction::class, 'content');
        $withShellField = $content->invoke(new DocsIndexAction(), 'bo', true);
        $withoutShellField = $content->invoke(new DocsIndexAction(), 'bo', false);
        self::assertIsString($withShellField);
        self::assertIsString($withoutShellField);

        self::assertStringNotContainsString('<form', $withShellField, 'The shell already carries a field; the index added a second one.');
        self::assertStringContainsString('action="/docs" role="search"', $withoutShellField);
        self::assertSame(
            1,
            substr_count($withoutShellField, 'name="q"'),
            'The index compensates a shell without a field with one input, not two.',
        );

        // Nor a wrapper of its own: the theme's page block is the whole page,
        // so `/docs` starts at the padding every other page block keeps.
        self::assertStringNotContainsString(
            'data-docs-index',
            $body,
            'The index is wrapped in a kernel block of its own, which is where a rule set that'
            . ' changes its top padding would live; the page block the theme ships is the page.',
        );
    }

    /**
     * A theme without a field in its shell still gets one on the index.
     *
     * A theme whose header carries no search input at all leaves `/docs`
     * searchable only by typing the URL, which is what the index's field used to
     * prevent for every theme. The question and the answer are checked together:
     * the probe says no, and the kernel's section then carries the field.
     */
    #[Test]
    public function aThemeWithoutAShellFieldGetsOneOnTheIndex(): void
    {
        $theme = self::themeWithoutShellField();

        if (null === $theme) {
            $this->markTestSkipped('Every installed theme ships a search field in its shell, so there is no compensation to measure.');
        }

        self::assertFalse(
            FrontendLayout::headerCarriesSearchField('/docs', $theme),
            'This theme ships a search field in its header, so it no longer measures the compensation.',
        );

        $content = new ReflectionMethod(DocsIndexAction::class, 'content');
        $markup = $content->invoke(new DocsIndexAction(), ThemeKernel::for($theme)->prefix(), false);
        self::assertIsString($markup);

        self::assertStringContainsString('action="/docs" role="search"', $markup);
        self::assertMatchesRegularExpression(
            '#<input[^>]*name="q"#',
            $markup,
            'A theme whose shell has no field left `/docs` with no search input at all.',
        );

        // The two halves together, as the page a reader under this theme gets:
        // the shell, which has no field, and the kernel's section, which now
        // carries the one the page would otherwise lack.
        $page = FrontendLayout::render('Documentation', $markup, currentPath: '/docs', theme: $theme);

        // The field is counted as an `<input>`, not as the text `name="q"`:
        // a theme's critical CSS inlines selectors such as
        // `main form[role="search"] input[name="q"]`, and a raw
        // substring count would measure those rules as if they were fields.
        self::assertSame(
            1,
            preg_match_all('#<input[^>]*name="q"#', $page),
            'The page under a shell-less theme carries a number of search inputs other than one.',
        );
    }

    /**
     * A miss on such a theme is still refinable in place.
     *
     * The kernel's field on the results page carries the query back, escaped, so
     * narrowing a miss does not mean retyping it from memory: the same promise
     * the shell's own field keeps on the themes that have one.
     */
    #[Test]
    public function aMissIsStillRefinableWithoutAShellField(): void
    {
        $theme = self::themeWithoutShellField();

        if (null === $theme) {
            $this->markTestSkipped('Every installed theme ships a search field in its shell, so there is no field to pre-fill.');
        }

        $searchContent = new ReflectionMethod(DocsIndexAction::class, 'searchContent');
        $markup = $searchContent->invoke(new DocsIndexAction(), ThemeKernel::for($theme)->prefix(), 'prose"><x', [], false);
        self::assertIsString($markup);

        self::assertStringNotContainsString('<x', $markup);
        self::assertMatchesRegularExpression(
            '#<input[^>]*name="q"[^>]*value="prose&quot;&gt;&lt;x"#',
            $markup,
            'The field a shell-less theme gets on a miss dropped the query, so narrowing it means retyping it.',
        );
    }

    /**
     * Whatever theme the deployment picked, `/docs` has a way in.
     *
     * The de-duplication is only worth having if it did not trade a duplicate
     * for no field at all, and the four themes without a header field are the
     * ones no other test in this file renders end to end.
     */
    #[Test]
    public function theIndexIsAlwaysSearchableFromItsPage(): void
    {
        $request = Request::create('GET', '/docs');
        $method = new ReflectionMethod(DocsIndexAction::class, 'handle');
        $response = $method->invoke(new DocsIndexAction(), $request);

        self::assertInstanceOf(Response::class, $response);

        self::assertMatchesRegularExpression(
            '#<input[^>]*name="q"#',
            $response->getContent(),
            'The page carries no search input: the theme has none in its shell and the kernel composed none either.',
        );
    }

    /**
     * An installed theme whose shell carries no search field, or null.
     *
     * Found rather than named: a theme's name is declared by that theme's own
     * test (`THEME`), and a second spelling here would be a duplicate the
     * ownership gate counts. WHICH theme answers is not the subject; that the
     * page it renders still gets a field is.
     */
    private static function themeWithoutShellField(): ?string
    {
        foreach (array_keys(ThemeRegistry::all()) as $theme) {
            if (!FrontendLayout::headerCarriesSearchField('/docs', $theme)) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * A search needs a corpus, and a deployment without one is a state the
     * surface answers for (`No guides are published in this deployment.`) —
     * this export ships the kernel and the catalogue, not the site's prose.
     * Measuring the answer on a tree that has nothing to answer FROM would
     * report the absent content as a defect in the search.
     */
    private static function requirePublishedGuides(): void
    {
        if ([] !== (new DocsCatalog())->entries()) {
            return;
        }

        self::markTestSkipped('No guide is published in this tree, so a query has no corpus to answer from.');
    }

    /** The query as the action answers it, through its own route. */
    private static function search(string $query): string
    {
        self::requirePublishedGuides();

        $method = new ReflectionMethod(DocsIndexAction::class, 'handle');
        $response = $method->invoke(
            new DocsIndexAction(),
            Request::create('GET', '/docs', ['q' => $query]),
        );

        self::assertInstanceOf(Response::class, $response);

        return $response->getContent();
    }
}
