<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Interfaces\CLI\Command;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Application\Services\SqliteEngineBridge;
use Modules\Clickhouse\Application\Services\SqliteJournal;
use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Interfaces\CLI\CommandOptions;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Interfaces\CLI\CliOutput;
use RuntimeException;

/**
 * bin/cms clickhouse:status — what the server says, not what the plan said.
 *
 * Order of the report: reachability, then every attached `nativa_*` database
 * with the path read back from `SHOW CREATE DATABASE` and its table count,
 * then drift lines, then the WAL lines. The path comparison is the point:
 * `attach` writes a path the ClickHouse *host* has to reach, and the only
 * authority on what it reached is the server itself.
 *
 * Exit codes: 0 when the server answers and nothing drifted, 1 when it is
 * unreachable or a difference is printed. A status that always exits 0 would
 * be decoration in a cron job.
 */
final class StatusCommand
{
    public function __construct(
        private readonly SqliteEngineBridge $bridge = new SqliteEngineBridge(),
        private readonly ?ClickHouseClient $client = null,
    ) {
    }

    /**
     * @param list<string> $argv index 0 is `clickhouse:status`
     *
     * @return int 0 when healthy, 1 on unreachable or drift
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $parsed = CommandOptions::parse(array_slice($argv, 1), ['prefix'], []);
        } catch (RuntimeException $exception) {
            $output->writeln('clickhouse:status: ' . $exception->getMessage());

            return 1;
        }

        if ([] !== $parsed['positional']) {
            $output->writeln('clickhouse:status: unexpected argument "' . $parsed['positional'][0] . '".');

            return 1;
        }

        $prefix = CommandOptions::value($parsed['options'], 'prefix') ?? ClickhouseServices::databasePrefix();

        try {
            $client = $this->client();

            if (!$client->ping()) {
                $output->writeln('ClickHouse is not reachable at ' . ClickhouseServices::url());

                return 1;
            }

            $attached = array_values(array_filter(
                $client->databases(),
                static fn (string $name): bool => str_starts_with($name, $prefix),
            ));

            foreach ($attached as $database) {
                $path = SqliteEngineBridge::sqlitePathFrom($client->createDatabase($database));
                $output->writeln(sprintf(
                    '%s  %s  %d tables',
                    $database,
                    $path ?? '(no SQLite path reported)',
                    count($client->tables($database)),
                ));
            }

            $plan = $this->bridge->plan($prefix);
            $drift = $this->bridge->drift($client, $plan);

            foreach ($drift as $line) {
                $output->writeln('drift: ' . $line);
            }

            if ([] === $drift) {
                $output->writeln('No drift.');
            }

            foreach ($plan as $attachment) {
                $warning = SqliteJournal::warning($attachment->databaseName, $attachment->path);
                if (null !== $warning) {
                    $output->writeln($warning);
                }
            }

            return [] === $drift ? 0 : 1;
        } catch (ClickHouseError|RuntimeException $exception) {
            $output->writeln('clickhouse:status: ' . $exception->getMessage());

            return 1;
        }
    }

    private function client(): ClickHouseClient
    {
        return $this->client ?? ClickhouseServices::client();
    }
}
