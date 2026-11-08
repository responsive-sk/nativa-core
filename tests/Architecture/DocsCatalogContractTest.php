<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use Modules\Core\Application\Services\DocsCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DocsCatalogContractTest — the publication boundary of /docs against the tree.
 *
 * Measured 2026-10-22, before this test: `DocsCatalog::PUBLISHED` held sixteen
 * keys and **none of the files existed in this tree**. They were the upstream
 * (`../nativa-minimal/docs/`) guide list copied verbatim, and `entries()`
 * skips a key whose file is missing — so `GET /docs` answered 200 with "No
 * guides are published in this deployment." The site was not broken; it was
 * empty, and nothing said so.
 *
 * That failure mode is what this test closes. A skipped key is an answer the
 * catalogue is allowed to give on purpose (a deployment without `docs/` must
 * read as a missing document, not a broken site), so the map may not be made
 * total by fiat here — instead the map and the tree are compared in the
 * direction that matters: every PUBLISHED key resolves to a real file, and no
 * family that must stay private is published.
 *
 * Deliberately narrow, in the shape of its neighbours: it states what the map
 * claims, not whether a guide's prose is true. The prose half is the authored
 * checklist in `docs/prose/docs-freshness.prose.md` (plan Task 0).
 *
 * @internal
 *
 * @coversNothing
 */
final class DocsCatalogContractTest extends TestCase
{
    /**
     * Drafts in `docs/clanky/` that have no blog pendant YET. Named rather than
     * derived: a draft that was never published is not an error, a draft nobody
     * remembers is. Measured 2026-10-22 — `siesta-funkcia-portu` exists on both
     * sides, these two do not. Publishing one means removing it from the list;
     * adding a fourth draft means adding it, which is the conversation.
     *
     * A method rather than a class constant on purpose: `NameOwnershipContractTest`
     * ratchets the tree's `const` count and can only fall, and a test's own
     * lookup table is not a name anybody else owns.
     *
     * @return list<string>
     */
    private static function pendingBlogPendants(): array
    {
        return ['model-bez-vazby', 'posledna-evolucia'];
    }

    /**
     * Families that must never appear in PUBLISHED, named rather than matched
     * by a pattern, so the reason travels with the check:
     *
     * - `clanky/` is the ARTICLE archive — `storage/blog/` is the source of
     *   truth and `/blog/<slug>` is where a Slovak dated article is indexed;
     * - `prose/` holds OpenProse contracts, which are instruction files read by
     *   an agent, not prose a public URL may serve;
     * - `security-*`, `PR-summary-*`, `rhythm-score-matrix`, `admin-templates`,
     *   `lekalo/`, `dex/` are the upstream families the map refuses today and
     *   must keep refusing the day one is copied in.
     *
     * A method, not a constant — see `pendingBlogPendants()`.
     *
     * @return list<string>
     */
    private static function privateFamilies(): array
    {
        return [
            'clanky/',
            'prose/',
            'security-audit-',
            'security-pentest-',
            'security-checklist-',
            'PR-summary-',
            'rhythm-score-matrix',
            'admin-templates',
            'lekalo/',
            'dex/',
        ];
    }

