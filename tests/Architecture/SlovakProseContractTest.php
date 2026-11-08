<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SlovakProseContractTest — the articles are Slovak, not Czech.
 *
 * Measured 2026-10-23: the archive draft
 * `docs/clanky/2026-10-16-posledna-evolucia.md` carried whole Czech sentences
 * ("Větev skladá scénu", "Pět instalovaných modulů", "uklízila") between
 * Slovak paragraphs. Nothing caught it, because no gate reads the prose at
 * all — `DocsCatalogContractTest` checks the MAP and the file names, not the
 * language inside them.
 *
 * This check is deliberately narrow: it looks for the three characters Slovak
 * never uses — **ě**, **ř**, **ů** (and their uppercase forms). They are the
 * cheapest unmistakable signal that a Czech word slipped in. It cannot see
 * Czech vocabulary written without them ("jsem", "který", "protože" uses ž/ý),
 * so it is a floor, not a spell-checker; the glossary in `CONTRIBUTING.md`
 * and review carry the rest.
 *
 * The only allowed occurrences are the FONT article's charset fixtures, where
 * the characters are the subject: a glyph-coverage list that must name every
 * Czech letter. They are exempted by a substring on the line, not by line
 * number — a shifted line keeps its exemption, an edited line loses it and
 * has to be re-argued.
 *
 * @internal
 *
 * @coversNothing
 */
final class SlovakProseContractTest extends TestCase
{
    /**
     * Lines that may contain Czech diacritics, keyed by repository-relative
     * path with a substring that identifies the line.
     *
     * A method, not a class constant: `NameOwnershipContractTest` ratchets the
     * tree's `const` count, and a test's own lookup table is not a name
     * anybody else owns (the same choice `DocsCatalogContractTest` records).
     *
     * @return array<string, list<string>>
     */
    private static function exemptions(): array
    {
        return [
            'storage/blog/optimalizacia-font-setov.md' => [
                // The glyph checklist: Slovak letters first, then the Czech
                // ones the font must also carry for the charset test.
                'chýba: Č Ď Ĺ Ľ Ň Ŕ Š Ť Ž',
                // The fixture itself, fed to the cmap comparison.
                'missing = [c for c in',
            ],
        ];
    }

    /**
     * No Slovak content file contains ě, ř or ů.
     */
    #[Test]
    public function noCzechDiacriticsInSlovakProse(): void
    {
        $files = self::contentFiles();

        self::assertGreaterThanOrEqual(
            10,
            count($files),
            'The prose walk found ' . count($files) . ' files — the roots moved, and this check would'
            . ' pass by reading almost nothing.',
        );

        foreach ($files as $path) {
            $relative = self::relative($path);
            $lines = @file($path, FILE_IGNORE_NEW_LINES);

            self::assertIsArray($lines, sprintf('Could not read %s.', $relative));

            foreach ($lines as $index => $line) {
                if (1 !== preg_match('/[ěĚřŘůŮ]/u', $line, $matched)) {
                    continue;
                }

                self::assertTrue(
                    self::isExempt($relative, $line),
                    sprintf(
                        '%s:%d contains "%s", a character Slovak does not use. Czech and Slovak are'
                        . ' two languages: translate the sentence, or write the Slovak letter. If the'
                        . ' character is the subject of the line (a charset fixture), name the line in'
                        . ' exemptions() — the font article\'s two glyph lists are the only entries'
                        . ' today.',
                        $relative,
                        $index + 1,
                        $matched[0],
                    ),
                );
            }
        }
    }

    /**
     * An exemption that no longer matches is a stale approval: the line moved,
     * changed, or the file is gone, and the next Czech character could hide
     * under it.
     */
    #[Test]
    public function theExemptionsStillPointAtRealLines(): void
    {
        foreach (self::exemptions() as $relative => $markers) {
            $path = self::root() . '/' . $relative;

            self::assertFileExists($path, sprintf('The exemption names %s, which is gone.', $relative));

            $contents = (string) file_get_contents($path);

            foreach ($markers as $marker) {
                self::assertStringContainsString(
                    $marker,
                    $contents,
                    sprintf(
                        'The exemption for %s no longer finds "%s". Remove the stale entry, or update'
                        . ' it to the line that now carries the deliberate Czech characters.',
                        $relative,
                        $marker,
                    ),
                );
            }
        }
    }

    /**
     * The scanner must see the characters it claims to refuse. A broken
     * pattern would make the whole check green without reading one line.
     */
    #[Test]
    public function theScannerMatchesEveryRefusedCharacter(): void
    {
        foreach (['ě', 'Ě', 'ř', 'Ř', 'ů', 'Ů'] as $character) {
            self::assertSame(
                1,
                preg_match('/[ěĚřŘůŮ]/u', $character),
                sprintf('The pattern does not match "%s" — the gate would miss it.', $character),
            );
        }

        self::assertSame(
            0,
            preg_match('/[ěĚřŘůŮ]/u', 'č ď ľ ĺ ň ô ä ŕ š ť ž'),
            'The pattern matches Slovak letters, so a Slovak article would fail its own gate.',
        );
    }

    /**
     * The Slovak prose surfaces: the blog corpus, the article archive and the
     * site pages. `docs/` outside `clanky/` is English on purpose and is not
     * read here.
     *
     * @return list<string>
     */
    private static function contentFiles(): array
    {
        $files = [];

        foreach (['storage/blog', 'docs/clanky', 'storage/pages'] as $directory) {
            foreach (glob(self::root() . '/' . $directory . '/*.md') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function isExempt(string $relative, string $line): bool
    {
        foreach (self::exemptions()[$relative] ?? [] as $marker) {
            if (str_contains($line, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $path): string
    {
        return str_replace(self::root() . '/', '', $path);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
