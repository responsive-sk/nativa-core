<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * NoCssJsInPhpTest — a stylesheet or a script is a file, not a PHP string.
 *
 * Why this gate exists: the tree carried 13 files whose CSS or JS body was
 * built in PHP, either as a heredoc inside a `<style>`/`<script>` tag or as a
 * concatenated stylesheet with no file behind it. Nothing measured that, so
 * the count grew quietly: every new panel surface or auth page was another
 * chance to paste a rule into a method. The audit that found it also found the
 * consequence, a 13 kB stylesheet inlined from PHP that no editor, linter or
 * cache could see.
 *
 * ## Two shapes, one question
 *
 * A file fails when it carries CSS or JS code in either shape:
 *
 *  1. a `<style>` or `<script>` body whose text survives the removal of
 *     `<?php ... ?>` output. In PHTML the PHP segments are output, so a body
 *     that is nothing but `<?php echo $x; ?>` is not code and is not flagged;
 *     a body that is `var recent = <?php echo $json; ?>;` still is.
 *  2. a PHP string literal that is a stylesheet: two or more custom-property
 *     declarations. `AuthTemplate::pageCss()` was exactly this, a complete
 *     rule set concatenated from string fragments with no tag and no file, and
 *     a tag-only scan could not see it.
 *
 * ## What is deliberately not code
 *
 * `<script type="application/ld+json">` is data, not JavaScript, and it stays
 * in PHP on purpose. A `style="..."` attribute is a value on one element, not
 * a stylesheet; the change that added this gate left it out of scope. Comments
 * are stripped before scanning, so a docblock that shows a `<script>` example
 * does not fail: the tree documents the CSP contract in prose.
 *
 * ## The ratchet
 *
 * The baseline is the measured count, not a wish. Every migration phase lowers it
 * by the files it converted, and the change ends at zero. It is asserted as an
 * exact count, so a file that starts carrying code fails immediately AND a
 * migration that is not reflected in the constant fails too: neither direction
 * can drift.
 *
 * @internal
 *
 * @coversNothing
 */
final class NoCssJsInPhpTest extends TestCase
{
    /**
     * Files that still carry a CSS or JS body, measured 2026-11-23 with the
     * tokenizer scan in the change's proposal. Only goes down.
     */
    private static int $baseline = 0;

    /** A floor so the scan cannot pass by reading nothing (13 of ~500). */
    private static int $scannedFloor = 250;

    /** @var list<string>|null */
    private static ?array $scanned = null;

