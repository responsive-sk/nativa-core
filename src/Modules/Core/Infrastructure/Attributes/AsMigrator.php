<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Attributes;

use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;

/**
 * Marks a class as a database migrator.
 *
 * The migrator class must expose run(CliOutput $output): void
 * and build its schema with idempotent CREATE TABLE IF NOT EXISTS statements.
 *
 * @see DatabaseMigrator
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsMigrator
{
    public function __construct(
        public string $module,
        public string $table,
    ) {
    }
}
