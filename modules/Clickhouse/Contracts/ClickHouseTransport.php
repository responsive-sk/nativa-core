<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Contracts;

use Modules\Clickhouse\Domain\ClickHouseError;

/**
 * ClickHouseTransport — the one method this module needs from the wire.
 *
 * This interface **is** the test seam (proposal, Decision 8): the module's
 * tests answer with a recording transport, so no gate needs a ClickHouse
 * server, and the client itself can stay `final readonly` — the opposite of
 * `CloudinaryApi`, which had to stay open because it had no interface to
 * substitute.
 *
 * `$database` is sent as ClickHouse's `database` query parameter, never
 * concatenated into `$sql`. A database name comes from operator input
 * (`CLICKHOUSE_DATABASE_PREFIX`), and interpolating it would turn every
 * statement into a place where a name could become syntax.
 */
interface ClickHouseTransport
{
    /**
     * Execute one SQL statement and return the raw response body.
     *
     * @param string $sql      the statement, sent verbatim as the request body
     * @param string $database ClickHouse `database` parameter; '' = the server default
     *
     * @throws ClickHouseError on any non-2xx answer or a transport failure
     */
    public function execute(string $sql, string $database = ''): string;
}
