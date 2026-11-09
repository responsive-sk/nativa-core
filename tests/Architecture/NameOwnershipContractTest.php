<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use FilesystemIterator;
use Modules\Tooling\ModuleCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * NameOwnershipContractTest — one name, one owner, measured instead of believed.
 *
 * Measured 2026-10-17, while a const audit asked the obvious question: is a
 * class constant a good way to hold a value that appears in more than one
 * place? The tree had 414 declarations for 203 names. That number is not a
 * defect — most constants are private invariants and are exactly right. The
 * defect is narrower and this test is shaped around it, not around the total.
 *
 * ## Three rules, and only one of them is absolute
 *
 * 1. **A string value must not be claimed by two names.** Absolute, with an
 *    allow-list of the two that exist today. A string is an identifier —
 *    `'users.manage'` written as `REQUIRED` instead of `USERS` means two
 *    owners for one permission and nothing reports the rename. Two
 *    exemptions is a list a person can hold in their head; the list also fails
 *    when an entry stops applying, so it cannot rot.
 * 2. **A value must not be spelled as a raw literal outside its owner.**
 *    A RATCHET, not a prohibition, and that is the measured conclusion rather
 *    than a compromise. 50 owned string values appear as literals on 404
 *    places across the tree. `'en'` alone is 54 of them, `'home'` 41 and
 *    `'/mark'` 30: a bare language code, a route slug and the panel's mount
 *    path, spelled in seed data, translations and URL tests, which is data and
 *    not a second owner. A gate with 404 exemptions is not a gate. 404 is a
 *    CEILING: every consolidation lowers it, and the gate refuses the 405th.
 * 3. **The declaration count must not rise.** Ratchet at 238/202. This is the
 *    rule that catches the cheapest wrong fix — "there are two names for this,
 *    let me add a third" — which is the complaint that started all of this.
 *
 * ## The counting definition is the test, not a comment in it
 *
 * A regex over this tree finds 6 constants, not 414, because the tree writes
 * typed constants (`private const string MARKER = '.migrations-applied';`). A regex is
 * also wrong in the other direction: it matches constants inside comments,
 * which this tree is full of because it documents its constants at length. So
 * this test tokenises. Two traps are encoded in `constantDeclarations()` and
 * both were found by measurement, not by reading:
 *
 *   - `array` is T_ARRAY, not T_STRING. A type detector that knows only
 *     T_STRING silently loses every `const array` — 88 here.
 *   - an UNTYPED `const NAME = …` has a name that is itself a T_STRING, so a
 *     loop that consumes T_STRING as "a type" walks past the name onto the `=`
 *     and drops the declaration.
 *
 * ## The scope is git-tracked files only, and that is not cosmetic
 *
 * `src/Modules/<Name>` is gitignored operator state: a kernel-only checkout
 * carries Core + Database and nothing else. Scoping this test at the whole
 * tree would make its result depend on which modules this checkout happens to
 * have installed — the exact defect `ModelCatalogueContractTest` was fixed for
 * two commits ago. So the roots below are the tracked surface, and the result
 * does not depend on which modules the checkout has installed (measured
 * 2026-10-27: 50 values on 385 places across 115 files).
 *
 * Deliberately cheap and deliberately dumb, like `KernelShapeTest` beside it:
 * file listings and a tokeniser, compared. What it protects is narrower than
 * "this codebase has good names" and is stated rather than implied.
 *
 * The ledger — every exemption with its reason, every ceiling with the
 * measurement behind it, and three ways the counting goes wrong — is
 * `docs/name-ownership.md`. The numbers live here, the reasons live there; a
 * ceiling raised without a reason in the commit message is not a reviewable
 * edit, it is a gate that has stopped working.
 *
 * @internal
 *
 * @coversNothing
 */
final class NameOwnershipContractTest extends TestCase
{
    /**
     * Tracked roots only. `public/dist` is build output — copied bytes, not
     * declarations. Every other `src/Modules/<Name>` is an installed slice and
     * is deliberately absent; see the class docblock.
     *
     * @var list<string>
     */
    private const array SCOPE = [
        'src/Modules/Core',
        'src/Modules/Database',
        'src/Modules/KernelTheme',
        'src/Modules/Tooling',
        'src/Themes',
        'modules',
        'tests',
        'bin',
        'public',
    ];

