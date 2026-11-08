<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\CLI\Command;

use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\CoverGenerator;
use Modules\Blog\Application\Services\CoverPathResolver;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\HTTP\ContentServices;
use RuntimeException;

/**
 * bin/cms generate-images — generate preview images for blog articles.
 *
 * Thin CLI shell over Modules\Blog\Application\Services\CoverGenerator (the
 * command owns only flag parsing, file discovery and raster conversion; all
 * rendering lives in the Blog module). Covers are byte-deterministic: the
 * same article always renders the same SVG. Without --template/--color the
 * identity is auto-resolved (series front matter → slug hash → table).
 *
 * WHY THIS IS NOT A SYMFONY COMMAND (the shape changed on purpose):
 *
 *   It used to extend `Symfony\Component\Console\Command\Command`, which
 *   `symfony/console` being `require-dev` only makes unloadable — the
 *   `admin-smoke` and `boot` jobs deliberately run with no `vendor/`, and so
 *   does a production deployment. So `$argv` in, an exit code out, and
 *   `CliOutput` as the contract — the same seam `DatabaseMigrator` uses.
 *   `bin/cms` owns the printing; this class owns the behaviour, so a test can
 *   drive it in-process against a temp storage tree.
 *
 *   That also drops the console markup the old strings carried (`<info>`,
 *   `<error>`): `CliOutput` has no markup concept, and printing the tags
 *   literally into a crontab mail is worse than no colour. Lines are plain.
 *
 * Options keep their symfony spelling and their short forms:
 *
 *     generate-images [-s|--slug SLUG] [-o|--output DIR]
 *                     [-t|--template NAME] [-c|--color NAME]
 *                     [--code-lines N] [-f|--format FMT] [--quality N]
 */
final class GenerateImagesCommand
{
    /** Every option takes a value; there are no bare flags. */
    private const array VALUE_OPTIONS = [
        'slug',
        'output',
        'template',
        'color',
        'code-lines',
        'format',
        'quality',
    ];

    /** Short form → long name, exactly as the symfony version declared them. */
    private const array SHORT_OPTIONS = [
        's' => 'slug',
        'o' => 'output',
        't' => 'template',
        'c' => 'color',
        'f' => 'format',
    ];

    /**
     * Relative to the storage root — `AppPaths::storage()` joins the base
     * storage/ dir, so the physical target is `storage/blog-images`.
     *
     * ONE default, unlike the symfony version, which declared 'blog-images'
     * on the option but fell back to 'storage/blog-images' in the handler —
     * so `--output=` (empty) silently produced `storage/storage/blog-images`.
     */
    private const string DEFAULT_OUTPUT = 'blog-images';

    private const string DEFAULT_FORMAT = 'svg';

    private readonly CoverGenerator $covers;

    private readonly CoverPathResolver $paths;

    public function __construct(?CoverGenerator $covers = null, ?CoverPathResolver $paths = null)
    {
        $this->covers = $covers ?? new CoverGenerator();
        $this->paths = $paths ?? new CoverPathResolver();
    }

