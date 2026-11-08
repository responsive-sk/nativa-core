<?php

declare(strict_types = 1);

namespace Tests\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProseRhythmContractTest, the written form of the prose in this tree.
 *
 * `prose-separator` names the rules; this is the part of them a machine can
 * see. The list below is the set of wrapped files, and it only grows: a file
 * joins it once its prose is converted, so the ratchet never needs a reason to
 * shrink. What this cannot judge is where a sentence may be cut (verb and
 * object stay together, a noun keeps its attribute); that stays a decision an
 * author makes, with the examples in the spec as the model.
 *
 * The `>` that once ended a mid-sentence line was retired. A line is still one
 * thought, and a break that continues the sentence is now an ordinary wrap, so
 * these checks read the source lines rather than a glyph.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProseRhythmContractTest extends TestCase
{
    /**
     * Files wrapped in the new form. Add one per converted file, and only after
     * it passes every check below.
     *
     * @return list<string>
     */
    private static function wrapped(): array
    {
        return [
            'docs/core-philosophy.md',
            'docs/prose-rhythm.md',
            'storage/blog/ako-prispievat-cez-pull-request.md',
            'storage/blog/boolean-nestaci-a-predsa-staci.md',
            'storage/blog/fatalna-chyba-a-proces-ktory-zomrel.md',
            'storage/blog/herdr-workspaces.md',
            'storage/blog/hyprland-namiesto-xfce.md',
            'storage/blog/mago-nad-modulovym-katalogom.md',
            'storage/blog/mago-rust-v-php-strome.md',
            'storage/blog/nativa-php-core.md',
            'storage/blog/optimalizacia-font-setov.md',
            'storage/blog/php2shell-tri-dni-tri-cve.md',
            'storage/blog/sest-nastrojov-a-jedna-otazka.md',
            'storage/blog/siesta-funkcia-portu.md',
            'storage/blog/tri-navrhy-na-prepojenie-nastrojov.md',
            'storage/blog/tri-subory-v-src.md',
        ];
    }

    /**
     * Function words that may not end a line: a preposition, a conjunction or
     * an auxiliary left dangling is the defect the eye catches first, and it is
     * the mechanical shadow of a phrase cut in the wrong place.
     *
     * @return list<string>
     */
    private static function danglingWords(): array
    {
        return [
            // prepositions
            'na', 'v', 'vo', 's', 'so', 'z', 'zo', 'do', 'pre', 'o', 'po', 'pri',
            'k', 'ku', 'za', 'pred', 'cez', 'medzi', 'bez', 'pod', 'nad', 'proti',
            'oproti', 'počas', 'podľa', 'kvôli', 'vďaka',
            // conjunctions
            'a', 'aj', 'ale', 'no', 'ani', 'či', 'lebo', 'pretože', 'takže',
            'však', 'hoci', 'aby', 'keď',
            // auxiliaries and particles
            'je', 'sa', 'si', 'by', 'som', 'sme', 'sú', 'bol', 'bola', 'bolo',
            'nie', 'už', 'len', 'ten', 'tá', 'tie', 'ktorý', 'ktorá',
            'ktoré', 'the', 'a', 'an', 'of', 'in', 'is', 'are', 'was',
            'with', 'and', 'but', 'or', 'as', 'at', 'for', 'on', 'by',
        ];
    }

    /**
     * A paragraph, as the lines a reader would see joined: headings, lists,
     * tables and fenced code are not prose and are skipped.
     *
     * @return list<array{file: string, lines: list<string>}>
     */
    private static function paragraphs(string $file): array
    {
        $content = (string) @file_get_contents(self::root() . '/' . $file);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $paragraphs = [];
        $current = [];
        $fence = false;
        $front = false;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (0 === $index && '---' === $trimmed) {
                $front = true;
                continue;
            }

            if ($front) {
                if ('---' === $trimmed) {
                    $front = false;
                }

                continue;
            }

            if (str_starts_with($trimmed, '```')) {
                $fence = !$fence;
                continue;
            }

            if ($fence) {
                continue;
            }

            $isProse = '' !== $trimmed
                && !str_starts_with($trimmed, '#')
                && !str_starts_with($trimmed, '|')
                && !str_starts_with($trimmed, '>')
                && !str_starts_with($trimmed, '---')
                && 1 !== preg_match('/^[-*+]\s+/u', $trimmed)
                && 1 !== preg_match('/^\d+\.\s+/u', $trimmed)
                && $line === ltrim($line);

            if (!$isProse) {
                if ([] !== $current) {
                    $paragraphs[] = ['file' => $file, 'lines' => $current];
                    $current = [];
                }

                continue;
            }

            $current[] = $trimmed;
        }

        if ([] !== $current) {
            $paragraphs[] = ['file' => $file, 'lines' => $current];
        }

        return $paragraphs;
    }

    /**
     * The sentences of one paragraph, each with its length in words.
     *
     * The lines are joined before the sentences are cut, because a wrap is a
     * break inside a sentence and not a sentence end. Inline code counts as one
     * word, the way a reader takes it, and emphasis markers count as none.
     *
     * @param list<string> $lines
     *
     * @return list<array{text: string, words: int}>
     */
    private static function sentences(array $lines): array
    {
        $joined = implode(' ', $lines);

        $sentences = [];

        foreach (preg_split('/(?<=[.!?])\s+/u', $joined) ?: [] as $sentence) {
            $masked = (string) preg_replace('/`[^`]*`/u', 'word', $sentence);
            $masked = str_replace(['**', '`', '>'], ' ', $masked);
            $words = preg_split('/\s+/u', trim($masked), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ([] === $words) {
                continue;
            }

            $sentences[] = ['text' => trim($sentence), 'words' => count($words)];
        }

        return $sentences;
    }

    /**
     * The tempo of a file, as docs/prose-rhythm.md names it: how long its
     * sentences are, and the three things a run of sentences must not do.
     *
     * `long` counts a sentence over 40 words, `metronome` a run of five
     * sentences whose lengths stay within five words of each other, `noShort` a
     * paragraph of four or more sentences with no short beat in it.
     *
     * @return array{count: int, median: float, mean: float, shortest: int, longest: int, long: int, metronome: int, noShort: int}
     */
    private static function tempo(string $file): array
    {
        $lengths = [];
        $long = 0;
        $metronome = 0;
        $noShort = 0;

        foreach (self::paragraphs($file) as $paragraph) {
            $sentences = self::sentences($paragraph['lines']);
            $words = array_column($sentences, 'words');

            if ([] === $words) {
                continue;
            }

            $lengths = array_merge($lengths, $words);
            $long += count(array_filter($words, static fn (int $count): bool => $count > 40));

            $run = 1;
            $counted = false;

            for ($index = 1, $size = count($words); $index < $size; $index++) {
                if (abs($words[$index] - $words[$index - 1]) <= 5) {
                    $run++;
                } else {
                    $run = 1;
                    $counted = false;
                }

                if ($run >= 5 && !$counted) {
                    $metronome++;
                    $counted = true;
                }
            }

            if (count($words) >= 4 && min($words) > 10) {
                $noShort++;
            }
        }

        sort($lengths);
        $count = count($lengths);
        $median = 0.0;

        if ($count > 0) {
            $middle = intdiv($count, 2);
            $median = 0 === $count % 2
                ? ($lengths[$middle - 1] + $lengths[$middle]) / 2
                : (float) $lengths[$middle];
        }

        return [
            'count' => $count,
            'median' => $median,
            'mean' => [] === $lengths ? 0.0 : array_sum($lengths) / $count,
            'shortest' => [] === $lengths ? 0 : min($lengths),
            'longest' => [] === $lengths ? 0 : max($lengths),
            'long' => $long,
            'metronome' => $metronome,
            'noShort' => $noShort,
        ];
    }

    /**
     * Where each file stands today, measured and then frozen.
     *
     * This is a ratchet and not a target: the corpus in `nativa-minimal` scores
     * the same as its weakest articles, so a gate on the whole tempo would
     * enforce a rule the repository does not satisfy, and the doc says as much.
     * What a count here does is stop the next edit from adding a new one, the
     * same way the wrapped list only grows.
     *
     * @return array<string, array{long: int, metronome: int, noShort: int}>
     */
    private static function tempoBaseline(): array
    {
        return [
            'docs/core-philosophy.md' => ['long' => 3, 'metronome' => 0, 'noShort' => 0],
            'docs/prose-rhythm.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/ako-prispievat-cez-pull-request.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/boolean-nestaci-a-predsa-staci.md' => ['long' => 1, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/fatalna-chyba-a-proces-ktory-zomrel.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/herdr-workspaces.md' => ['long' => 1, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/hyprland-namiesto-xfce.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/mago-nad-modulovym-katalogom.md' => ['long' => 2, 'metronome' => 0, 'noShort' => 1],
            'storage/blog/mago-rust-v-php-strome.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/nativa-php-core.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/optimalizacia-font-setov.md' => ['long' => 0, 'metronome' => 1, 'noShort' => 1],
            'storage/blog/php2shell-tri-dni-tri-cve.md' => ['long' => 1, 'metronome' => 0, 'noShort' => 1],
            'storage/blog/sest-nastrojov-a-jedna-otazka.md' => ['long' => 3, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/siesta-funkcia-portu.md' => ['long' => 2, 'metronome' => 0, 'noShort' => 2],
            'storage/blog/tri-navrhy-na-prepojenie-nastrojov.md' => ['long' => 3, 'metronome' => 0, 'noShort' => 0],
            'storage/blog/tri-subory-v-src.md' => ['long' => 0, 'metronome' => 0, 'noShort' => 0],
        ];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    #[Test]
    public function noWrappedFileCarriesAnEmDash(): void
    {
        self::assertNotSame([], self::wrapped(), 'The wrapped list is empty, so this check reads nothing.');

        foreach (self::wrapped() as $file) {
            $content = (string) @file_get_contents(self::root() . '/' . $file);
            // Prose only: a sample of code or of a tool's output keeps whatever
            // the tool prints, and the dash there is data, not writing.
            $content = (string) preg_replace('/^```.*?^```$/msu', '', $content);

            self::assertStringNotContainsString(
                '—',
                $content,
                sprintf('%s still carries an em dash; the tree separates with a line break.', $file),
            );
        }
    }

    #[Test]
    public function everySentenceStartsItsOwnLine(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                foreach ($paragraph['lines'] as $line) {
                    $masked = (string) preg_replace('/`[^`]*`/u', '@', $line);

                    if (1 === preg_match('/[.!?][)\]"»“*`]*\s+\p{Lu}/u', $masked)) {
                        $problems[] = sprintf('%s: a new sentence shares the line: %s', $file, $line);
                    }
                }
            }
        }

        self::assertSame([], $problems, "Every sentence starts on its own line, with no separator:\n" . implode("\n", $problems));
    }

    #[Test]
    public function noLineEndsOnAFunctionWord(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                $lines = $paragraph['lines'];

                foreach ($lines as $index => $line) {
                    if ($index === count($lines) - 1) {
                        continue;
                    }

                    // A line that ends its sentence, or ends on the colon that
                    // introduces the next one, is a break of its own and may
                    // end on any word. The rest continue the sentence, and a
                    // function word there is a phrase cut in the wrong place.
                    $bare = rtrim($line, '*_`„“"\')]»');

                    if (1 === preg_match('/[.!?…:]$/u', $bare)) {
                        continue;
                    }

                    $words = preg_split('/\s+/u', trim($line)) ?: [];
                    $last = strtolower((string) end($words));
                    $last = trim($last, '.,;:!?„"()[]');

                    if (in_array($last, self::danglingWords(), true)) {
                        $problems[] = sprintf('%s: a line ends on `%s`: %s', $file, $last, $line);
                    }
                }
            }
        }

        self::assertSame([], $problems, "A phrase cut in the wrong place leaves a function word at the end of the line:\n" . implode("\n", $problems));
    }

    #[Test]
    public function noLineExceedsTheWidth(): void
    {
        $problems = [];

        foreach (self::wrapped() as $file) {
            foreach (self::paragraphs($file) as $paragraph) {
                foreach ($paragraph['lines'] as $line) {
                    if (mb_strlen($line, 'UTF-8') <= 100) {
                        continue;
                    }

                    $problems[] = sprintf('%s: %d characters: %s', $file, mb_strlen($line, 'UTF-8'), $line);
                }
            }
        }

        self::assertSame([], $problems, "A line may run long rather than cut a phrase, but not forever:\n" . implode("\n", $problems));
    }

    /**
     * The shape of the tempo, which is the half of it a machine can hold.
     *
     * docs/prose-rhythm.md gives four phase targets from `nativa-minimal`: INTRO
     * 5-15 words per sentence, HMLA 8-15, SLNKO 15-25, TÉMA 10-20. This tree has
     * no phase headings, so what is checked is their envelope, 5 to 25, plus one
     * short sentence per file: a median is a shape, and a shape outside the
     * envelope nobody wrote in is a paragraph that reads as one breath.
     */
    #[Test]
    public function tempoStaysInsideTheEnvelope(): void
    {
        $problems = [];
        $measured = [];

        foreach (self::wrapped() as $file) {
            $tempo = self::tempo($file);
            $measured[] = sprintf(
                '%s: %d sentences, median %.1f, mean %.1f, shortest %d, longest %d, over 40 %d, metronome %d, no short beat %d',
                $file,
                $tempo['count'],
                $tempo['median'],
                $tempo['mean'],
                $tempo['shortest'],
                $tempo['longest'],
                $tempo['long'],
                $tempo['metronome'],
                $tempo['noShort'],
            );

            // Four sentences are too few for a median to mean anything.
            if ($tempo['count'] < 4) {
                continue;
            }

            if ($tempo['median'] < 5.0 || $tempo['median'] > 25.0) {
                $problems[] = sprintf(
                    '%s: the middle sentence is %.1f words, outside the 5 to 25 the doc names',
                    $file,
                    $tempo['median'],
                );
            }

            if ($tempo['shortest'] > 10) {
                $problems[] = sprintf(
                    '%s: not one sentence under 10 words, so the longest have nothing to lean on',
                    $file,
                );
            }
        }

        self::assertSame([], $problems, "The tempo of a file is a shape, not a score:\n" . implode("\n", $problems) . "\nMeasured:\n" . implode("\n", $measured));
    }

    /**
     * What is already in the corpus may only fall.
     *
     * A hard gate on the three counts would fail on text this repository
     * publishes today, which is exactly the trap `nativa-minimal` walked out of
     * when it demoted its tempo tool to advice. Frozen counts stop an edit from
     * adding a metronome run without pretending the ones already there are
     * finished: a writer may lower a number by fixing a paragraph, and a
     * conversion may not raise one.
     */
    #[Test]
    public function tempoDoesNotRegress(): void
    {
        $baseline = self::tempoBaseline();
        $problems = [];

        foreach (self::wrapped() as $file) {
            if (!isset($baseline[$file])) {
                $problems[] = sprintf(
                    '%s: no baseline row, so nothing measures it. Measure it once and freeze the counts.',
                    $file,
                );

                continue;
            }

            $tempo = self::tempo($file);

            foreach ([
                'long' => 'a sentence over 40 words',
                'metronome' => 'a run of five sentences of the same length',
                'noShort' => 'a paragraph of four sentences with no short beat',
            ] as $key => $label) {
                if ($tempo[$key] <= $baseline[$file][$key]) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s: %d of %s, the baseline allows %d. Rewrite the run or lower the baseline never.',
                    $file,
                    $tempo[$key],
                    $label,
                    $baseline[$file][$key],
                );
            }
        }

        foreach (array_diff_key($baseline, array_flip(self::wrapped())) as $file => $row) {
            $problems[] = sprintf(
                '%s: frozen counts for a file that is no longer wrapped. The list only grows, so this row is stale.',
                $file,
            );
        }

        self::assertSame([], $problems, "Tempo may be fixed here one paragraph at a time, never added to:\n" . implode("\n", $problems));
    }

    /**
     * A colon ends the line it introduces.
     *
     * The colon is the one mark that says "the rest of this sentence is a list,
     * a value or an explanation", and it says that to a reader who can see the
     * line end. Kept inside a line, it asks the eye to find the split, and the
     * answer after it is what the reader came for.
     *
     * The counts are a ratchet like the tempo ones: the corpus is full of
     * colons mid-line, so this measures them and stops the number rising until
     * the conversion pass that lowers it reaches the file.
     *
     * @return array<string, int>
     */
    private static function colonBaseline(): array
    {
        return [
            'docs/core-philosophy.md' => 0,
            'docs/prose-rhythm.md' => 0,
            'storage/blog/ako-prispievat-cez-pull-request.md' => 0,
            'storage/blog/boolean-nestaci-a-predsa-staci.md' => 0,
            'storage/blog/fatalna-chyba-a-proces-ktory-zomrel.md' => 0,
            'storage/blog/herdr-workspaces.md' => 0,
            'storage/blog/hyprland-namiesto-xfce.md' => 0,
            'storage/blog/mago-nad-modulovym-katalogom.md' => 0,
            'storage/blog/mago-rust-v-php-strome.md' => 0,
            'storage/blog/nativa-php-core.md' => 0,
            'storage/blog/optimalizacia-font-setov.md' => 0,
            'storage/blog/php2shell-tri-dni-tri-cve.md' => 0,
            'storage/blog/sest-nastrojov-a-jedna-otazka.md' => 0,
            'storage/blog/siesta-funkcia-portu.md' => 0,
            'storage/blog/tri-navrhy-na-prepojenie-nastrojov.md' => 0,
            'storage/blog/tri-subory-v-src.md' => 0,
        ];
    }

    /**
     * Colons that keep the text they introduce on the same line.
     *
     * Inline code is masked first, so a `key: value` inside backticks is data
     * and not prose. A colon between two digits is a clock time (`23:31`), not
     * an introduction, so it is dropped before the line is read.
     */
    private static function colonsOnTheSameLine(string $file): int
    {
        $count = 0;

        foreach (self::paragraphs($file) as $paragraph) {
            foreach ($paragraph['lines'] as $line) {
                $masked = (string) preg_replace('/`[^`]*`/u', '@', $line);
                $masked = str_replace(['**', '*', '_'], '', $masked);
                $masked = (string) preg_replace('/(?<=\d):(?=\d)/u', '', $masked);

                if (1 === preg_match('/:\s*\S/u', $masked)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    #[Test]
    public function aColonEndsItsLine(): void
    {
        $baseline = self::colonBaseline();
        $problems = [];

        foreach (self::wrapped() as $file) {
            if (!isset($baseline[$file])) {
                $problems[] = sprintf('%s: no colon count, so nothing measures it.', $file);

                continue;
            }

            $found = self::colonsOnTheSameLine($file);

            if ($found > $baseline[$file]) {
                $problems[] = sprintf(
                    '%s: %d colons keep their text on the line, the baseline allows %d',
                    $file,
                    $found,
                    $baseline[$file],
                );
            }
        }

        foreach (array_diff_key($baseline, array_flip(self::wrapped())) as $file => $count) {
            $problems[] = sprintf('%s: a colon count for a file that is no longer wrapped.', $file);
        }

        self::assertSame([], $problems, "A colon ends its line and the next line says what it introduced:\n" . implode("\n", $problems));
    }
}
