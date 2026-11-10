<?php

declare(strict_types = 1);

namespace Modules\Database\Contracts;

/**
 * PrimaryReadInterface — the named escape from replica reads.
 *
 * A connection that implements it may serve reads somewhere other than the
 * primary. Most reads tolerate that; a few do not, because their answer must
 * never be stale: a session lookup right after logout, a "token already used"
 * check, the row an admin just wrote.
 *
 * The call site says which read it is, and the connection returns to its
 * previous routing afterwards:
 *
 *     $rows = $db instanceof PrimaryReadInterface
 *         ? $db->onPrimary(fn (): array => $db->query($sql)->fetchAll())
 *         : $db->query($sql)->fetchAll();
 *
 * The `instanceof` is part of the contract on purpose: a single-file
 * connection has only one place to read from, so it does not implement this
 * interface and callers do not branch on a flag.
 */
interface PrimaryReadInterface
{
    /**
     * Run the callback with every read routed to the primary.
     *
     * Nests like a stack. Reads after the callback return to the routing they
     * had before, unless a write happened in between: then they stay on the
     * primary so they can see it.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function onPrimary(callable $callback): mixed;
}