    /** Rule 1 — string values that two names claim today, and why each stays. */
    private const array MULTI_NAME_EXEMPTIONS = [
        "'Cardboard'" => 'Two tests that each guard their OWN skip. KernelOnlyBootTest is in'
            . ' tests/Boot and must not statically name an optional module;'
            . ' SettingsThemeSelectTest lives in modules/Cardboard and imports nothing from'
            . ' tests/ — zero of the module\'s tests do, and the first would set a precedent'
            . ' KernelCouplingTest would then have to know about. Merging them would couple'
            . ' two files that are deliberately independent, and the name has no production'
            . ' owner to consolidate onto: nothing outside tests/ writes \'Cardboard\'.',
        "'203.0.113.7'" => 'RateLimitMiddlewareTest::CLIENT_IP (Security) and AuthServiceTest::REMOTE'
            . ' (Users) — TWO MODULES, and the earlier text naming'
            . ' AuthMiddlewareDenialTest::REMOTE was simply wrong: that constant does not'
            . ' exist, and this test file owns 203.0.113.9 instead. The value is an RFC 5737'
            . ' documentation address and each test uses it as a SCENARIO, not an'
            . ' identifier: PasswordResetServiceTest walks 203.0.113.1/.2/.3 to prove one'
            . ' address gets three attempts, which is what the octet MEANS here. A shared'
            . ' tests/Support constant would claim the two runs are the same run, and would'
            . ' be a cross-module import — tests/Support costs nothing at runtime'
            . ' (autoload-dev) so the argument against it is the dependency, not speed.',
    ];

    /**
     * The gate files, which neither rule 2's ceiling nor rule 3's counts
     * measure. One list, read by both: when they were separate the sibling
     * entry lived inline in rule 3 while rule 2 filtered nothing, and the only
     * way that shows up is a ceiling that rises while a reason is being
     * written down.
     */
    private const array GATE_FILES = [
        'tests/Architecture/NameOwnershipContractTest.php',
        'tests/Architecture/MetricRegistryResolutionTest.php',
        // Task 2.7's CSS gate. It needs the theme names to measure ownership
        // and a handful of constants to hold its exceptions; counting those
        // would let the next theme edit move a ceiling that exists to measure
        // PRODUCTION spellings. Rule 1 still reads this file.
        'tests/Theme/ThemeClassOwnershipTest.php',
        // The sticky-rail gate. It spells the two elements whose overflow it
        // measures (`html`, `body`), the declaration it looks for and the value
        // that breaks it — those spellings ARE the subject of the test, and
        // counting them would let a CSS edit move a ceiling that exists to
        // measure production spellings. Rule 1 still reads this file.
        'tests/Theme/ThemeStickyRailTest.php',
        // The catalogue-shape gate. It must spell the group name and the four
        // theme names to measure where they live, and those spellings are the
        // SUBJECT of the test rather than duplication it failed to notice.
        // Counting them would let a theme move inflate a ceiling that exists to
        // measure production spellings. Rule 1 still reads this file.
        'tests/Architecture/ModuleCatalogueShapeTest.php',
        // The Statement-helper gate. It must spell the thirteen module names
        // whose helper copies it holds to one shape, and `MODULES` is the
        // ratchet — those spellings are the SUBJECT of the test, not a duplicate
        // it failed to notice. Rule 1 still reads this file.
        'tests/Architecture/StatementHelperContractTest.php',
        // The admin-class gate. It spells the module whose markup it reads
        // (`Cardboard`) and nothing else; that spelling is the SUBJECT of the
        // test, not a duplicate it failed to notice, and the module name has an
        // owner in the catalogue, which this file asks instead of guessing.
        'tests/Architecture/AdminClassContractTest.php',
        // The drift gate beside the service it measures. It builds a fixture
        // catalogue out of temp directories, so it spells a group name, a theme
        // name and a flat module name — the layout it asserts, not a second
        // owner for those strings. Its `MODULE` guard constant exists for the
        // same reason every module test has one. Rule 1 still reads this file.
        'modules/Cardboard/tests/ModuleTreeStateDriftTest.php',
    ];

