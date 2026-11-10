<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

/**
 * StatementIntent — does this prepared SQL write?
 *
 * `query()` and `execute()` state their intent at the call site, so this class
 * only exists for `prepare()`, where the statement is handed back before it
 * runs and the connection has to choose a side now. One function, one table,
 * one test: the heuristic is contained instead of spread over every caller.
 *
 * The rule fails closed. Only the statements that are unambiguously reads are
 * reads; everything else, including `WITH` (a CTE can end in INSERT), `CALL`
 * (a procedure can write) and `PRAGMA` (an assignment form writes), goes to
 * the primary. A misclassification in that direction costs a replica read; the
 * other direction would send a write to a connection that refuses it.
 *
 * SQL comments and leading parentheses are skipped so `/* why *​/ SELECT` and
 * `(SELECT ...)` classify as reads; a string literal cannot appear before the
 * first keyword, so nothing else needs stripping.
 */
final class StatementIntent
{
    /**
     * First keywords that never write.
     *
     * @var list<string>
     */
    private const array READ_KEYWORDS = ['SELECT', 'EXPLAIN', 'VALUES'];

    public static function writes(string $sql): bool
    {
        $keyword = self::firstKeyword($sql);

        if (null === $keyword) {
            return true;
        }

        return !\in_array($keyword, self::READ_KEYWORDS, true);
    }

    public static function reads(string $sql): bool
    {
        return !self::writes($sql);
    }

    /**
     * The first word of the statement, uppercased, with comments removed.
     */
    private static function firstKeyword(string $sql): ?string
    {
        $sql = self::stripLeadingComments($sql);
        $sql = ltrim($sql, " \t\n\r\0\x0B(");

        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $sql, $matches)) {
            return null;
        }

        return strtoupper($matches[0]);
    }

    private static function stripLeadingComments(string $sql): string
    {
        $sql = ltrim($sql);

        while (true) {
            if (str_starts_with($sql, '--')) {
                $newline = strpos($sql, "\n");
                $sql = false === $newline ? '' : substr($sql, $newline + 1);
                $sql = ltrim($sql);

                continue;
            }

            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                $sql = false === $end ? '' : substr($sql, $end + 2);
                $sql = ltrim($sql);

                continue;
            }

            return $sql;
        }
    }
}
