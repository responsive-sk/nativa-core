<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Interfaces\HTTP;

use Modules\Clickhouse\Application\ClickHouseClient;
use Modules\Clickhouse\Contracts\ClickHouseTransport;
use Modules\Clickhouse\Infrastructure\Http\ClickHouseHttpClient;
use Modules\Core\Infrastructure\Env;
use RuntimeException;

/**
 * ClickhouseServices — the module's one place for environment and instances.
 *
 * The module is inert until `CLICKHOUSE_URL` exists: an empty value throws the
 * exact sentence the commands and their tests share, rather than constructing
 * a client that would fail at the first statement with a message about a URL.
 *
 * Built once per process, the `MediaServices` shape. A CLI process runs one
 * command, so "once" is also "for this command"; the reset() exists for tests
 * that change the environment between commands.
 */
final class ClickhouseServices
{
    private static ?ClickHouseTransport $transport = null;

    private static ?ClickHouseClient $client = null;

    /**
     * @throws RuntimeException when the module is not configured
     */
    public static function transport(): ClickHouseTransport
    {
        return self::$transport ??= new ClickHouseHttpClient(
            self::url(),
            self::user(),
            self::password(),
            self::integer('CLICKHOUSE_CONNECT_TIMEOUT', 5),
            self::integer('CLICKHOUSE_TIMEOUT', 30),
        );
    }

    /**
     * @throws RuntimeException when the module is not configured
     */
    public static function client(): ClickHouseClient
    {
        return self::$client ??= new ClickHouseClient(self::transport());
    }

    /**
     * The database-name prefix — `nativa_`, so every attached database is
     * recognisable and `status` enumerates only what this module created.
     */
    public static function databasePrefix(): string
    {
        return Env::get('CLICKHOUSE_DATABASE_PREFIX', 'nativa_') ?? 'nativa_';
    }

    /**
     * The configured server URL, or the refusal the commands print.
     *
     * @throws RuntimeException when CLICKHOUSE_URL is empty
     */
    public static function url(): string
    {
        $url = Env::get('CLICKHOUSE_URL', '') ?? '';

        if ('' === $url) {
            throw new RuntimeException(
                'ClickHouse is not configured (CLICKHOUSE_URL, CLICKHOUSE_USER, CLICKHOUSE_PASSWORD)',
            );
        }

        return $url;
    }

    public static function user(): string
    {
        return Env::get('CLICKHOUSE_USER', 'default') ?? 'default';
    }

    public static function password(): string
    {
        return Env::get('CLICKHOUSE_PASSWORD', '') ?? '';
    }

    public static function reset(): void
    {
        self::$transport = null;
        self::$client = null;
    }

    private static function integer(string $key, int $default): int
    {
        $value = Env::get($key, null);

        return null === $value || '' === $value ? $default : (int) $value;
    }
}