    /**
     * Rule 2 — measured 2026-10-17 on the tracked scope. A ceiling, not a list.
     *
     * Was 533, then 532 after `AdminLayout::markCssLinks()` started reading
     * `ThemeResolver::ADMIN_THEME` instead of `'moonshine'` and moving
     * `AdminRoutePolicy` and `Permissions` into `Modules\Core\Domain` deleted
     * Cardboard's local copy of the admin permission table.
     *
     * Then 514, and that is work rather than an accounting change: task 2.2
     * made `QuestionsAdminAction` read `Question::STATUS_*` in both count maps
     * and `CommentsMetric` plus `CommentsAdminAction` read
     * `Comment::STATUS_*`, retiring 18 owned literals.
     *
     * Then 502: task 0.3 removed the two `ADMIN_PATH` constants and the three
     * call sites that retyped `'/mark'` beside them. `AdminRoutePolicy` still
     * declares the default (`DEFAULT_PREFIX`), so the value stays owned and
     * the remaining `/mark` literals are still counted — the gate must not
     * lose sight of a value just because its owner became configurable.
     *
     * Then 499: task 2.8 moved 130 admin redirects from path literals to route
     * names. Most of those spelled `/mark/<section>`, not the bare `/mark`
     * this rule owns, so the ceiling fell by the three that did spell it.
     *
     * Now 496: task 2.9 did the same for the HTML links and forms, and again
     * only the bare `/mark` occurrences are visible to this rule. The rest of
     * that work is held by `AdminRouteUrlNamesTest`, which scans `href`,
     * `action` and HTMX URLs as well.
     * Now 507: the boson theme is a whole new module, so it adds places
     * without duplicating an existing one — six in `BosonTheme` (`boson` three
     * times because name, bundle and jsKey are three declarations that happen
     * to share a spelling, and `Blog` in the chrome's labels and page heads)
     * and five in its test (`en` fixtures and a `Blog` assertion). The
     * direction stays down for existing code; a new surface is measured, not
     * exempted.
     *
     * Now 526: the demo renderer (`bin/build/render-theme-pages.php`) is a new
     * build surface, not a new spelling of an existing value. It names the
     * seven page surfaces (`home`, `article`, …) and the two themes it refuses;
     * those words are owned elsewhere only by accident — `home` by a shell-copy
     * test's group constant, `article` by `Question::SUBJECT_ARTICLE`, the theme
     * names by the theme tests' own `THEME` constants. Making the renderer read
     * the kernel's constants instead would give a build script a dependency it
     * has no use for, so the places stay and the ceiling is measured again.
     *
     * The number is measured, the direction is down, and the gate files' own
     * literals (the exemption reasons) are excluded from it.
     *
     * 526 → 384 with the locale decision: 132 counted places spelled `'sk_SK'`,
     * 84 of them in `Locales/…/SeedData.php` — which the failure message above
     * had been calling correct while the counter charged for it. A locale code
     * names a language, not this tree's default, so rule 2 skips it
     * (`isLocaleCode()`) and the ceiling drops by exactly those 132. Rule 1 still
     * holds the value to one name, so a second constant with the same code is
     * still reported.
     *
     * 384 → 385 with the shared paginator: the inbox is the first `/mark` list
     * paged by `modules/Pagination`, and its ONE counted place is
     * `NotificationsAction` reading `?page=` off the request. That is a URL key,
     * not a second name for `Question::SUBJECT_PAGE`; the two spellings are the
     * same word by accident, exactly like the renderer's `article` above, and no
     * constant can absorb it — a `PAGE_QUERY = 'page'` would be the second
     * name rule 1 exists to forbid.
     *
     * The first draft cost FIVE places (two in `OffsetPaginator::toArray()`, one
     * in the action, two in the page test) because the paginator took a
     * `(parameter name, value)` pair and every caller had to say `page`. The fix
     * was the design, not the number: the closure now takes the page value alone,
     * the action spells its own key once, and the test FOLLOWS the link the page
     * rendered instead of rebuilding it from a query array. A rise of one on a
     * new surface is the honest measurement; the four that went away were not.
     *
     * 385 → 404 with the Forms module: a whole new surface, not a second
     * spelling. The nine route attributes spell their own paths (that IS the
     * path's owner), `FormsBadge::NAV_HREF` carries the panel href the nav
     * reads, the two stored status words are declared once and read by the
     * repository, and the tests name the theme prefix and one bare `en`
     * fixture. Nineteen places across two tables and nine routes, measured
     * rather than exempted.
     *
     * The first draft of that module measured 610, and the rise was not the
     * new surface: `FormFieldType` declared `TEXT = 'text'` and `EMAIL =
     * 'email'`, so the gate charged every place in the tree that spells either
     * word. The fix was the vocabulary, an enum whose cases own their tokens,
     * not the ceiling.
     *
     * 404 → 410 with the Nativa theme, six places and no new duplicate. Three
     * are the theme's own name, once per method that answers with it (`name`,
     * `bundle`, `jsKey`) — the same three Jednotka, Mono and Griffe spell,
     * because a theme names itself rather than reading the resolver's default.
     * One is the key that enters the theme in `WITHOUT_HIGHLIGHT`: a gate that
     * exempts a theme has to name the theme it exempts, exactly as the `kernel`
     * entry does. The last two spell `Blog` as a page heading — the theme's
     * index heading and the test asserting it. That word is a module constant's
     * value by coincidence, the way the renderer's `article` is, and a theme
     * that imported the Blog module for the string would tie a presentation to
     * a feature module it must render without.
     */
    private const int MAX_LITERAL_PLACES = 410;

