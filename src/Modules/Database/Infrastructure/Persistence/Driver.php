<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * Driver — the connection flavours this tree can speak.
 *
 * Two, and both are PHP extensions: `pdo_sqlite` (the default, one file per
 * module) and `pdo_pgsql` (one DSN body per module). An enum rather than a
 * pair of constants because the values are a closed set, and because the
 * const ledger in `tests/Architecture/NameOwnershipContractTest` counts
 * declarations: a new name for a closed set belongs in a type, not in the
 * global const inventory.
 */
enum Driver: string
{
    case Sqlite = 'sqlite';

    case Pgsql = 'pgsql';

    /**
     * @throws \InvalidArgumentException when the name is not supported
     */
    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower($name)) ?? throw new \InvalidArgumentException(sprintf(
            'Unsupported DB_DRIVER "%s"; supported: %s',
            $name,
            implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
        ));
    }
}