    /**
     * @param list<string> $argv the command's own arguments, index 0 being
     *                           the command name as `$_SERVER['argv']` has it
     *
     * @return int 0 when every article rendered, 1 on a bad option or a
     *              conversion failure
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $options = $this->parse(array_slice($argv, 1));
        } catch (RuntimeException $e) {
            $output->writeln('generate-images: ' . $e->getMessage());

            return 1;
        }

        // An empty flag means "no override" — pass null so the auto identity
        // (series front matter → slug hash) can take over.
        $template = $this->text($options, 'template') ?: null;
        $color = $this->text($options, 'color') ?: null;
        $format = $this->text($options, 'format') ?: self::DEFAULT_FORMAT;
        $quality = $this->number($options, 'quality', 85);
        $codeLines = $this->number($options, 'code-lines', CoverGenerator::DEFAULT_CODE_LINES);

        if (null === $this->validate($template, $color, $format, $quality, $codeLines, $output)) {
            return 1;
        }

        $outputDir = $this->text($options, 'output') ?: self::DEFAULT_OUTPUT;
        $outputPath = $this->prepareOutputPath($outputDir);
        $rendered = $this->processArticles($options, $output, $template, $color, $format, $quality, $codeLines, $outputPath);

        $output->writeln('');
        $output->writeln(sprintf(
            'Generated %d image(s), %d failed in %s/',
            $rendered['generated'],
            $rendered['failed'],
            $outputDir,
        ));

        // A slug that matched nothing is a FAILURE, not an empty success. The
        // whole point of `--slug` is to render exactly one article, so a typo
        // there rendering nothing while printing a healthy summary is the one
        // outcome an operator cannot afford in a bulk command.
        return 0 === $rendered['failed'] && $rendered['generated'] > 0 ? 0 : 1;
    }

    /**
     * @return ?bool null when an option is not acceptable, true when it is
     */
    private function validate(
        ?string $template,
        ?string $color,
        string $format,
        int $quality,
        int $codeLines,
        CliOutput $output,
    ): ?bool {
        if (null !== $template && !isset(CoverGenerator::TEMPLATES[$template])) {
            $output->writeln('Invalid template: ' . $template);
            $output->writeln('Available templates: ' . implode(', ', array_keys(CoverGenerator::TEMPLATES)));

            return null;
        }

        if (null !== $color && !isset(CoverGenerator::PALETTES[$color])) {
            $output->writeln('Invalid color: ' . $color);
            $output->writeln('Available colors: ' . implode(', ', array_keys(CoverGenerator::PALETTES)));

            return null;
        }

        $validFormats = ['svg', 'webp', 'png', 'both'];
        if (!in_array($format, $validFormats, true)) {
            $output->writeln('Invalid format: ' . $format);
            $output->writeln('Available formats: ' . implode(', ', $validFormats));

            return null;
        }

        if ($quality < 1 || $quality > 100) {
            $output->writeln('Quality must be between 1 and 100');

            return null;
        }

        if ($codeLines < 0 || $codeLines > 20) {
            $output->writeln('Code lines must be between 0 and 20');

            return null;
        }

        // Raster export needs a converter — point at the package when missing.
        if (in_array($format, ['webp', 'png', 'both'], true) && !$this->hasConversionTool()) {
            $output->writeln('No SVG rasteriser found (imagick or rsvg-convert).');
            $output->writeln('Run: sudo apt-get install librsvg2-bin');

            return null;
        }

        return true;
    }