    /**
     * Rule 3 — measured 2026-10-17 on the tracked scope.
     *
     * 241 → 242 for `ModuleTreeState::IGNORED`, the list of directory prefixes
     * excluded from a drift comparison; then down to 240 when the gate files
     * left the count. Then 239: the theme gate's `UNPREFIXED_ALLOWED`
     * list was deleted when PHPStan showed it was an empty array no value
     * could ever match. Removing a constant that guarded nothing is the same
     * direction this ratchet exists to enforce.
     *
     * Now 238: task 0.3 deleted `ADMIN_PATH` from the Settings provider (and
     * its installed slice) and `AdminRoutePolicy` added `DEFAULT_PREFIX` in
     * its place — one default for one panel, not two spellings of one name.
     *
     * 242 with the boson theme: `BosonTheme::DEFAULT_COPY` and `::WORKS` are
     * the theme's two tables (copy and reading order), and `MODULE`/`THEME`
     * are the module-test idiom every theme test already declares. Four
     * declarations for a new theme, not four spellings of an existing value.
     *
     * 246 with the demo renderer: `LOCALES` (locale → directory and fixture),
     * `SURFACES` (surface → the theme method that renders it), and the two
     * refusal tables (`NOT_RENDERED`, `REFUSED`). Four tables of a new build
     * surface, not four spellings of a name that already had an owner.
     *
     * 250 with the operator notification: `HANDLER` (`notify.admin`, pushed by
     * two producers and looked up by one registry), `EXCERPT_LIMIT` and
     * `PAYLOAD_EXCERPT_LIMIT` (the mail body and the queue row are different
     * surfaces with different budgets) and `WORK_LIMIT` (the batch the panel may
     * run inside one request). `HttpTransport::TIMEOUT_SECONDS` moved here from
     * the reset mailer rather than being added, so it is not part of the four:
     * the transport the two mailers share was the reason it was extracted.
     * None of the four is a second name for a value that already had one; the
     * duplicate this feature first introduced — a hardcoded `/mark/questions`
     * beside `QuestionsBadge::NAV_HREF` — was removed by carrying the panel
     * path in the payload, which is why rule 2 stayed at 526.
     *
     * 260 with the Clickhouse module: its seven module tests carry the
     * `MODULE` guard every module test in the tree declares, `NAME_PATTERN` is
     * the one regex the bridge validates a prefix and a name with, and
     * `MAX_ATTEMPTS`/`BASE_DELAY_MS` are the retry numbers the transport owns
     * (the same two names `CloudinaryApi` already declares, for the same
     * reason). The six command option-name arrays and the transport's retry
     * status list were inlined rather than made constants, and the test's
     * prefix stopped being a constant once the gate showed that owning
     * `nativa_` charged ten places for it. 261 with `SqliteJournalTest`: its
     * guard is the same `MODULE` constant every module test declares, and the
     * name already existed, so only the declaration count moves.
     *
     * 266 with the docs search: `DocsSearchService` owns four numbers for four
     * different surfaces — `MAX_QUERY_LENGTH` (what one field may carry),
     * `MAX_TOKENS` (six words is a sentence, not a search), `SNIPPET_LIMIT` (how
     * much of a matched line a card shows) and `TITLE_WEIGHT` (how many body
     * hits one title hit is worth). `DocsSearchTest::THEME` is the theme-test
     * idiom that already names `boson` in `BosonThemeTest`, not a second name
     * for it. None of the five is a second spelling of a value that already had
     * one, which is the rise this ratchet refuses.
     *
     * 269 with the Cloudinary library: four values its new surfaces own —
     * `MAX_IDS` (how many public ids one delete may carry, checked in both
     * callers), `THUMB_SIZE` (the 68px square a 34px tile asks the CDN for)
     * and the preview cache's own `TTL` and `FILE`. None is a second spelling
     * of a value that already had one: the cache writes its own file, and a
     * folder glance's five minutes is not the page cache's staleness rule.
     *
     * 284 with the Forms module: fifteen declarations, every one a value the
     * module is the owner of. `FormSchema` carries the seven limits its own
     * design table states (20 fields, 10 options of 80, a 120-character label,
     * a 5 000 ceiling, a 500 default and the three keys the form itself uses),
     * `FormSubmission` names its two stored states, and `FormsBadge::NAV_HREF`,
     * `FormResultsService::WINDOW`, the two `PATTERN`s and the submit action's
     * `RATE_LIMIT`/`RATE_WINDOW` own one fact each. Four of those names the
     * tree already held (`PATTERN` twice, `RATE_LIMIT`, `RATE_WINDOW`), which
     * is why the distinct-name count moved by eleven rather than fifteen.
     *
     * 287 with the Nativa theme: `JS_BUDGET` and `CSS_BUDGET` are the
     * freeze-test idiom every bundle already spends on its own two numbers, and
     * `THEME` is the name each theme test builds — Boson, Mono and
     * DocsSearchTest already hold that spelling for the same fact. None of the
     * three is a second name for a value that had one, which is why the
     * distinct-name ceiling did not move with them.
     */
    private const int MAX_DECLARATIONS = 287;

