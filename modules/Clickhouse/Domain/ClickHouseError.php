<?php

declare(strict_types = 1);

namespace Modules\Clickhouse\Domain;

use RuntimeException;

/**
 * ClickHouseError — a server error body, or a transport failure, as one value.
 *
 * ClickHouse answers a failed query with an HTTP status and a body whose first
 * line carries everything an operator needs:
 *
 *     Code: 60. DB::Exception: Table default.x doesn't exist. (UNKNOWN_TABLE) (version 24.8.4.13)
 *
 * The code alone means nothing without the exception class, so both are kept.
 * A transport failure has no server code and no class: `code` is 0 and `type`
 * is `transport`, which keeps the distinction visible in a log line instead of
 * reporting "error 0" like a successful HTTP exchange would.
 *
 * Not `readonly` as a class: PHP 8.5 forbids a readonly class extending the
 * non-readonly `RuntimeException`, and this value has to be throwable — the
 * transport contract is `@throws ClickHouseError`. The one property the
 * constructor does not inherit is `readonly`, so the value is just as
 * immutable as the class modifier would make it.
 */
final class ClickHouseError extends RuntimeException
{
    /**
     * @param int    $code    the server's `Code: N`; 0 for a transport failure
     * @param string $message the exception message, without the code prefix
     * @param string $type    the `DB::Exception` class, or `transport`
     */
    public function __construct(int $code, string $message, public readonly string $type = '')
    {
        parent::__construct($message, $code);
    }

    /**
     * Parse the body of a non-2xx ClickHouse response.
     *
     * Only the first line is the error; everything after it is a stack trace.
     * A body that does not match the documented shape is kept whole rather
     * than discarded: an unrecognised answer is still the only diagnosis
     * there is.
     */
    public static function fromResponse(string $body): self
    {
        $trimmed = trim($body);
        $firstLine = '' === $trimmed ? '' : explode("\n", $trimmed, 2)[0];
        $firstLine = trim($firstLine);

        if (1 === preg_match('/^Code:\s*(\d+)\.\s*([A-Za-z0-9_:]+):\s*(.+)$/', $firstLine, $matches)) {
            return new self((int) $matches[1], trim($matches[3]), $matches[2]);
        }

        return new self(0, '' === $firstLine ? 'ClickHouse returned an error with no body' : $firstLine);
    }

    /**
     * A request that never reached a ClickHouse answer — a refused connection,
     * a timeout, a curl error.
     */
    public static function fromTransport(string $reason): self
    {
        return new self(0, $reason, 'transport');
    }

    /**
     * The line an operator reads and `status` exits 1 on.
     */
    public function __toString(): string
    {
        return '' === $this->type
            ? sprintf('ClickHouse error %d: %s', $this->getCode(), $this->getMessage())
            : sprintf('ClickHouse error %d (%s): %s', $this->getCode(), $this->type, $this->getMessage());
    }
}
