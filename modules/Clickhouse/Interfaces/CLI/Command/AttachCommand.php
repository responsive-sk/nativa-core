<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Interfaces\CLI\Command;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Application\Services\SqliteEngineBridge;
use Modules\Clickhouse\Application\Services\SqliteJournal;
use Modules\Clickhouse\Application\Services\TypedTablePlanner;
use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Interfaces\CLI\CommandOptions;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Interfaces\CLI\CliOutput;
use RuntimeException;

/**
 * bin/cms clickhouse:attach — every kernel SQLite file, as a ClickHouse database.
 *
 * Flags, and what each one actually changes:
 *
 *     --db=<name>       one database, by file name (`blog`) or full name (`nativa_blog`)
 *     --prefix=<p>      override CLICKHOUSE_DATABASE_PREFIX for this run
 *     --dry-run         print the statements; send nothing, not even a ping
 *     --force           DROP then CREATE — the only way to re-point an engine;
 *                       detaches the ClickHouse view, leaves the SQLite file alone
 *     --checkpoint      PRAGMA wal_checkpoint(TRUNCATE) before attaching
 *     --typed           write explicit CREATE TABLE definitions for tables whose
 *                       declarations name a type inference cannot express
 *
 * `--dry-run` deliberately does not resolve the client: printing the DDL is
 * useful on a machine with no ClickHouse yet, and a dry run that needed a
 * server would be a rehearsal of the wrong thing.
 *
 * The command prints, never throws: a refusal is one `clickhouse:attach: …`
 * line and exit 1, the `GenerateImagesCommand` contract. It extends nothing,
 * so it loads in a tree with no `vendor/`.
 */
final class AttachCommand
{
    public function __construct(
        private readonly SqliteEngineBridge $bridge = new SqliteEngineBridge(),
        private readonly ?ClickHouseClient $client = null,
    ) {
    }

    /**
     * @param list<string> $argv the command's argv, index 0 being `clickhouse:attach`
     *
     * @return int 0 on success, 1 on a refusal
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $parsed = CommandOptions::parse(
                array_slice($argv, 1),
                ['db', 'prefix'],
                ['dry-run', 'force', 'typed', 'checkpoint'],
            );
        } catch (RuntimeException $exception) {
            $output->writeln('clickhouse:attach: ' . $exception->getMessage());

            return 1;
        }

        if ([] !== $parsed['positional']) {
            $output->writeln('clickhouse:attach: unexpected argument "' . $parsed['positional'][0] . '".');

            return 1;
        }

        $options = $parsed['options'];
        $typed = isset($options['typed']);
        $dryRun = isset($options['dry-run']);
        $force = isset($options['force']);
        $checkpoint = isset($options['checkpoint']);
        $prefix = CommandOptions::value($options, 'prefix') ?? ClickhouseServices::databasePrefix();

        try {
            $attachments = $this->bridge->plan($prefix, CommandOptions::value($options, 'db'));
        } catch (RuntimeException $exception) {
            $output->writeln('clickhouse:attach: ' . $exception->getMessage());

            return 1;
        }

        try {
            $warnings = $this->warnings($attachments, $checkpoint, $output);
            $typedPlan = $this->typedPlan($attachments, $typed);
            $warnings = array_merge($warnings, $typedPlan['warnings']);
            $statements = array_merge($this->statements($attachments, $force), $typedPlan['statements']);

            foreach ($statements as $statement) {
                $output->writeln($statement);
            }

            if ($dryRun) {
                foreach ($warnings as $warning) {
                    $output->writeln($warning);
                }

                $output->writeln(sprintf('Dry run: %d database(s), nothing changed.', count($attachments)));

                return 0;
            }

            $client = $this->client();
            $this->bridge->apply($client, $attachments, $force);

            foreach ($typedPlan['statements'] as $statement) {
                $client->emit($statement);
            }

            foreach ($warnings as $warning) {
                $output->writeln($warning);
            }

            $output->writeln(sprintf('Attached %d database(s).', count($attachments)));

            return 0;
        } catch (ClickHouseError|RuntimeException $exception) {
            $output->writeln('clickhouse:attach: ' . $exception->getMessage());

            return 1;
        }
    }

    /**
     * @param list<\Modules\Clickhouse\Domain\SqliteAttachment> $attachments
     *
     * @return list<string>
     */
    private function statements(array $attachments, bool $force): array
    {
        $statements = [];

        foreach ($attachments as $attachment) {
            if ($force) {
                $statements[] = $attachment->detachStatement();
            }

            foreach ($attachment->statements() as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * Checkpoint first when asked, and report the WAL otherwise. The two are
     * exclusive: a checkpointed file has nothing left to warn about.
     *
     * @param list<\Modules\Clickhouse\Domain\SqliteAttachment> $attachments
     *
     * @return list<string> lines to print after the DDL
     */
    private function warnings(array $attachments, bool $checkpoint, CliOutput $output): array
    {
        $warnings = [];

        foreach ($attachments as $attachment) {
            if ($checkpoint) {
                if (SqliteJournal::checkpoint($attachment->path)) {
                    $output->writeln('Checkpointed ' . $attachment->path);
                }

                continue;
            }

            $warning = SqliteJournal::warning($attachment->databaseName, $attachment->path);
            if (null !== $warning) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * The explicit table definitions, and the warnings that come with them.
     *
     * @param list<\Modules\Clickhouse\Domain\SqliteAttachment> $attachments
     *
     * @return array{statements: list<string>, warnings: list<string>}
     */
    private function typedPlan(array $attachments, bool $typed): array
    {
        $statements = [];
        $warnings = [];

        if (!$typed) {
            return ['statements' => [], 'warnings' => []];
        }

        $planner = new TypedTablePlanner();

        foreach ($attachments as $attachment) {
            $plan = $planner->plan($attachment);
            $statements = array_merge($statements, $plan['statements']);
            $warnings = array_merge($warnings, $plan['warnings']);
        }

        return ['statements' => $statements, 'warnings' => $warnings];
    }

    private function client(): ClickHouseClient
    {
        return $this->client ?? ClickhouseServices::client();
    }
}