    /**
     * 203 → 204 with MAX_DECLARATIONS: `ModuleTreeState::IGNORED` is a name as
     * well as a declaration, and both ceilings moved for the same one constant.
     * Then 202: deleting `UNPREFIXED_ALLOWED` took a name with it. It stays 202
     * after task 0.3 — `ADMIN_PATH` left and `DEFAULT_PREFIX` arrived, so the
     * declaration count fell while the distinct-name count did not.
     *
     * 203 with boson: only `WORKS` is a new name — `DEFAULT_COPY` already
     * names Jednotka's table, and `MODULE`/`THEME` are the shared test idiom.
     *
     * 207 with the demo renderer: `LOCALES`, `SURFACES`, `NOT_RENDERED` and
     * `REFUSED` name four things nothing else in the tree names — which locale
     * renders where, which theme method answers a surface, and which themes the
     * generator will not write over. None of them is a second name for a value
     * that already had one.
     *
     * 209 with the operator notification: `EXCERPT_LIMIT` (how much of a
     * reader's text a mail may carry — the payload's own cap is a different
     * number for a different surface, so it is not the same name twice) and
     * `WORK_LIMIT` (how much of the queue one admin click may run inside a
     * request). `HANDLER` was already owned by `Blog\CoverJobs` and is reused by
     * design — one name for "the job this producer pushes" — and
     * `TIMEOUT_SECONDS` moved with the shared HTTP transport rather than
     * arriving new.
     *
     * 210 with the Clickhouse module: `NAME_PATTERN` is the only new name. The
     * seven `MODULE` guards reuse the module-test idiom, and the transport's
     * `MAX_ATTEMPTS`/`BASE_DELAY_MS` are names `CloudinaryApi` already owns.
     *
     * 212 with the docs search: only TWO names are new — `MAX_QUERY_LENGTH`
     * (one field, one budget, and the blog's own cap is a different number) and
     * `MAX_TOKENS` (a six-word search is a sentence, not a query). `SNIPPET_LIMIT`
     * and `TITLE_WEIGHT` reuse names the tree already holds, `THEME` is the
     * theme-test idiom, and neither of the two is a second name for a value that
     * already had one.
     *
     * 214 with the Cloudinary library: three of the four new declarations carry
     * new names (`MAX_IDS`, `THUMB_SIZE`, `TTL`). `FILE` is the name
     * `DiscoveryCache` already owns and is reused by design, because both
     * caches write one JSON file under `storage/cache` and the name says what
     * the constant is, not which cache declares it.
     *
     * 225 with the Forms module: eleven names nothing else in the tree names,
     * each the limit of a different surface (`MAX_FIELDS` is the shape of one
     * definition, `MAX_OPTIONS` one field's list, `MAX_LABEL_LENGTH` one
     * label). The other four declarations reuse names the tree already owns,
     * and the field-type vocabulary arrived as an enum rather than as six
     * constants: `'text'` and `'email'` are ordinary words, and owning them as
     * constants charged 182 unrelated places in the tree (Boot 47).
     */
    private const int MAX_NAMES = 225;

    /**
     * `name => modules/<Name>` or `modules/<Group>/<Name>`, resolved once. A
     * test property, not a constant: it is memoised state, and the declaration
     * ratchet above counts what this file DECLARES.
     *
     * @var array<string, string>
     */
    private array $cataloguePaths = [];

    /**
     * Rule 1. A value is the thing two owners disagree about, so the assertion
     * is about values, not about declaration sites — and it holds only for
     * strings. `60` is claimed by nine names across nine different meanings
     * (a timeout, a page size, a row limit); that is arithmetic coincidence,
     * not a second owner, and a gate reporting it would teach people to ignore
     * the gate.
     */
    #[Test]
    public function aStringValueIsClaimedByAtMostOneName(): void
    {
        /** Claimed string value => constant name => the places that name it. */
        $claims = [];

        foreach ($this->declarations() as $declaration) {
            if (null === $declaration['value'] || !$this->isQuotedString($declaration['value'])) {
                continue;
            }

            $claims[$declaration['value']][$declaration['name']][] = $declaration['file']
                . ':' . $declaration['line'];
        }

        $unreported = [];

        foreach ($claims as $value => $names) {
            if (count($names) < 2) {
                continue;
            }

            if (isset(self::MULTI_NAME_EXEMPTIONS[$value])) {
                continue;
            }

            $sites = [];

            foreach ($names as $name => $where) {
                $sites[] = $name . ' (' . implode(', ', $where) . ')';
            }

            $unreported[$value] = implode(' | ', $sites);
        }

        self::assertSame(
            [],
            $unreported,
            "A value with two names has two owners, and nothing reports the rename:\n"
            . implode("\n", array_map(
                static fn (string $value, string $names): string => sprintf('  %-20s <- %s', $value, $names),
                array_keys($unreported),
                $unreported,
            ))
            . "\nEither delete one name or record the exemption with a reason in"
            . ' self::MULTI_NAME_EXEMPTIONS. A new constant is not a fix: it moves the'
            . ' collision and leaves three declarations where two had one.',
        );

        // An exemption that no longer applies is the other half of the same
        // defect: the list stops describing the tree, so nobody can trust it.
        $stale = array_diff(array_keys(self::MULTI_NAME_EXEMPTIONS), $this->multiNameValues($claims));

        self::assertSame(
            [],
            array_values($stale),
            'self::MULTI_NAME_EXEMPTIONS lists a value that no longer has two names.'
            . ' An exemption that stopped applying is rot in the ledger, not a'
            . ' free pass.',
        );
    }

