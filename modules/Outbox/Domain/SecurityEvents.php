<?php

declare(strict_types = 1);

namespace Modules\Outbox\Domain;

/**
 * SecurityEvents — the vocabulary of security-relevant domain events.
 *
 * One owner, like `Permissions` owns the permission names: no emit site and no
 * handler spells an event type or invents a payload key of its own.
 *
 * ## Why these go through the outbox at all
 *
 * `AuditServices::log()` already records admin writes, but it writes to
 * `audit.db` — a different SQLite file from `users.db`, where the write
 * happened. **No transaction spans two databases**, so "the privileged write"
 * and "the record of it" are never atomic. Inline, a crash between them keeps
 * the write and loses the record, which for an access change is the wrong
 * direction to fail in: the thing you would want to investigate is the thing
 * that vanished.
 *
 * The outbox closes that gap to the extent SQLite allows. It is **not** a
 * transactional outbox — `outbox.db` is a third separate file. What it gives is
 * at-least-once delivery plus an idempotent handler, so the record survives a
 * crash and is written when the queue next drains (end of request, or
 * `bin/cms outbox:process`). Anything stronger would be a false promise.
 *
 * @internal
 *
 * @coversNothing
 */
final class SecurityEvents
{
    /** A protected path was refused to the signed-in user. */
    public const string AUTHZ_DENIED = 'security.authz.denied';

    /** A user row was created, changed or deleted. */
    public const string USER_CHANGED = 'security.user.changed';

    /** A role or its permission set was changed. */
    public const string ROLE_CHANGED = 'security.role.changed';

    /** Login, logout, remember-me or a failed credential check. */
    public const string AUTH_ATTEMPT = 'security.auth.attempt';

    /** A rate limiter refused a caller. */
    public const string RATELIMIT_TRIPPED = 'security.ratelimit.tripped';

    /** Outcomes carried by AUTH_ATTEMPT. */
    public const string OUTCOME_SUCCESS = 'success';

    public const string OUTCOME_FAILURE = 'failure';

    public const string OUTCOME_LOGOUT = 'logout';

    public const string OUTCOME_REMEMBER = 'remember';

    /**
     * Every event type this module knows, for the coverage guard.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AUTHZ_DENIED,
            self::USER_CHANGED,
            self::ROLE_CHANGED,
            self::AUTH_ATTEMPT,
            self::RATELIMIT_TRIPPED,
        ];
    }

    /**
     * Is this one of ours?
     *
     * The drain dispatches by event type, and an unregistered type throws —
     * so this is what keeps a typo from becoming a message that retries five
     * times and then fails.
     */
    public static function isSecurityEvent(string $eventType): bool
    {
        return in_array($eventType, self::all(), true);
    }

    /**
     * The payload key names, so an emit site cannot quietly invent one the
     * handler will not read.
     *
     * @return list<string>
     */
    public static function payloadKeys(string $eventType): array
    {
        return match ($eventType) {
            // `ip` is a key of EVERY type here, and it took two passes to say
            // so. The Audit handler reads it unconditionally —
            // `SecurityEventHandler::ip()` runs for every message, not per type
            // — and every emit site sends it. The two refusal arms were missing
            // it; once those were fixed (measured 2026-10-16) the two account
            // arms were still short, while `SecurityEventServices::userChanged()`
            // and `::roleChanged()` both send `'ip' => $ip ?? ''`. Each arm is
            // now compared against the emit sites by
            // tests/Architecture/SecurityEventPayloadContractTest.php, which is
            // what "every emit site sends it" needed to become checkable rather
            // than rememberable.
            self::AUTHZ_DENIED      => ['path', 'required', 'userId', 'roles', 'ip'],
            self::USER_CHANGED      => ['userId', 'operation', 'ip'],
            self::ROLE_CHANGED      => ['roleId', 'operation', 'ip'],
            self::AUTH_ATTEMPT      => ['email', 'outcome', 'ip'],
            self::RATELIMIT_TRIPPED => ['key', 'scope', 'ip'],
            default                 => [],
        };
    }
}
