<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Services;

/**
 * Audit — the panel's write log, when the Audit module is installed.
 *
 * The reference is fully qualified inside the guard, not a `use` line: an
 * import would put Audit in RestAdmin's install closure, and the panel must
 * install without it. `class_exists()` is the same guard `UsersAction` uses
 * for the Outbox — the write still happens when the log does not.
 *
 * The call is best-effort for a second reason: the panel has already committed
 * its specification (or sent the remote write) by the time this runs. An audit
 * sink that is down must not turn a successful save into a 500, because the
 * operator would retry a write that already happened. The failure goes to
 * stderr, where an operator can see it without the request being undone.
 */
final class Audit
{
    public static function log(string $action, string $target, string $detail, ?string $ipAddress = null): void
    {
        if (!class_exists('Modules\\Audit\\Interfaces\\HTTP\\AuditServices')) {
            return;
        }

        try {
            \Modules\Audit\Interfaces\HTTP\AuditServices::log($action, $target, $detail, $ipAddress);
        } catch (\Throwable $exception) {
            error_log('restadmin: audit write failed: ' . $exception->getMessage());
        }
    }
}