    /**
     * Rule 2, as a ceiling.
     *
     * @param array<string, array<string, list<string>>> $claims
     *
     * @return list<string>
     */
    private function multiNameValues(array $claims): array
    {
        $found = [];

        foreach ($claims as $value => $names) {
            if (count($names) > 1) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * Rule 2. Every place an owned string value is spelled out as a literal
     * somewhere it does not own. The ceiling is what makes this a gate rather
     * than a wish: 385 today, and the only legal direction is down.
     */
    #[Test]
    public function ownedValuesAreNotSpelledAsLiteralsMoreOftenThanTheCeiling(): void
    {
        [$places] = $this->literalPlaces();

        self::assertLessThanOrEqual(
            self::MAX_LITERAL_PLACES,
            $places,
            sprintf(
                '%d places spell an owned value as a raw literal; the ceiling is %d.'
                . ' Every consolidation lowers this, so a rise means a new duplicate.'
                . '\nLocale codes are not counted: `xx_YY` is data about a language, not a'
                . ' second name for a constant, and `Locales/…/SeedData.php` spelling one 84'
                . ' times is why (`isLocaleCode()`). A new duplicate in ordinary text still'
                . ' counts.',
                $places,
                self::MAX_LITERAL_PLACES,
            ),
        );
    }

    /**
     * The locale decision, pinned.
     *
     * Without this, the exclusion in `literalPlaces()` is only a comment: a
     * later "tidy-up" of that loop would leave every assertion green (the
     * ceiling is a number, and the tree is under it again once nobody looks)
     * while the counter silently went back to charging seed data. A rule that
     * decides what IS a duplicate deserves an assertion that says so.
     */
    #[Test]
    public function aLocaleCodeIsDataRatherThanARepeatedName(): void
    {
        [, $where] = $this->literalPlaces();

        $offenders = [];

        foreach ($where as $file => $literals) {
            foreach ($literals as $literal) {
                if (self::isLocaleCode($literal)) {
                    $offenders[] = $file . ': ' . $literal;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A locale code is data about a language, not a second name for the constant that'
            . ' happens to hold one — it must not be counted as a re-spelt owned value.',
        );
    }

    /**
     * Rule 3. The ratchet that catches the cheapest wrong fix.
     */
    #[Test]
    public function theDeclarationCountDoesNotRise(): void
    {
        // A ruler does not measure itself. This file holds the ceilings, so
        // counting its own constants would mean the gate's next edit — including
        // its own approval list — moves the number it is meant to hold. Rule 1
        // still reads it, so a duplicated value in a gate is still caught.
        //
        // The same exclusion covers every other file in this directory, and that
        // is a deliberate widening rather than a convenience: a gate that exempts
        // only itself can be outgrown by adding a constant to a sibling test.
        // `MetricRegistryResolutionTest` did exactly that on the day it was
        // written — one `REGISTRY` constant took the count from 241 to 242, and
        // the honest fix was to exempt the gates, not to raise the ceiling.
        $declarations = array_values(array_filter(
            $this->declarations(),
            fn (array $declaration): bool => !in_array($declaration['file'], self::GATE_FILES, true),
        ));

        $count = count($declarations);
        $names = count(array_unique(array_column($declarations, 'name')));

        self::assertLessThanOrEqual(
            self::MAX_DECLARATIONS,
            $count,
            sprintf(
                'const declarations rose above %d (now %d). If a duplicate was fixed by'
                . ' adding a constant, the fix moved the problem and the tree grew.',
                self::MAX_DECLARATIONS,
                $count,
            ),
        );

        self::assertLessThanOrEqual(
            self::MAX_NAMES,
            $names,
            sprintf(
                'distinct const names rose above %d (now %d). A new name for an existing'
                . ' value is the same defect wearing a different hat.',
                self::MAX_NAMES,
                $names,
            ),
        );
    }

    /**
     * Owner-keyed literal count. Kept separate from rule 1 so the failure text
     * can say which number moved.
     *
     * @return array{int, array<string, list<string>>}
     */
    private function literalPlaces(): array
    {
        $owners = [];

        foreach ($this->declarations() as $declaration) {
            // Rule 1 reads the gates; the ceiling does not. A gate constant
            // naming its own subject (`KERNEL_VOCABULARY = 'st'`) would
            // otherwise turn every production default argument into a literal
            // owned by the gate, and raise the ceiling for measuring the tree.
            if (in_array($declaration['file'], self::GATE_FILES, true)) {
                continue;
            }

            if (null === $declaration['value'] || !$this->isQuotedString($declaration['value'])) {
                continue;
            }

            $owners[$declaration['value']][$this->ownerKey($declaration['file'])] = true;
        }

        $places = 0;
        $where = [];

        foreach ($this->files() as $file) {
            $relative = $this->relative($file);

            // The gates are excluded from the CEILING, not from rule 1. The
            // remaining entries in MULTI_NAME_EXEMPTIONS are necessarily
            // string literals spelled in this file, and a ceiling that the
            // ledger's own reasons inflate can never fall again. Recording a
            // reason is what makes a collision tolerated; counting the
            // tolerance as duplication is a ratchet that tightens itself every
            // time someone writes down WHY. Rule 1 still reads these files,
            // so a duplicated value declared by a gate is still caught.
            if (in_array($relative, self::GATE_FILES, true)) {
                continue;
            }

            $owner = $this->ownerKey($relative);

            foreach ($this->stringLiterals($file) as $literal) {
                if (!isset($owners[$literal]) || isset($owners[$literal][$owner])) {
                    continue;
                }

                // A locale code is data about a language, not a name for the
                // constant that happens to hold one — see `isLocaleCode()`.
                if (self::isLocaleCode($literal)) {
                    continue;
                }

                $places++;
                $where[$relative][] = $literal;
            }
        }

        return [$places, $where];
    }

    /**
     * Whether the literal spells a locale code — data, not a second name for a
     * constant.
     *
     * Rule 2 needed a decision here and the measurement supplied it: `'sk_SK'`
     * was 132 of 526 counted places. 84 of those were
     * `Locales/…/SeedData.php`, the file this gate's own failure message calls
     * CORRECT ("seed data is not a constant, and making it one would be
     * worse"); the rest were Locales fixtures, a shell-copy test and the
     * themes' `html lang` assertions. One value, a quarter of the ceiling, and
     * every counted place was right.
     *
     * The reason it is data: a locale code names a LANGUAGE, while the constant
     * that owns it names this tree's DEFAULT language.
     * `ContentLocaleMapper::FALLBACK_DEFAULT` is what makes `'sk_SK'` an owned
     * value at all, but a test saying "resolve this key for `sk_SK`" is not
     * renaming the default — and forcing it to import Core's content mapper
     * would make a locale fixture describe a setting it has no opinion about.
     * So the value leaves rule 2 (how many places re-spell it) and stays in
     * rule 1 (how many NAMES it has): a second constant holding `sk_SK` is
     * still the collision this file exists to report.
     *
     * Matched by shape rather than kept as a list, because a list of locale
     * codes is another vocabulary to maintain and to rot —
     * `MULTI_NAME_EXEMPTIONS` needs a staleness check for exactly that reason —
     * while `xx_YY` is the shape every producer of a locale already uses.
     */
    private static function isLocaleCode(string $literal): bool
    {
        $bare = trim($literal, "\"'");

        return 1 === preg_match('/^[a-z]{2}_[A-Z]{2}$/', $bare) ? true : false;
    }

    /**
     * Catalogue entry and installed slice are the same text twice: without
     * this, every constant in `src/Modules/X` reads as a second site for
     * `modules/X` and the ceiling doubles for no reason.
     *
     * The catalogue has two shapes: `modules/X/` and `modules/Themes/X/`. The
     * grouped path is resolved through `ModuleCatalog`, not guessed here — a
     * theme whose key still read `modules/X/` would look like a THIRD site for
     * every constant it spells, and the ratchet would rise on a move that
     * changed no code.
     */
    private function ownerKey(string $file): string
    {
        if (!str_starts_with($file, 'src/Modules/')) {
            return $file;
        }

        $relative = substr($file, strlen('src/Modules/'));

        // The tree has the catalogue's shape: a grouped module's slice is
        // `src/Modules/<Group>/<Name>/…`, so the group segment is part of the
        // key, not of the module's name.
        $segments = explode('/', $relative, 2);
        $first = $segments[0];
        $rest = isset($segments[1]) ? '/' . $segments[1] : '';

        if (in_array($first, ModuleCatalog::catalogueGroups(), true)) {
            $nested = explode('/', $rest, 3);
            $name = $nested[1] ?? '';
            $rest = isset($nested[2]) ? '/' . $nested[2] : '';

            return 'modules/' . $first . '/' . $name . $rest;
        }

        return $this->catalogueRelative($first) . $rest;
    }

    /**
     * `modules/<Name>` or `modules/<Group>/<Name>`, as the catalogue resolves
     * it. Falls back to the flat path for a slice with no catalogue entry, so
     * an installed-only module keeps its own key instead of colliding with one.
     */
    private function catalogueRelative(string $name): string
    {
        if (isset($this->cataloguePaths[$name])) {
            return $this->cataloguePaths[$name];
        }

        $path = ModuleCatalog::fromAppPaths()->cataloguePath($name);

        return $this->cataloguePaths[$name] = null === $path
            ? 'modules/' . $name
            : substr($path, strlen(APP_BASE_PATH) + 1);
    }

    /** @return list<string> */
    private function files(): array
    {
        $found = [];

        foreach (self::SCOPE as $relative) {
            $base = APP_BASE_PATH . '/' . $relative;

            if (!is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }

                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $path = $file->getPathname();

                // Build output: copied bytes from the frontend, not declarations.
                if (str_starts_with($path, APP_BASE_PATH . '/public/dist/')) {
                    continue;
                }

                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(APP_BASE_PATH) + 1);
    }

    /**
     * Every string literal in a file, flat.
     *
     * Not keyed by line: `assertSame('x', $row['y'])` puts two literals on one
     * line, and keying by line let the second overwrite the first — dropping
     * precisely the values a test asserts, which are the ones rule 2 is about.
     *
     * @return list<string>
     */
    private function stringLiterals(string $file): array
    {
        $out = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)
                && T_CONSTANT_ENCAPSED_STRING === $token[0]
                && $this->isQuotedString($token[1])
            ) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Tokens that can form part of a constant's type. `array` is T_ARRAY and
     * `static` is T_STATIC, so a T_STRING-only list loses every `const array`.
     *
     * @var list<int>
     */
    private const array TYPE_TOKENS = [
        T_STRING,
        T_ARRAY,
        T_CALLABLE,
        T_STATIC,
        T_NS_SEPARATOR,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * Every `const NAME = …` declaration in the scope, typed ones included.
     *
     * @return list<array{file: string, name: string, value: ?string, line: int}>
     */
    private function declarations(): array
    {
        $found = [];

        foreach ($this->files() as $file) {
            foreach ($this->declarationsIn($file) as $declaration) {
                $declaration['file'] = $this->relative($file);
                $found[] = $declaration;
            }
        }

        return $found;
    }

    /**
     * @return list<array{file: string, name: string, value: ?string, line: int}>
     */
    private function declarationsIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || T_CONST !== $tokens[$i][0]) {
                continue;
            }

            $nameIndex = $this->nameIndexAfterConst($tokens, $count, $i);

            if (null === $nameIndex) {
                continue;
            }

            $assign = $this->skipSpace($tokens, $count, $nameIndex + 1);

            if ('=' !== ($tokens[$assign] ?? null)) {
                continue;
            }

            $value = $this->literalValue($tokens, $count, $assign + 1);

            $found[] = [
                'file' => '',
                'name' => $tokens[$nameIndex][1],
                'value' => $value,
                'line' => $tokens[$i][2],
            ];
        }

        return $found;
    }

    /**
     * The index of the constant's NAME, whether or not a type precedes it.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return int|null
     */
    private function nameIndexAfterConst(array $tokens, int $count, int $constIndex): ?int
    {
        $cursor = $this->skipSpace($tokens, $count, $constIndex + 1);
        $start = $cursor;

        while ($cursor < $count) {
            // An element is a token ARRAY or a bare character — `?` and `|`
            // arrive as one-character strings, which is why this is not called
            // `$token`: a security linter reads that name as a credential.
            $element = $tokens[$cursor];

            if (!is_array($element)) {
                if ('?' === $element || '|' === $element) {
                    $cursor = $this->skipSpace($tokens, $count, $cursor + 1);

                    continue;
                }

                break;
            }

            if (!in_array($element[0], self::TYPE_TOKENS, true)) {
                break;
            }

            $next = $this->skipSpace($tokens, $count, $cursor + 1);
            $after = $tokens[$next] ?? null;

            if (is_array($after)
                && T_STRING === $after[0]
                && 1 === preg_match('/^[A-Z][A-Z0-9_]*$/', $after[1])
            ) {
                return $next;
            }

            if (is_array($after) && in_array($after[0], self::TYPE_TOKENS, true)) {
                $cursor = $next;

                continue;
            }

            // A type token with nothing type-like or name-like after it was
            // the name all along — this is the untyped `const NAME = …` case.
            return $cursor;
        }

        if ($start === $cursor) {
            $token = $tokens[$cursor] ?? null;

            if (is_array($token)
                && T_STRING === $token[0]
                && 1 === preg_match('/^[A-Z][A-Z0-9_]*$/', $token[1])
            ) {
                return $cursor;
            }
        }

        return null;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function skipSpace(array $tokens, int $count, int $from): int
    {
        while ($from < $count && is_array($tokens[$from]) && T_WHITESPACE === $tokens[$from][0]) {
            $from++;
        }

        return $from;
    }

    /**
     * A single scalar literal, or null for anything computed.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function literalValue(array $tokens, int $count, int $from): ?string
    {
        $index = $this->skipSpace($tokens, $count, $from);
        $token = $tokens[$index] ?? null;

        if (!is_array($token)) {
            return null;
        }

        return match ($token[0]) {
            T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER => $token[1],
            default => null,
        };
    }

    /** Not `isString()`: that name is a final method on PHPUnit's Assert. */
    private function isQuotedString(string $raw): bool
    {
        return str_starts_with($raw, "'") || str_starts_with($raw, '"');
    }
}