    private function prepareOutputPath(string $outputDir): string
    {
        $outputPath = AppPaths::instance()->storage(ltrim($outputDir, '/'));

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0o775, true);
        }

        return $outputPath;
    }

    /**
     * Render every article (or the one `--slug` named) and report per file.
     *
     * @param array<string, string|bool> $options
     *
     * @return array{generated: int, failed: int} successes and failures counted
     *                                           SEPARATELY — a single counter
     *                                           cannot tell "rendered 3, one
     *                                           failed" from "rendered 3, all
     *                                           fine", and the exit code rests
     *                                           on the difference
     */
    private function processArticles(
        array $options,
        CliOutput $output,
        ?string $template,
        ?string $color,
        string $format,
        int $quality,
        int $codeLines,
        string $outputPath,
    ): array {
        $slug = $this->text($options, 'slug');
        // Articles live in one directory per locale: the default locale
        // (settings.locale) in the family root, every other locale in
        // blog/{lang}. The mapper owns that mapping — no glob guessing.
        $files = $this->articleFiles();
        sort($files);
        $generated = 0;
        $failed = 0;

        foreach ($files as $file) {
            $results = $this->generateForArticle((string) $file, $slug, $template, $color, $format, $quality, $codeLines, $outputPath);

            foreach ($results as $result) {
                $output->writeln($result);

                if (str_starts_with($result, 'Failed')) {
                    ++$failed;
                } else {
                    ++$generated;
                }
            }
        }

        return ['generated' => $generated, 'failed' => $failed];
    }

    /**
     * Generate one article's cover (all requested formats).
     *
     * @return list<string> output lines
     */
    private function generateForArticle(string $file, string $slugFilter, ?string $template, ?string $color, string $format, int $quality, int $codeLines, string $outputPath): array
    {
        $fileSlug = basename($file, '.md');

        if ('' !== $slugFilter && $fileSlug !== $slugFilter) {
            return [];
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $fileSlug)) {
            return [];
        }

        $source = file_get_contents($file);
        if (!is_string($source)) {
            return [];
        }

        $fm = (new MarkdownRenderer())->extractFrontMatter($source);
        $locale = $this->paths->localeFor($file);

        $svg = $this->covers->generate($fileSlug, $fm['meta'], $fm['body'], $locale, $template, $color, $codeLines);

        return $this->writeOutputs($fileSlug, $svg, $format, $quality, $outputPath, $locale);
    }

    /**
     * Write the SVG and any requested raster variants.
     *
     * @return list<string>
     */
    private function writeOutputs(string $fileSlug, string $svg, string $format, int $quality, string $outputPath, string $locale = 'sk'): array
    {
        $results = [];

        if (in_array($format, ['svg', 'both'], true)) {
            $path = $this->coverSvgPath($fileSlug, $locale);
            $results[] = $this->writeFile($path, $svg, $this->coverSvgLabel($path));
        }

        if (in_array($format, ['webp', 'both'], true)) {
            $webpFile = $outputPath . DIRECTORY_SEPARATOR . $fileSlug . '.webp';
            $results[] = $this->convertToWebp($svg, $webpFile, $quality)
                ? 'Generated: ' . $fileSlug . '.webp'
                : 'Failed to generate: ' . $fileSlug . '.webp';
        }

        if ('png' === $format) {
            $pngFile = $outputPath . DIRECTORY_SEPARATOR . $fileSlug . '.png';
            $results[] = $this->convertToPng($svg, $pngFile)
                ? 'Generated: ' . $fileSlug . '.png'
                : 'Failed to generate: ' . $fileSlug . '.png';
        }

        return $results;
    }

    /**
     * Write the SVG to its collision-proof path and make sure the directory
     * exists.
     *
     * The rule itself — flat for a unique slug, `blog-images/{locale}/` for
     * a slug two locales share — belongs to CoverPathResolver, where a test
     * can reach it. This method only creates the directory.
     */
    private function coverSvgPath(string $fileSlug, string $locale): string
    {
        $path = $this->paths->pathFor($fileSlug, $locale);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return $path;
    }

    /**
     * Every article markdown file across all locales.
     *
     * @return list<string>
     */
    private function articleFiles(): array
    {
        $files = [];

        foreach (ContentServices::mapper()->locales() as $locale) {
            $dir = AppPaths::instance()->storage(BlogArticlesService::directoryFor($locale));
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $file) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    private function coverSvgLabel(string $path): string
    {
        $root = AppPaths::instance()->storage('blog-images') . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    private function writeFile(string $path, string $content, string $label): string
    {
        return file_put_contents($path, $content) !== false
            ? 'Generated: ' . $label
            : 'Failed to generate: ' . $label;
    }

    /**
     * Unique temp path under storage/cache for raster conversion intermediates.
     *
     * sys_get_temp_dir() is deliberately avoided: shared hostings may
     * forbid it via open_basedir, while the app's storage tree is always
     * inside the allowlist.
     */
    private function tempPath(string $extension): string
    {
        $dir = AppPaths::instance()->storage('cache' . DIRECTORY_SEPARATOR . 'image-conversion');

        try {
            AppPaths::instance()->ensureDir($dir);
        } catch (\RuntimeException) {
            // Fall through — file_put_contents below will fail loudly.
            // @mago-expect lint:no-empty-catch-clause
        }

        return $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    private function hasConversionTool(): bool
    {
        if (extension_loaded('imagick')) {
            return true;
        }

        $output = [];
        exec('which rsvg-convert 2>/dev/null', $output, $exitCode);

        return 0 === $exitCode;
    }

    private function convertToWebp(string $svg, string $outputFile, int $quality): bool
    {
        // Use Imagick for SVG → WebP conversion
        if (extension_loaded('imagick')) {
            return $this->convertViaImagick($svg, $outputFile, 'webp', $quality);
        }

        // Fallback: rsvg-convert to PNG, then convert to WebP
        $tempPng = $this->tempPath('png');
        $this->convertToPng($svg, $tempPng);

        $cmd = sprintf(
            'convert %s -quality %d %s 2>&1',
            escapeshellarg($tempPng),
            $quality,
            escapeshellarg($outputFile),
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempPng);

        return 0 === $exitCode && is_file($outputFile);
    }

    private function convertViaImagick(string $svg, string $outputFile, string $format, int $quality): bool
    {
        $imagick = new \Imagick();
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat($format);
        $imagick->setImageCompressionQuality($quality);
        $imagick->resizeImage(CoverGenerator::WIDTH, CoverGenerator::HEIGHT, \Imagick::FILTER_LANCZOS, 1);

        $result = $imagick->writeImage($outputFile);
        $imagick->destroy();

        return $result;
    }

    private function convertToPng(string $svg, string $outputFile): bool
    {
        // Use Imagick if available
        if (extension_loaded('imagick')) {
            return $this->convertViaImagick($svg, $outputFile, 'png', 100);
        }

        // Fallback: rsvg-convert
        $tempSvg = $this->tempPath('svg');
        file_put_contents($tempSvg, $svg);

        $cmd = sprintf(
            'rsvg-convert -w %d -h %d --format png %s -o %s 2>&1',
            CoverGenerator::WIDTH,
            CoverGenerator::HEIGHT,
            escapeshellarg($tempSvg),
            escapeshellarg($outputFile),
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempSvg);

        return 0 === $exitCode && is_file($outputFile);
    }

    /**
     * An option value, or '' when it was not given.
     *
     * @param array<string, string|bool> $options
     */
    private function text(array $options, string $name): string
    {
        $value = $options[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function number(array $options, string $name, int $default): int
    {
        $value = $this->text($options, $name);

        return '' === $value ? $default : (int) $value;
    }

    /**
     * `--name=value` or `--name value`, plus the single-dash short forms.
     *
     * An unknown option is an error rather than a shrug: `generate-imagez`
     * would otherwise render every article with the defaults and print a
     * healthy-looking summary, which is the worst possible outcome for a
     * command an operator runs in bulk.
     *
     * @param list<string> $arguments
     *
     * @return array<string, string|bool>
     *
     * @throws RuntimeException on an unknown option or a missing value
     */
    private function parse(array $arguments): array
    {
        $options = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; ++$i) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--')) {
                $name = substr($argument, 2);
            } elseif (str_starts_with($argument, '-')) {
                $short = substr($argument, 1);

                if (!isset(self::SHORT_OPTIONS[$short])) {
                    throw new RuntimeException(sprintf('unknown option "-%s".', $short));
                }

                $name = self::SHORT_OPTIONS[$short];
            } else {
                throw new RuntimeException(sprintf('unexpected argument "%s".', $argument));
            }

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } elseif (isset($arguments[$i + 1]) && !str_starts_with($arguments[$i + 1], '-')) {
                $value = $arguments[++$i];
            } else {
                $value = true;
            }

            if (!in_array($name, self::VALUE_OPTIONS, true)) {
                throw new RuntimeException(sprintf('unknown option "--%s".', $name));
            }

            // A value option given with no value falls back to the default
            // rather than erroring — symfony treated `--format` with nothing
            // after it as the flag-less form, and the previous behaviour was
            // "use the default", so the port keeps that.
            $options[$name] = true === $value ? '' : $value;
        }

        return $options;
    }
}