<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Blog\Interfaces\CLI\Command\GenerateImagesCommand;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Queue\Application\Services\HandlerRegistry;
use RuntimeException;

/**
 * CoverRenderHandler — the consumer half of `CoverJobs`, for the Queue module.
 *
 * It wraps the OPERATOR'S COMMAND rather than reaching into `CoverGenerator`
 * itself. That is deliberate: `bin/cms generate-images` and the queued cover
 * are the same work, and if the handler had its own copy of the rendering —
 * the front matter extraction, the locale collision rule, the raster fallbacks
 * — the two would drift, and the copy nobody runs by hand is the copy that
 * quietly stops matching. One implementation, two doors.
 *
 * So this class holds no rendering logic. It builds an argv, runs the command,
 * and turns its exit code into the queue's vocabulary: a non-zero exit throws,
 * which releases the job for another attempt (with the backoff), and a job that
 * exhausts its attempts lands in `failed_jobs` with the message the command
 * printed. That is the same contract `outbox:process` states, on the same
 * principle: a worker should not report success for work that did not happen.
 *
 * Registered from `src/composition.php`, because that is where the handler
 * pairings live now (Boot 31). Registering from a Blog service locator instead
 * would bind it to one process — the web one — and leave every worker drain
 * with an empty registry, parking each cover as unroutable while the article
 * page still served a stale image.
 */
final class CoverRenderHandler
{
    /**
     * Bind the cover job on the shared registry.
     *
     * The registry is process-wide and cached by `QueueServices::handlers()`,
     * and that is the instance the worker reads. Registering on a fresh
     * `HandlerRegistry` is the Boot 16 failure in miniature: everything loads,
     * nothing is found.
     */
    public static function register(HandlerRegistry $registry): void
    {
        $registry->register(CoverJobs::HANDLER, static function (array $args): void {
            self::handle($args);
        });
    }

    /**
     * @param array<string, mixed> $args the job payload — `slug` and `locale`
     *
     * @throws RuntimeException when the article cannot be rendered
     */
    public static function handle(array $args): void
    {
        $slug = isset($args['slug']) && is_string($args['slug']) ? $args['slug'] : '';
        $locale = isset($args['locale']) && is_string($args['locale']) ? $args['locale'] : '';

        if ('' === $slug) {
            // Nothing to render and nothing to retry — a payload with no slug is
            // not a transient failure, so parking it after three attempts would
            // only teach an operator to ignore `failed_jobs`.
            throw new RuntimeException('A blog.cover job must carry a slug.');
        }

        // `--slug` only, and the payload's `locale` is NOT passed as a filter.
        // A slug two locales share has two covers — `CoverPathResolver` puts
        // them in separate directories precisely so they do not collide — and
        // `--slug` alone renders every copy, which is what an operator running
        // the command by hand would get. A locale filter would make the queued
        // job render strictly less than the command does, and the day someone
        // adds that option is the day the two stop agreeing.
        $argv = ['generate-images', '--slug', $slug];

        $output = new class implements CliOutput {
            /** @var list<string> */
            public array $lines = [];

            public function writeln(string $message): void
            {
                $this->lines[] = $message;
            }
        };

        $exit = (new GenerateImagesCommand())->run($argv, $output);

        if (0 !== $exit) {
            throw new RuntimeException(sprintf(
                'Cover render for "%s" (%s) failed: %s',
                $slug,
                '' === $locale ? 'any locale' : $locale,
                trim(implode(' | ', $output->lines)) ?: 'generate-images exited 1.',
            ));
        }
    }
}