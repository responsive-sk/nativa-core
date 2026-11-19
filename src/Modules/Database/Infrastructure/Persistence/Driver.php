<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * Driver — the connection flavours this tree can speak.
 *
 * Three, and all are PHP extensions: `pdo_sqlite` (the default, one file per
 * module), `pdo_pgsql` (one shared database, one schema per module) and
 * `pdo_mysql` (MariaDB, one database per module because MariaDB has no
 * separate schema layer). An enum rather than a pair of constants because the
 * values are a closed set, and because the const ledger in
 * `tests/Architecture/NameOwnershipContractTest` counts declarations: a new
 * name for a closed set belongs in a type, not in the global const inventory.
 *
 * PDO reports MariaDB as `mysql`, so that spelling is accepted as an alias.
 */
enum Driver: string
{
    case Sqlite = 'sqlite';

    case Pgsql = 'pgsql';

    case MariaDb = 'mariadb';

    /**
     * @throws \InvalidArgumentException when the name is not supported
     */
    public static function fromName(string $name): self
    {
        $normalised = strtolower($name);

        if ('mysql' === $normalised) {
            return self::MariaDb;
        }

        return self::tryFrom($normalised) ?? throw new \InvalidArgumentException(sprintf(
            'Unsupported DB_DRIVER "%s"; supported: %s',
            $name,
            implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
        ));
    }
}
