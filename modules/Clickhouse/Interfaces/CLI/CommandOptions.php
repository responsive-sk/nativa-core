<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Interfaces\CLI;

use RuntimeException;

/**
 * CommandOptions — the argv rules the three commands share.
 *
 * `bin/cms` hands each command its own arguments, index 0 being the command
 * name, and every command needs the same three answers: which option names
 * exist, which of them take a value, and which arguments are positional. One
 * parser owns that here, so `--db` cannot mean a value in attach and a flag in
 * query, and a typo'd option is refused by all three instead of being ignored
 * by one.
 *
 * An unknown option is an error, not a shrug: `--dry-runn` would attach for
 * real, and `--writ` would refuse a write that was meant to run.
 */
final class CommandOptions
{
    /**
     * @param list<string> $arguments   the command's arguments, without its name
     * @param list<string> $valueOptions names that require a value (`--name=value` or `--name value`)
     * @param list<string> $flags        names that take no value
     *
     * @return array{options: array<string, string|bool>, positional: list<string>}
     *
     * @throws RuntimeException on an unknown option, a missing value, or a short option
     */
    public static function parse(array $arguments, array $valueOptions, array $flags): array
    {
        $options = [];
        $positional = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; ++$i) {
            $argument = $arguments[$i];

            if (!str_starts_with($argument, '--')) {
                if (str_starts_with($argument, '-')) {
                    throw new RuntimeException(sprintf('unknown option "%s".', $argument));
                }

                $positional[] = $argument;
                continue;
            }

            $name = substr($argument, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (in_array($name, $flags, true)) {
                if (null !== $value) {
                    throw new RuntimeException(sprintf('option "--%s" takes no value.', $name));
                }

                $options[$name] = true;
                continue;
            }

            if (!in_array($name, $valueOptions, true)) {
                throw new RuntimeException(sprintf('unknown option "--%s".', $name));
            }

            if (null === $value) {
                if (!isset($arguments[$i + 1]) || str_starts_with($arguments[$i + 1], '-')) {
                    throw new RuntimeException(sprintf('option "--%s" needs a value.', $name));
                }

                $value = $arguments[++$i];
            }

            $options[$name] = $value;
        }

        return ['options' => $options, 'positional' => $positional];
    }

    /**
     * @param array<string, string|bool> $options
     */
    public static function value(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