    #[Test]
    public function noSourceFileCarriesAnInlineStylesheetOrScript(): void
    {
        $offenders = [];

        foreach (self::scannedFiles() as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $bodies = self::bodiesIn($source, str_ends_with($file, '.phtml'));
            if ([] !== $bodies) {
                $offenders[$file] = $bodies;
            }
        }

        self::assertGreaterThanOrEqual(
            self::$scannedFloor,
            \count(self::scannedFiles()),
            'The scan read fewer files than the floor, so a green result would prove nothing.',
        );

        $message = "These files carry CSS or JS code instead of reading it from a file:\n";

        foreach ($offenders as $file => $bodies) {
            $message .= sprintf("  %s (%s)\n", $file, implode(', ', $bodies));
        }

        $message .= "\nMove each body to its asset file and read it through "
            . 'View\\InlineCss::file() or View\\InlineJs::file(), keeping the tag in PHP. '
            . 'When a file is converted, lower NoCssJsInPhpTest::BASELINE by one in the same change.';

        self::assertSame(
            self::$baseline,
            \count($offenders),
            $message,
        );
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function detectorFixtures(): iterable
    {
        yield 'a planted style body' => [
            '<style nonce="x">.a{color:red}</style>',
            false,
            1,
        ];

        yield 'a planted script body' => [
            '<script nonce="x">document.addEventListener("load",function(){});</script>',
            false,
            1,
        ];

        yield 'JSON-LD is data, not code' => [
            '<script type="application/ld+json">{"@type":"WebSite"}</script>',
            false,
            0,
        ];

        yield 'a PHTML body holding only PHP output' => [
            '<script nonce="x"><?php echo $inlineJs; ?></script>',
            true,
            0,
        ];

        yield 'a PHTML body with code around PHP output' => [
            '<script nonce="x">var recent = <?php echo $json; ?>;</script>',
            true,
            1,
        ];

        yield 'a stylesheet assembled from PHP strings' => [
            "\$css = ':root{--a-bg:#fff;--a-ink:#000}';",
            false,
            1,
        ];

        yield 'a docblock example is not markup' => [
            "/**\n * Use <script>alert(1)</script> nowhere.\n */\n\$x = 1;",
            false,
            0,
        ];
    }

    #[Test]
    #[DataProvider('detectorFixtures')]
    public function theDetectorSeparatesCodeFromData(string $source, bool $isPhtml, int $expected): void
    {
        $method = new ReflectionMethod(self::class, 'bodiesIn');

        /** @var list<string> $bodies */
        $bodies = $method->invoke(null, $source, $isPhtml);

        self::assertCount($expected, $bodies, $source);
    }

    /**
     * @return list<string>
     */
    private static function scannedFiles(): array
    {
        if (null !== self::$scanned) {
            return self::$scanned;
        }

        $root = \dirname(__DIR__, 2);
        $files = [];

        foreach (['src', 'modules'] as $tree) {
            $directory = $root . '/' . $tree;
            if (!is_dir($directory)) {
                continue;
            }

            /** @var \SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            ) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $path = str_replace('\\', '/', $entry->getPathname());
                $relative = ltrim(substr($path, \strlen($root)), '/');

                if (!str_ends_with($path, '.php') && !str_ends_with($path, '.phtml')) {
                    continue;
                }

                foreach (['/tests/', '/assets/', '/vendor/', '/storage/', 'src/Modules/'] as $skip) {
                    if (str_contains('/' . $relative, $skip)) {
                        continue 2;
                    }
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return self::$scanned = $files;
    }

    /**
     * The code bodies a source carries: `style`, `script` and `stylesheet`.
     *
     * @return list<string>
     */
    private static function bodiesIn(string $source, bool $isPhtml): array
    {
        $text = $isPhtml ? self::inlineHtml($source) : self::phpStrings($source);
        $bodies = [];

        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $text, $matches)) {
            foreach ($matches[1] as $body) {
                if (self::carriesCss($body)) {
                    $bodies[] = 'style';
                }
            }
        }

        if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $tag) {
                $attributes = strtolower($tag[1]);

                if (str_contains($attributes, 'ld+json') || preg_match('/\bsrc\s*=/i', $attributes)) {
                    continue;
                }

                if (self::carriesJs($tag[2])) {
                    $bodies[] = 'script';
                }
            }
        }

        if (!$isPhtml && self::carriesStylesheetLiteral($text)) {
            $bodies[] = 'stylesheet';
        }

        return array_values(array_unique($bodies));
    }

    /** PHTML without its PHP segments: what the template actually emits as text. */
    private static function inlineHtml(string $source): string
    {
        return (string) preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $source);
    }

    /**
     * The text of a PHP file's string literals, comments excluded.
     *
     * Tokenising rather than regexing is what keeps an example in a docblock
     * out of the scan: a comment is a token, not a literal.
     */
    private static function phpStrings(string $source): string
    {
        $parts = [];

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if (\T_INLINE_HTML === $id || \T_ENCAPSED_AND_WHITESPACE === $id) {
                $parts[] = $text;
                continue;
            }

            if (\T_CONSTANT_ENCAPSED_STRING === $id) {
                $quote = $text[0];
                $inner = substr($text, 1, -1);
                $parts[] = '\\' === $quote ? $inner : stripcslashes($inner);
            }
        }

        // A newline between literals keeps two unrelated fragments from forming
        // a tag that neither of them contained on its own.
        return implode("\n", $parts);
    }

    private static function carriesCss(string $body): bool
    {
        return 1 === preg_match('/\{[^{}]*:[^{}]*\}/', $body);
    }

    private static function carriesJs(string $body): bool
    {
        return 1 === preg_match('/\b(?:function|var|let|const)\b|=>|\bdocument\.|\bwindow\.|\blocalStorage\b|\bimport\s*\(/', $body);
    }

    /**
     * A stylesheet literal: a rule block carrying two or more custom
     * properties. The braces matter: a `style="--slide-bg:..."` attribute on
     * one element is a value, not a stylesheet, and the themes emit several.
     */
    private static function carriesStylesheetLiteral(string $text): bool
    {
        if (!str_contains($text, '{') || !str_contains($text, '}')) {
            return false;
        }

        return preg_match_all('/--[a-z][a-z0-9-]*\s*:/i', $text) >= 2;
    }
}
