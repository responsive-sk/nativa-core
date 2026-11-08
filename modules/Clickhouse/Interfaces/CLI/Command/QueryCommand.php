<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Interfaces\CLI\Command;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Domain\ClickHouseError;
use Modules\Clickhouse\Interfaces\CLI\CommandOptions;
use Modules\Clickhouse\Interfaces\HTTP\ClickhouseServices;
use Modules\Core\Interfaces\CLI\CliOutput;
use RuntimeException;

/**
 * bin/cms clickhouse:query — one statement, read-only unless asked otherwise.
 *
 * The bridge is read-only by default (proposal, Decision 5). The kernel's own
 * connections are WAL and multi-writer, so a write through ClickHouse would be
 * a second journal owner on a file the kernel is actively writing; `--write`
 * is the operator saying they know that, and the command repeats the warning
 * before it executes anything. A statement that does not start with one of the
 * five read keywords needs the flag — a typo that turns `SELECT` into `SELETC`
 * is then a refusal, not a server error.
 *
 * Formats: `JSONEachRow` (default, one object per line) and `TSV` (raw
 * TabSeparated, printed as the server sent it). A server error prints as
 * `ClickHouse error <code> (<type>): <message>` and exits 1 — the same string
 * the transport logs, so a cron mail and `status` read alike.
 */
final class QueryCommand
{
    public function __construct(private readonly ?ClickHouseClient $client = null)
    {
    }

    /**
     * @param list<string> $argv index 0 is `clickhouse:query`
     *
     * @return int 0 on success, 1 on a refusal or a server error
     */
    public function run(array $argv, CliOutput $output): int
    {
        try {
            $parsed = CommandOptions::parse(array_slice($argv, 1), ['db', 'format'], ['write']);
        } catch (RuntimeException $exception) {
            $output->writeln('clickhouse:query: ' . $exception->getMessage());

            return 1;
        }

        $positional = $parsed['positional'];

        if ([] === $positional) {
            $output->writeln('clickhouse:query: a SQL statement is required.');

            return 1;
        }

        if (count($positional) > 1) {
            $output->writeln('clickhouse:query: takes exactly one statement; quote it as one argument.');

            return 1;
        }

        $sql = trim($positional[0]);
        $options = $parsed['options'];
        $format = CommandOptions::value($options, 'format') ?? 'JSONEachRow';

        if (!in_array($format, ['JSONEachRow', 'TSV'], true)) {
            $output->writeln('clickhouse:query: unknown format "' . $format . '" (JSONEachRow or TSV).');

            return 1;
        }

        $write = isset($options['write']);
        $read = 1 === preg_match('/^(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql);

        if (!$read && !$write) {
            $output->writeln(
                'clickhouse:query: a statement that does not start with SELECT, WITH, SHOW, DESCRIBE or EXPLAIN needs --write.',
            );

            return 1;
        }

        if ($write) {
            $output->writeln(
                'WARNING: a write locks the whole SQLite file; the kernel keeps its own WAL connections, so prefer writing through the kernel.',
            );
        }

        $database = CommandOptions::value($options, 'db') ?? '';

        try {
            $client = $this->client();

            if ($read && 'TSV' === $format) {
                $body = $client->emit(ClickHouseClient::withFormat($sql, 'TSV'), $database);

                foreach (explode("\n", rtrim($body, "\n")) as $line) {
                    if ('' !== trim($line)) {
                        $output->writeln($line);
                    }
                }

                return 0;
            }

            if ($read) {
                foreach ($client->select($sql, $database) as $row) {
                    $output->writeln(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
                }

                return 0;
            }

            $body = $client->emit($sql, $database);

            if ('' !== trim($body)) {
                $output->writeln(rtrim($body));
            }

            return 0;
        } catch (ClickHouseError $error) {
            $output->writeln((string) $error);

            return 1;
        } catch (RuntimeException $exception) {
            $output->writeln('clickhouse:query: ' . $exception->getMessage());

            return 1;
        }
    }

    private function client(): ClickHouseClient
    {
        return $this->client ?? ClickhouseServices::client();
    }
}
