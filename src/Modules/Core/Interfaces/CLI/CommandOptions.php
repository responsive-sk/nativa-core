<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI;

use RuntimeException;

/**
 * CommandOptions — the argv rules every command shares.
 *
 * `bin/cms` hands each command its own arguments, index 0 being the command
 * name. Before this class there were three parsers with three ideas of what a
 * typo means: the Clickhouse commands refused `--dry-runn`, the Blog command
 * accepted `--format` with no value and fell back to its default, and the
 * Outbox command refused an option it had not read while treating a flag's
 * `=value` as data. One parser owns the rule here, so an option cannot mean a
 * value in one command and a flag in another, and every command refuses what it
 * did not declare.
 *
 * The contract, deliberately narrow:
 *
 * - `--name=value` and `--name value` are the same option;
 * - `--name` on a flag is `true`; `--name=value` on a flag is an error;
 * - a value option with no value is an error, unless it is declared optional,
 *   in which case the empty string is the value (the `--format` case: the
 *   symfony command treated a bare option as "use the default");
 * - a short form is an alias declared by the command, never a guess;
 * - anything that does not start with `-` is positional, and the command
 *   decides whether it accepts one.
 *
 * An unknown option is an error, not a shrug: `--dry-runn` would attach for
 * real, and `--lmit=50` would drain a different batch than the operator asked
 * for while printing a clean-looking summary.
 */
final class CommandOptions
{
    /**
     * @param list<string>          $arguments            the command's arguments, without its name
     * @param list<string>          $valueOptions         long names that require a value (`--name=value` or `--name value`)
     * @param list<string>          $flags                long names that take no value
     * @param array<string, string> $aliases              short name => long name, exactly as the command declares them
     * @param list<string>          $optionalValueOptions long names whose bare form means the empty string
     *
     * @throws RuntimeException on an unknown option, a flag with a value, or a missing value
     *
     * @return array{options: array<string, string|bool>, positional: list<string>}
     */
    public static function parse(
        array $arguments,
        array $valueOptions,
        array $flags = [],
        array $aliases = [],
        array $optionalValueOptions = [],
    ): array {
        $options = [];
        $positional = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if (!str_starts_with($argument, '-')) {
                $positional[] = $argument;

                continue;
            }

            if (str_starts_with($argument, '--')) {
                $name = substr($argument, 2);
            } else {
                $short = substr($argument, 1);

                if (!isset($aliases[$short])) {
                    throw new RuntimeException(sprintf('unknown option "-%s".', $short));
                }

                $name = $aliases[$short];
            }

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

            $takesValue = in_array($name, $valueOptions, true);
            $optionalValue = in_array($name, $optionalValueOptions, true);

            if (!$takesValue && !$optionalValue) {
                throw new RuntimeException(sprintf('unknown option "--%s".', $name));
            }

            if (null === $value) {
                $next = $arguments[$i + 1] ?? null;

                if (null !== $next && !str_starts_with($next, '-')) {
                    $value = $arguments[++$i];
                } elseif ($optionalValue) {
                    $value = '';
                } else {
                    throw new RuntimeException(sprintf('option "--%s" needs a value.', $name));
                }
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
