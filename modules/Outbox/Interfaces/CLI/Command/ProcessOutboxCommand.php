<?php

declare(strict_types = 1);

namespace Modules\Outbox\Interfaces\CLI\Command;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Outbox\Application\Services\ArticlePublishHandler;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use RuntimeException;

/**
 * bin/cms outbox:process — drain the outbox queue and prune it.
 *
 * The kit ships no worker and no cron, so the in-request drain in
 * `OutboxDrain` is the normal delivery path. This command is the operator
 * side of the same queue: it makes the pending backlog observable, lets a
 * deployment without traffic still deliver events, and prunes rows that are
 * already `processed` so outbox.db does not grow without bound.
 *
 * Exits non-zero when messages failed, so a cron entry or a CI probe can
 * notice a poison message instead of silently rotating it.
 *
 * WHY THIS IS NOT A SYMFONY COMMAND (the shape changed on purpose):
 *
 *   It used to extend `Symfony\Component\Console\Command\Command` and take
 *   `InputInterface` / `OutputInterface`. `symfony/console` is `require-dev`
 *   only — the kernel runtime has no Composer packages at all — so the class
 *   could not even be *loaded* in the trees that matter most: the
 *   `admin-smoke` and `boot` CI jobs deliberately run with no `vendor/`, and
 *   so does a production deployment. A command that fatals in production
 *   because of a dev dependency is not a command.
 *
 *   So the parsing is here, `$argv` in and an exit code out, with
 *   `CliOutput` — the same seam `DatabaseMigrator` already uses — as the
 *   output contract. `bin/cms` owns the printing; this class owns the
 *   behaviour, which is what lets a test drive it in-process.
 *
 * Options keep the symfony spelling AND both value spellings, because the
 * original accepted both and a cron line already written out there must keep
 * working:
 *
 *     outbox:process [--limit=N | --limit N]
 *                    [--prune-interval='7 days' | --prune-interval '7 days']
 *                    [--no-prune]
 */
final class ProcessOutboxCommand
{
    /** Options that take no value; everything else does. */
    private const array FLAGS = ['no-prune'];

    private const int DEFAULT_LIMIT = 100;

    private const string DEFAULT_PRUNE_INTERVAL = '7 days';

    /**
     * @param list<string> $argv the command's own arguments, index 0 being
     *                           the command name as `$_SERVER['argv']` has it
     *
     * @return int 0 on a clean drain, 1 when any message failed or an option
     *              was not understood
     */
    public static function run(array $argv, CliOutput $output): int
    {
        try {
            $options = self::parse(array_slice($argv, 1));
        } catch (RuntimeException $e) {
            $output->writeln('outbox:process: ' . $e->getMessage());

            return 1;
        }

        $limit = max(1, (int) ($options['limit'] ?? self::DEFAULT_LIMIT));
        // `--no-prune` DISABLES, so the flag has to be read as an absence. The
        // first port had this as `|| isset(...)` — i.e. the flag that says do
        // not prune was the flag that pruned — and the module test caught it.
        $prune = !isset($options['no-prune']);
        $pruneInterval = is_string($options['prune-interval'] ?? null)
            ? $options['prune-interval']
            : self::DEFAULT_PRUNE_INTERVAL;

        $repository = OutboxServices::repository();

        $before = $repository->countByStatus();
        $output->writeln(sprintf(
            'Outbox before: pending=%d processing=%d processed=%d failed=%d',
            $before['pending'],
            $before['processing'],
            $before['processed'],
            $before['failed'],
        ));

        // The article-publish cache flush is registered HERE rather than only in
        // the request bootstrap: a CLI process that drains without it parks
        // every `article.*` message as unroutable. `src/composition.php` owns
        // the pairing the CLI cannot see (Boot 31); this one the Outbox module
        // CAN see, so it registers it itself — the same split the module's own
        // service layer has always had.
        ArticlePublishHandler::register(OutboxServices::processor());
        self::reportHandlers($output);

        $result = OutboxDrain::drain($limit);
        $output->writeln(sprintf(
            'Delivered: processed=%d failed=%d (limit %d)',
            $result['processed'],
            $result['failed'],
            $limit,
        ));

        if ($prune) {
            $pruned = $repository->cleanProcessed($pruneInterval);
            $output->writeln(sprintf('Pruned: %d processed message(s)', $pruned));
        }

        $after = $repository->countByStatus();
        $output->writeln(sprintf(
            'Outbox after: pending=%d processing=%d processed=%d failed=%d',
            $after['pending'],
            $after['processing'],
            $after['processed'],
            $after['failed'],
        ));

        return 0 === $result['failed'] ? 0 : 1;
    }

    /**
     * Name the handler types this pass could deliver — a message whose type
     * is missing from the list is what will land in `failed` below.
     */
    private static function reportHandlers(CliOutput $output): void
    {
        $types = OutboxServices::processor()->registeredTypes();

        $output->writeln('Handlers: ' . ([] === $types ? '(none registered)' : implode(', ', $types)));
    }

    /**
     * `--name=value` or `--name value` for a value option, `--name` for a flag.
     *
     * An unknown option is an error, not a shrug — the same thing symfony did,
     * and the thing that matters here: a typo'd `--lmit=50` that silently used
     * the default limit would drain a different amount than the operator
     * asked for and print a clean-looking summary either way.
     *
     * @param list<string> $arguments
     *
     * @return array<string, string|bool>
     *
     * @throws RuntimeException on an unknown option or a value option with no value
     */
    private static function parse(array $arguments): array
    {
        $options = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; ++$i) {
            $argument = $arguments[$i];

            if (!str_starts_with($argument, '--')) {
                throw new RuntimeException(sprintf('unexpected argument "%s".', $argument));
            }

            $name = substr($argument, 2);

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } else {
                $value = true;
            }

            if (!in_array($name, self::FLAGS, true) && !in_array($name, ['limit', 'prune-interval'], true)) {
                throw new RuntimeException(sprintf('unknown option "--%s".', $name));
            }

            if (true === $value) {
                if (in_array($name, self::FLAGS, true)) {
                    $options[$name] = true;

                    continue;
                }

                // `--limit 50`: the value is the next token.
                if (!isset($arguments[$i + 1])) {
                    throw new RuntimeException(sprintf('option "--%s" needs a value.', $name));
                }

                $value = $arguments[++$i];
            }

            $options[$name] = $value;
        }

        return $options;
    }
}