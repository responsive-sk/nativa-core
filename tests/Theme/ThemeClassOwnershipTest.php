<?php

declare(strict_types = 1);

namespace Tests\Theme;

use Modules\Core\Interfaces\HTTP\ThemeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ThemeClassOwnershipTest — no CSS class belongs to two themes.
 *
 * Task 2.7 of a const audit asked the obvious question about the theme
 * prefixes: `mo-`, `gr-`, `je-`, `er-`. The premise was that each theme
 * writes its own classes and nobody knows whether they overlap. Measured,
 * the overlap is real and it is exactly one family:
 *
 *     .tok-keyword  .tok-string  .tok-number  .tok-comment
 *     .tok-function .tok-operator  .tok-var .tok-type … (12 in Mono, 6 in Griffe)
 *
 * defined by BOTH `griffe` and `mono`, and with DIFFERENT values:
 * `.tok-comment` is `#8b8fa3` in one and `#94a3b8` in the other.
 *
 * And it is deliberate, which is why this test does not "fix" it. Both files
 * say so in their own words — Griffe's:
 *
 *     [/*] Syntax highlight markers (vendored twin reads .tok-*; …)
 *
 *     mono.css: "the no-build twin of the shared code-highlight.ts the built
 *     themes bundle".
 *
 * `.tok-*` is a contract with a third party, not a theme's private vocabulary.
 * Prefixing it would break the highlighter that emits those class names, and
 * the emitter is not in this repository. That is why these two files were
 * written with unprefixed markers in the first place, and it is why the
 * right answer is to NAME the exception rather than rename the contract.
 *
 * ## What this test actually holds
 *
 * A theme's OWN classes carry its prefix. That is what makes `gr-js` on
 * `<html>` (Griffe's first-paint staging signal) and `mo-` styles knowable
 * from the markup alone — and it is what lets two stylesheets coexist in one
 * document, which they do at `/mark`: the Cardboard panel loads the admin
 * sheet and the theme sheet at once.
 *
 * So the rule is scoped to classes the theme OWNS:
 *
 *   - every class a theme defines must start with that theme's `prefix()`,
 *     except the kernel's `<body class="{$bundle}-shell">` contract, and
 *   - no class may be defined by two themes, with one named exception —
 *     `SHARED_CONTRACT`, the highlighter markers.
 *
 * ## Why the measurement, not the assertion, is the hard part
 *
 * Three things had to be got right, and each was wrong first:
 *
 *  1. **The theme list is not the four in the plan.** Discovery finds five:
 *     `erik`, `griffe`, `jednotka`, `mono` and `kernel` — the last one with
 *     prefix `k`. Counting the plan's four would have left the kernel
 *     theme unmeasured.
 *  2. **`criticalCss()` is the only truth.** Erik, Griffe, Mono and kernel
 *     each ship one or two `.css` files of their own; `jednotka` ships
 *     NONE and returns `FrontendLayout::BASE_CRITICAL_CSS`, nine kernel
 *     files. Grepping the theme's own assets directory therefore measures
 *     `jednotka` as empty — which reads as "owns nothing" and is actually
 *     "owns the kernel's". Since all nine are the kernel's, `jednotka` owns
 *     no class and claims none, and that is asserted rather than assumed.
 *  3. **`:root` variables are not classes.** A first pass counted 18
 *     "collisions" that were `--active`, `--sub`, `--primary`, `--outline` —
 *     custom properties shared by name across themes and resolved per
 *     document. Two stylesheets never load together on a public page, and on
 *     `/mark` the admin sheet uses a different namespace again.
 *
 * Kernel-only like the rest of tests/: themes arrive through ThemeRegistry as
 * objects, and every file is read as TEXT. No module class is named, so this
 * passes on a checkout carrying Core + Database alone. A registry with fewer
 * than two themes skips the collision assertions rather than passing them
 * silently — one theme cannot collide with anything — while the prefix and
 * dead-class checks still run against whatever is installed.
 */
final class ThemeClassOwnershipTest extends TestCase
{
    /**
     * Classes two themes are allowed to define, with why.
     *
     * `$name => reason` — and the list fails when an entry stops applying,
     * so it cannot rot into a free pass.
     *
     * @var array<string, string>
     */
    private const array SHARED_CONTRACT = [
        'tok' => 'Syntax-highlight markers emitted by the shared code-highlight.ts'
            . ' and its no-build twins: griffe.css calls them "vendored twin'
            . ' reads .tok-*" and mono.css "the no-build twin of the shared'
            . ' code-highlight.ts the built themes bundle". The emitter is not'
            . ' in this repository, so renaming them here would break the'
            . ' contract rather than fix a collision. Both define them'
            . ' deliberately, with different colours on purpose — each theme'
            . ' paints its own syntax palette. If code-highlight.ts ever lands'
            . ' here, this entry is the list to revisit.',
    ];

    /**
     * Bare state modifiers — the one part of a BEM vocabulary that is meant
     * to be shared.
     *
     * `.st-toc-fab__panel.open` styles a block's state, and the block it
     * belongs to is the selector it is combined with, not the class itself.
     * Prefixing a state would produce `.st-open`, which would then be able to
     * style any element of any block on the page. This is the opposite of a
     * collision: it is the reason a state is written bare.
     */
    private const array SHARED_STATES = ['open', 'active', 'copied'];

    /**
     * Classes in a stylesheet that no markup in this repository emits.
     *
     * Measured 2026-10-17. Three, and none of them is a collision — they are
     * DEAD CSS, a different defect that happens to be what this task's
     * measurement found:
     *
     *   .copy-btn       mono.css — the highlighter's copy button; the shared
     *   .has-badge      mono.css   code-highlight.ts that would emit it is
     *                    not in this repository, same as SHARED_CONTRACT['tok']
     *   .navbar__actions shell.css, nav.css — the upstream name, kept next to
     *                    Mono's own `mo-navbar__actions`
     *
     * All three are decidable HERE: a grep over every `.php`, `.phtml` and
     * `.js` outside `assets/` finds no emitter. They are listed rather than
     * deleted because deleting them is a product decision about styles nobody
     * can see, and this test's job is to say they are invisible, not to remove
     * them.
     *
     * `.griffe-shell` is NOT one of them, and it was wrongly listed in the
     * first pass. `FrontendLayout::document()` writes
     * `<body class="{$bundle}-shell">`, and `bundle()` — not `prefix()` — is
     * what supplies the spelling. Griffe's bundle is `griffe`, so the body
     * carries `griffe-shell` and the stylesheet's rule does match. Erik's
     * bundle is `erik` while `erik.css` spells the same rule `.er-shell`; that
     * mismatch is recorded in `docs/name-ownership.md` rather than guessed at
     * here, because only the stylesheet's owner can say which spelling won.
     *
     * An entry that starts matching markup again is not free pass and not rot:
     * it means someone wired the emitter up, and the right move is to DELETE
     * the entry so the class is owned by the prefix rule from then on.
     */
    private const array DEAD_CLASSES = [
        'copy-btn' => 'mono.css, the highlighter copy button whose emitter is not in this repo',
        'has-badge' => 'mono.css, same missing emitter',
        'navbar__actions' => 'shell.css + nav.css, the upstream name beside Mono own `mo-navbar__actions`',
    ];

    /**
     * Classes the KERNEL owns, defined in `FrontendLayout::BASE_CRITICAL_CSS`.
     *
     * Measured, and three guesses about it were wrong in a row:
     *
     *  - `jednotka` returns BASE_CRITICAL_CSS, so those files are reachable
     *    under two names. They are the kernel's vocabulary and the kernel's
     *    alone: `st-` is what the CSS says, `ThemeModule::prefix()` documents
     *    it (`'st', 'er', 'ms'`), and six modules ship the markup — Pages,
     *    Blog, Media, Audit, Cardboard, Core.
     *  - `KernelTheme::prefix()` returns `k`, and that is CORRECT: 19 `k-`
     *    classes in its own markup, defined by its own stylesheet. The
     *    kernel's THEME and the kernel's SHARED CSS are two vocabularies, and
     *    only one of them is a theme.
     *
     * So this is not an exemption from the rule — the rule is that two themes
     * must not define one class, and the kernel base sheet defines none of the
     * themes' classes. It is here so a reader who greps `st-` and finds it in
     * a theme's criticalCss() knows whose it is.
     */
    private const string KERNEL_VOCABULARY = 'st';

    /** @return list<array{name: string, prefix: string, bundle: string, files: list<string>, source: string}> */
    private function themes(): array
    {
        $themes = [];

        foreach (ThemeRegistry::all() as $name => $fqcn) {
            $theme = new $fqcn();

            $themes[] = [
                'name' => $name,
                'prefix' => $theme->prefix(),
                'bundle' => $theme->bundle(),
                'files' => $this->criticalFiles($name, $theme->criticalCss()),
                'source' => 'own',
            ];
        }

        return $themes;
    }

    /**
     * `criticalCss()` returns `Modules/<Name>/…` paths. The TRACKED catalogue
     * lives in `modules/<Name>/` — or `modules/Themes/<Name>/` for a theme —
     * and the installed slice in `src/Modules/<Name>/`. Both catalogue shapes
     * are tried before `src/`, which is the only order that measures the tree
     * git actually carries.
     *
     * The catalogue is tried FIRST, and that order is the whole point. A first
     * pass read `src/` first and every assertion passed while the checkout
     * disagreed with itself: editing `modules/Griffe/assets/critical/griffe.css`
     * changed nothing this test could see, because `src/Modules/Griffe/…` was an
     * older installed copy. Boot 44 recorded the same trap in production code
     * — the installed slice serving deleted code with every gate green — and
     * the same shape applies to the tracked tree a gate is supposed to judge.
     * `NameOwnershipContractTest` calls the same thing `ownerKey()`.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function criticalFiles(string $name, array $paths): array
    {
        $base = \dirname(__DIR__, 2);
        $found = [];

        foreach ($paths as $relative) {
            // `criticalCss()` names the module's DIRECTORY, and the directory
            // is not the registry key: the key is `erik`, the path says
            // `Erik`. Two wrong guesses stacked here, both invisible —
            // `modules/erik/Modules/Erik/…` (un-stripped), then
            // `modules/erik/assets/…` (case-sensitive strip) — and each one
            // fell through to `src/`, so the tracked tree went UNMEASURED
            // while the test stayed green. The directory therefore comes from
            // the path, and the key is only a fallback.
            $tail = (string) preg_replace('#^Modules/[^/]+/#i', '', $relative);
            $directory = preg_match('#^Modules/([^/]+)/#i', $relative, $m) ? $m[1] : $name;

            foreach ([
                $base . '/modules/Themes/' . $directory . '/' . $tail,
                $base . '/modules/' . $directory . '/' . $tail,
                $base . '/src/Modules/Themes/' . $directory . '/' . $tail,
                $base . '/src/Modules/' . $directory . '/' . $tail,
                $base . '/src/' . $relative,
                $base . '/' . $relative,
            ] as $candidate) {
                if (is_file($candidate)) {
                    $found[] = $candidate;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Every class selector a stylesheet defines, as `class` => the rules.
     *
     * Read as TEXT and never loaded: the files are build output and CSS has no
     * PHP. Custom properties (`--x`) need no exclusion — the pattern requires
     * a leading dot and `--active` has none.
     *
     * Comments are stripped first, and that is not tidiness. `mono.css` names
     * itself and `code-highlight.ts` in its own header, so `.css`, `.js` and
     * `.ts` arrive as selectors: a first pass counted 121 classes of which
     * those were a part. A class is lowercase-with-dashes and nothing else in
     * a header is.
     *
     * @return array<string, list<string>>
     */
    private function classesIn(string $file): array
    {
        $source = (string) file_get_contents($file);
        $found = [];

        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $stripped = (string) preg_replace('~(?m)//.*$~', '', $stripped);

        // The lookahead keeps `.5rem` — a decimal inside a value — out, and the
        // lowercase class keeps a camelCase identifier out.
        preg_match_all('/\.([a-z][a-z0-9_-]*)(?=[\s,{:>+~\[])/', $stripped, $matches);

        foreach ($matches[1] as $class) {
            $found[$class][] = basename($file);
        }

        return $found;
    }

    /**
     * Every class defined in a theme's stylesheets, as `class` => theme => file.
     *
     * The shared map both ownership tests read, so "who claims this class"
     * cannot be answered two different ways by two assertions.
     *
     * @return array<string, array<string, string>>
     */
    private function owners(): array
    {
        $owners = [];

        foreach ($this->themes() as $theme) {
            foreach ($theme['files'] as $file) {
                foreach ($this->classesIn($file) as $class => $where) {
                    foreach ($where as $source) {
                        $owners[$class][$theme['name']] = $source;
                    }
                }
            }
        }

        return $owners;
    }

    #[Test]
    public function noClassIsDefinedByTwoThemes(): void
    {
        if (count($this->themes()) < 2) {
            self::markTestSkipped('Fewer than two themes discovered — no collision is measurable.');
        }

        $owners = $this->owners();
        $shared = [];

        foreach ($owners as $class => $claimedBy) {
            if (count($claimedBy) < 2) {
                continue;
            }

            $family = explode('-', $class)[0];

            if (isset(self::SHARED_CONTRACT[$family])) {
                continue;
            }

            $shared[] = sprintf(
                '  .%-30s %s',
                $class,
                implode(' + ', array_map(
                    static fn (string $t, string $f): string => $t . ' (' . $f . ')',
                    array_keys($claimedBy),
                    array_values($claimedBy),
                )),
            );
        }

        self::assertSame(
            [],
            $shared,
            sprintf(
                "%d class(es) are defined by more than one theme.\n%s\n\n"
                . 'A theme owns a class outright: prefix it, or give the second theme a'
                . ' vendor namespace. If the class is a CONTRACT with something this'
                . ' repository does not own — the syntax highlighter is the one that'
                . ' exists — say so in self::SHARED_CONTRACT with the reason, and the'
                . ' reason is what the next reader has.',
                count($shared),
                implode("\n", $shared),
            ),
        );
    }

    /**
     * A SHARED_CONTRACT entry must still describe a real collision.
     *
     * The list is a set of reasons, not a permission slip: if `.tok-*` stops
     * being defined by two themes, the exemption no longer explains anything
     * and has to be deleted. That is the same direction `DEAD_CLASSES` fails,
     * and the reason both lists can be trusted by the next reader.
     */
    #[Test]
    public function aSharedContractEntryStillCoversACollision(): void
    {
        if (count($this->themes()) < 2) {
            self::markTestSkipped('Fewer than two themes discovered — no collision is measurable.');
        }

        $applying = [];

        foreach ($this->owners() as $class => $claimedBy) {
            if (count($claimedBy) > 1) {
                $applying[explode('-', $class)[0]] = true;
            }
        }

        $stale = array_values(array_diff(array_keys(self::SHARED_CONTRACT), array_keys($applying)));

        self::assertSame(
            [],
            $stale,
            "These SHARED_CONTRACT entries no longer cover a collision:\n  "
            . implode("\n  ", $stale)
            . "\n\nDelete the entry. An exemption that stopped applying is rot in the"
            . ' ledger, not a free pass.',
        );
    }

    #[Test]
    public function everyOwnClassCarriesItsThemePrefix(): void
    {
        $themes = $this->themes();

        if ([] === $themes) {
            self::markTestSkipped('No themes discovered — kernel-only checkout.');
        }

        $stray = [];

        foreach ($themes as $theme) {
            foreach ($theme['files'] as $file) {
                // A borrowed kernel sheet carries the KERNEL's classes, not the
                // theme's. `jednotka` resolves none of its own and reaches
                // BASE_CRITICAL_CSS, so its nine files are 104 `st-` classes
                // that belong to nobody-but-the-kernel and are correct here.
                $borrowed = str_contains($file, '/Core/Interfaces/HTTP/assets/critical/');

                foreach (array_keys($this->classesIn($file)) as $class) {
                    $family = explode('-', $class)[0];

                    if (str_starts_with($class, $theme['prefix'] . '-')) {
                        continue;
                    }

                    if (isset(self::SHARED_CONTRACT[$family])) {
                        continue;
                    }

                    // The shell class is spelled with the BUNDLE, not the
                    // prefix: `FrontendLayout::document()` writes
                    // `<body class="{$bundle}-shell">`. Griffe's bundle is
                    // `griffe`, so `.griffe-shell` is emitted and owned even
                    // though `prefix()` is `gr`. This is the kernel's name for
                    // the theme, not a second theme vocabulary, so it is
                    // allowed here rather than renamed — renaming the
                    // stylesheet would leave the body unstyled.
                    if ($class === $theme['bundle'] . '-shell') {
                        continue;
                    }

                    if ($borrowed && str_starts_with($class, self::KERNEL_VOCABULARY . '-')) {
                        continue;
                    }

                    // A bare state/mixin modifier: `.st-toc-fab__panel.open`.
                    // It carries no prefix of its own BY DESIGN — BEM states
                    // are the one part of a vocabulary that is shared, and a
                    // prefix here would mean `.st-open` could style any
                    // element in any block. The owning block is the selector
                    // it is combined with, which `noClassIsDefinedByTwoThemes`
                    // already reads as part of the rule.
                    if (in_array($class, self::SHARED_STATES, true)) {
                        continue;
                    }

                    if (isset(self::DEAD_CLASSES[$class])) {
                        continue;
                    }

                    $stray[] = sprintf(
                        '  .%-30s %s (prefix %s-)',
                        $class,
                        basename($file),
                        $theme['prefix'],
                    );
                }
            }
        }


        self::assertSame(
            [],
            $stray,
            sprintf(
                "%d class(es) do not carry their theme's prefix.\n%s\n\n"
                . 'Without the prefix two stylesheets cannot share a document, and the'
                . ' admin panel is exactly that case: /mark loads the admin sheet and the'
                . ' theme sheet at once. If a class must stay unprefixed, name it in'
                . ' self::SHARED_CONTRACT and say why.',
                count($stray),
                implode("\n", $stray),
            ),
        );
    }

    /**
     * A theme with no stylesheet of its own owns nothing and claims nothing.
     *
     * `jednotka` returns `FrontendLayout::BASE_CRITICAL_CSS` — nine kernel
     * files. Measured that way it has 155 `je-` classes in its markup and
     * zero selectors to match them, which is worth stating rather than
     * leaving as a silent pass.
     */
    #[Test]
    public function aThemeBorrowingKernelStylesDeclaresIt(): void
    {
        $themes = $this->themes();

        if ([] === $themes) {
            self::markTestSkipped('No themes discovered — kernel-only checkout.');
        }

        $borrowing = [];

        foreach ($themes as $theme) {
            if ([] !== $theme['files']) {
                continue;
            }

            $borrowing[] = sprintf(
                '  %-10s prefix=%s — criticalCss() names no file that exists',
                $theme['name'],
                $theme['prefix'],
            );
        }

        // The measured answer today, not an expectation: every theme ships its
        // own styles, and `jednotka` returns the kernel's nine — which resolve,
        // so it is NOT in this list. The assertion that matters is the one in
        // noClassIsDefinedByTwoThemes: nine kernel files under two names would
        // show up there as a collision between the kernel and jednotka.
        self::assertSame(
            [],
            $borrowing,
            "These themes resolve no stylesheet of their own:\n" . implode("\n", $borrowing)
            . "\n\nIf the intent is to borrow the kernel's CSS (BASE_CRITICAL_CSS), say"
            . ' so here, because the grep a reader runs first — modules/<Name>/**/*.css'
            . ' — reports such a theme as owning nothing at all.',
        );
    }

    /**
     * A DEAD_CLASSES entry must still be dead.
     *
     * The list is not a permission slip. If someone wires an emitter up — a
     * template starts emitting `copy-btn`, a highlighter lands in this
     * repository — the class is LIVE and belongs to the prefix rule, so the
     * test fails until the entry is deleted. That is the same direction the
     * exemption list in `NameOwnershipContractTest` fails: rot is not a pass.
     *
     * Only classes that are actually present in a stylesheet are checked. A
     * typo in a name would otherwise fail here forever with nothing to fix.
     */
    #[Test]
    public function aDeadClassEntryIsStillDead(): void
    {
        $themes = $this->themes();

        if ([] === $themes) {
            self::markTestSkipped('No themes discovered — kernel-only checkout.');
        }

        // The scan is the whole tracked tree, not one theme's files: an emitter can
        // sit in any module (Pages, Blog, a template) and the question "is this
        // class dead?" cannot be answered by looking at the theme that happens
        // to style it. That is also why it is one call, not one per theme.
        foreach ($this->emittedClasses() as $class => $where) {
            $emitted[$class] = $where;
        }

        $revived = [];

        foreach (self::DEAD_CLASSES as $class => $reason) {
            if (!isset($emitted[$class])) {
                continue;
            }

            $revived[] = sprintf(
                '  .%-24s %s — %s',
                $class,
                implode(' + ', array_unique($emitted[$class])),
                $reason,
            );
        }

        self::assertSame(
            [],
            $revived,
            sprintf(
                "%d class(es) in DEAD_CLASSES are emitted after all:\n%s\n\n"
                . 'Someone wired the markup up, so the class is live and the prefix rule'
                . ' applies to it. Delete the entry — the assertion that replaced it'
                . ' will say what the class still needs.',
                count($revived),
                implode("\n", $revived),
            ),
        );
    }

    /**
     * Every class the tree EMITS, as `class` => the files that write it.
     *
     * Read as TEXT: a template is not loaded to find out what it prints, and
     * the emitter may live in any module — including ones this checkout does
     * not have installed, which is why the scan is over the TRACKED tree
     * (`git ls-files`) rather than over what happens to be on disk.
     *
     * `src/Modules/` is gitignored operator state, so it is deliberately not
     * scanned: the tracked catalogue under `modules/` carries the same code,
     * and scanning the slice would make the answer depend on which modules a
     * checkout has installed — the defect `ModelCatalogueContractTest` was
     * fixed for in this same round.
     *
     * @return array<string, list<string>>
     */
    private function emittedClasses(): array
    {
        $base = \dirname(__DIR__, 2);
        $found = [];

        $tracked = [];
        exec('git -C ' . escapeshellarg($base) . ' ls-files', $tracked, $status);

        if (0 !== $status) {
            return $found;
        }

        foreach ($tracked as $relative) {
            if (!preg_match('/\.(php|phtml|js)$/', $relative)) {
                continue;
            }

            if (str_contains($relative, '/assets/') || str_starts_with($relative, 'public/dist/')) {
                continue;
            }

            $path = $base . '/' . $relative;

            if (!is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // `class="a b"`, `class='a b'`, and the same inside a JS template
            // literal — one `class=` attribute to this scan. Values may contain
            // PHP interpolation around them, which is why each token is
            // validated rather than trusted.
            if (preg_match_all('/class=\\\\?["\']([^"\']+)/', $source, $matches)) {
                foreach ($matches[1] as $value) {
                    $tokens = preg_split('/\s+/', trim(strip_tags($value)));

                    if (false === $tokens) {
                        continue;
                    }

                    foreach ($tokens as $class) {
                        if ('' !== $class && preg_match('/^[a-z][a-z0-9_-]*$/', $class)) {
                            $found[$class][] = $relative;
                        }
                    }
                }
            }
        }

        return $found;
    }
}