    /** @return array<string, array{string}> */
    public static function publishedKeys(): array
    {
        $cases = [];

        foreach ((new DocsCatalog())->keys() as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /**
     * The whole point of the map: a key a reader can request must answer a
     * guide. A key whose file is absent is a catalogue that lies — and it lies
     * silently, because `entries()` drops it and the request 404s.
     */
    #[Test]
    #[DataProvider('publishedKeys')]
    public function everyPublishedKeyResolvesToAFile(string $key): void
    {
        $catalog = new DocsCatalog();

        self::assertTrue(
            $catalog->has($key),
            sprintf('"%s" came back from keys() but has() says it is not published.', $key),
        );
        self::assertNotNull(
            $catalog->filePath($key),
            sprintf(
                'PUBLISHED names "%s" but no such file exists under docs/. The map is the list of'
                . ' guides a reader may open; a key without a file is a 404 the index silently'
                . ' skips. Either write the guide or remove the line — the measured state this'
                . ' check exists for was sixteen upstream keys and zero files (2026-10-22).',
                $key,
            ),
        );
    }

    /**
     * The index renders one card per key whose file exists, so the map must not
     * be empty in this tree — an empty map is the empty index the live
     * deployment served.
     */
    #[Test]
    public function theCataloguePublishesAtLeastTheNavigationGuides(): void
    {
        $entries = (new DocsCatalog())->entries();

        self::assertNotSame(
            [],
            $entries,
            'No guide resolves to a file, so GET /docs renders "No guides are published in this'
            . ' deployment." That string is the correct answer for a deployment without docs/ —'
            . ' it is not the correct answer for the repository that contains them.',
        );

        $keys = array_column($entries, 'key');

        foreach (['intro', 'core-philosophy', 'modules'] as $required) {
            self::assertContains(
                $required,
                $keys,
                sprintf(
                    '"%s" is not among the guides that resolve. The order of PUBLISHED is the order'
                    . ' of the index, and this one anchors it: an introduction, the philosophy the'
                    . ' module guides inherit, then the module contract itself.',
                    $required,
                ),
            );
        }
    }

    /**
     * A published guide must carry an H1 — `DocsCatalog::split()` cuts the title
     * out of the file and the layout renders it, so a guide without one reaches
     * the page with a label invented from its key and its own first heading
     * still in the body.
     */
    #[Test]
    #[DataProvider('publishedKeys')]
    public function everyPublishedGuideDeclaresItsOwnTitle(string $key): void
    {
        $guide = (new DocsCatalog())->read($key);

        self::assertNotNull($guide, sprintf('"%s" has no readable body.', $key));

        $file = (string) file_get_contents(sprintf('%s/docs/%s.md', dirname(__DIR__, 2), $key));

        self::assertMatchesRegularExpression(
            '/^#\s+\S/m',
            $file,
            sprintf(
                'docs/%s.md has no H1. split() removes it by position and the layout renders the'
                . ' title from it; without one the surface shows a label derived from the key and'
                . ' the guide keeps its own heading in the body.',
                $key,
            ),
        );
        self::assertStringStartsNotWith(
            '# ',
            ltrim($guide['body']),
            sprintf(
                'docs/%s.md still STARTS with an H1 after split() — the title was not cut out at the'
                . ' top of the file, so the page renders it twice.',
                $key,
            ),
        );
    }

    /**
     * The families that must stay private, named one by one rather than left to
     * a reviewer's memory — this is the half of the map that is a POLICY.
     */
    #[Test]
    public function noPrivateFamilyIsPublished(): void
    {
        foreach ((new DocsCatalog())->keys() as $key) {
            foreach (self::privateFamilies() as $family) {
                $inside = str_starts_with($family, '/')
                    ? str_contains($key, $family)
                    : str_starts_with($key, $family);

                self::assertFalse(
                    $inside,
                    sprintf(
                        '"%s" is published. "%s" is a private family: clanky/ is the article archive'
                        . ' whose source of truth is storage/blog/, prose/ holds agent instructions,'
                        . ' and the rest are reports and generated data. Publishing one puts a'
                        . ' document in front of readers that was written for the team.',
                        $key,
                        $family,
                    ),
                );
            }
        }
    }

    /**
     * `docs/clanky/` is the archive, not a corpus: every draft there must either
     * have become a blog article or be waiting to. Measured 2026-10-22:
     * `siesta-funkcia-portu` exists on both sides, `model-bez-vazby` and
     * `posledna-evolucia` have no blog pendant yet — which is why this asserts
     * the direction that holds (every clanky slug is a blog slug or still
     * pending, never a docs key) rather than demanding a pendant today.
     */
    #[Test]
    public function theArticleArchiveNeverLeaksIntoTheGuideSurface(): void
    {
        $root = dirname(__DIR__, 2);
        $archive = glob($root . '/docs/clanky/*.md') ?: [];

        self::assertNotSame([], $archive, 'docs/clanky/ is gone — this check is reading nothing.');

        $blog = [];

        foreach (glob($root . '/storage/blog/*.md') ?: [] as $file) {
            $blog[] = basename($file, '.md');
        }

        self::assertNotSame([], $blog, 'storage/blog/ is empty — the article corpus is the source of truth.');

        foreach ($archive as $file) {
            $name = basename($file, '.md');

            // A dated draft (`2026-10-16-model-bez-vazby`) corresponds to a blog
            // slug by its tail, which is how a published one is named.
            $slug = 1 === preg_match('/^\d{4}-\d{2}-\d{2}-(.+)$/', $name, $matched) ? $matched[1] : $name;

            self::assertNotContains(
                'clanky/' . $name,
                (new DocsCatalog())->keys(),
                sprintf(
                    'docs/clanky/%s.md is published through /docs. An article belongs on /blog/%s'
                    . ' where it is indexed, has an author and a date — /docs is English engineering'
                    . ' prose with noindex.',
                    $name,
                    $slug,
                ),
            );
            self::assertTrue(
                in_array($slug, $blog, true) || in_array($slug, self::pendingBlogPendants(), true),
                sprintf(
                    'docs/clanky/%s.md is neither a blog article nor a declared pending draft. A'
                    . ' draft that was never published is not an error; a draft nobody remembers is.'
                    . ' Publish it through the article flow, or add "%s" to PENDING_BLOG_PENDANT so the'
                    . ' decision is written down.',
                    $name,
                    $slug,
                ),
            );
        }
    }

    /**
     * Rewriting is the one place this surface can change a guide's own text, so
     * it may only ever point at a guide it can serve.
     */
    #[Test]
    public function rewriteLinksTouchesOnlyWhatThisSurfaceServes(): void
    {
        $catalog = new DocsCatalog();

        $markdown = implode("\n", [
            '[a guide](modules.md)',
            '[nested](./modules.md#what-the-scan-sees)',
            '[repo relative](docs/modules.md)',
            '[a draft](clanky/2026-10-16-model-bez-vazby.md)',
            '[outside](../README.md)',
            '[external](https://example.test/x.md)',
            '[absolute](/docs/modules)',
            '[not markdown](modules)',
        ]);

        $rewritten = $catalog->rewriteLinks('intro', $markdown);

        self::assertStringContainsString('](/docs/modules)', $rewritten, 'A published sibling was not rewritten.');
        self::assertStringContainsString(
            '](/docs/modules#what-the-scan-sees)',
            $rewritten,
            'A fragment must survive the rewrite — a guide links its own headings.',
        );
        self::assertStringContainsString(
            '](clanky/2026-10-16-model-bez-vazby.md)',
            $rewritten,
            'A link to an unpublished document must come back exactly as written: a rewrite that'
            . ' guesses turns a link that 404s today into a link that 404s somewhere new.',
        );
        self::assertStringContainsString('](../README.md)', $rewritten, 'A link out of docs/ was rewritten.');
        self::assertStringContainsString('](https://example.test/x.md)', $rewritten, 'An external URL was rewritten.');
        self::assertStringContainsString('](/docs/modules)', $rewritten, 'An absolute path was rewritten.');
        self::assertStringContainsString('](modules)', $rewritten, 'A non-markdown target was rewritten.');
    }

    /**
     * The key guard, which is what makes "refused without reading the file" a
     * property of the code rather than a coincidence of the map.
     */
    #[Test]
    public function unsafeKeysAreRefusedBeforeAnyFileIsOpened(): void
    {
        $catalog = new DocsCatalog();

        foreach (['../README', 'a/../../etc/passwd', 'a\b', '.hidden', '/lead', '%2e%2e/x', ''] as $key) {
            self::assertFalse(
                $catalog->isSafeKey($key),
                sprintf('"%s" was accepted as a key. A key is a path segment: no "..", no backslash,'
                    . ' no URL escape, no leading slash or dot.', $key),
            );
            self::assertNull(
                $catalog->filePath($key),
                sprintf('filePath("%s") resolved — the guard is bypassed.', $key),
            );
        }

        self::assertTrue($catalog->isSafeKey('kernel-status/01-import-and-edge-cuts'), 'A legit nested key was refused.');
    }

    /**
     * A deployment without `docs/` must render the empty state, not a 500 — the
     * seam `DocsCatalog::__construct(?string $docsDir)` exists for this.
     */
    #[Test]
    public function aDeploymentWithoutTheDocsDirectoryRendersNothingInsteadOfFailing(): void
    {
        $catalog = new DocsCatalog('/nonexistent-docs-directory-' . bin2hex(random_bytes(4)));

        self::assertSame([], $catalog->entries(), 'A missing docs/ directory produced entries.');
        self::assertNull($catalog->read('intro'), 'A missing docs/ directory still served a guide.');
    }
}
