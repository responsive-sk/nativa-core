<?php

declare(strict_types = 1);

namespace Modules\Outbox\Interfaces\HTTP;

use Modules\Outbox\Domain\SecurityEvents;

/**
 * SecurityEventServices — named emitters for the account/role mutations.
 *
 * These two are the events an administrator most needs to be able to
 * reconstruct after the fact: who changed whose access, and when. The inline
 * audit row records them, but it is written to `audit.db` immediately after
 * the write to `users.db` and nothing makes the pair atomic — a crash in
 * between keeps the privilege change and loses the record of it.
 *
 * The helper exists so a call site states the OPERATION rather than repeating
 * the payload shape, and so the `userId`/`roleId` keys stay spelled the way
 * `SecurityEvents::payloadKeys()` declares them.
 *
 * @internal
 *
 * @coversNothing
 */
final class SecurityEventServices
{
    /** Operations on a user row. */
    public const string OP_CREATE = 'create';

    public const string OP_UPDATE = 'update';

    public const string OP_DELETE = 'delete';

    /** Operations on a role or its permission set. */
    public const string OP_PERMISSIONS = 'permissions';

    /**
     * Who made the change, plus a readable label for the affected user.
     * `$userId` itself is the target, not the actor.
     *
     * @param array{actor_id?: string, actor_name?: string, target?: string} $context
     *
     * @return bool false when the event could not be stored — never an error
     *              the calling write should fail on
     */
    public static function userChanged(
        string $userId,
        string $operation,
        ?string $ip = null,
        array $context = [],
    ): bool {
        return OutboxServices::securityEvent(
            SecurityEvents::USER_CHANGED,
            ['userId' => $userId, 'operation' => $operation, 'ip' => $ip ?? ''],
            self::metadata($context),
        );
    }

    /**
     * A bulk operation refused because the entity requires a permission the
     * caller does not hold.
     *
     * Carried as an authz denial rather than a fifth event type: it IS an
     * authorization decision, with the same investigative value, and a separate
     * type would fragment the very history someone reads to reconstruct a
     * probe.
     *
     * @return bool false when the event could not be stored
     */
    public static function bulkRefused(string $entity, string $action, ?string $ip = null): bool
    {
        return OutboxServices::securityEvent(
            SecurityEvents::AUTHZ_DENIED,
            [
                'path'     => '/mark/' . $action,
                'required' => self::permissionForEntity($entity) ?? 'unclassified entity',
                'userId'   => 'bulk:' . $entity,
                'roles'    => [],
                'ip'       => $ip ?? '',
            ],
        );
    }

    /**
     * Entity permission through Users when installed, null otherwise. The
     * class is named, never imported: a use-statement here would make
     * Outbox depend on Users for a label (same shape as RateLimit's
     * class_exists outbox guard, mirrored back the other way).
     */
    private static function permissionForEntity(string $entity): ?string
    {
        if (!class_exists('Modules\\Users\\Domain\\AdminRoutePolicy')) {
            return null;
        }

        return \Modules\Core\Domain\AdminRoutePolicy::permissionForEntity($entity);
    }

    /**
     * One authentication outcome — success, failure, logout or remember-me.
     *
     * @return bool false when the event could not be stored
     */
    public static function authAttempt(string $email, string $outcome, ?string $ip = null, ?string $note = null): bool
    {
        return OutboxServices::securityEvent(
            SecurityEvents::AUTH_ATTEMPT,
            ['email' => $email, 'outcome' => $outcome, 'ip' => $ip ?? ''],
            null === $note ? null : ['note' => $note],
        );
    }

    /**
     * Who made the change, plus a readable label for the affected role.
     *
     * @param array{actor_id?: string, actor_name?: string, target?: string} $context
     *
     * @return bool false when the event could not be stored
     */
    public static function roleChanged(
        string $roleId,
        string $operation,
        ?string $ip = null,
        array $context = [],
    ): bool {
        return OutboxServices::securityEvent(
            SecurityEvents::ROLE_CHANGED,
            ['roleId' => $roleId, 'operation' => $operation, 'ip' => $ip ?? ''],
            self::metadata($context),
        );
    }

    /**
     * Keep only the readable attribution keys the audit handler understands.
     *
     * The actor cannot live in the payload: `userId`/`roleId` there name the
     * object being changed, and `payloadKeys()` is a closed vocabulary. Metadata
     * is where the outbox already carries "who caused this" for article events.
     *
     * @param array{actor_id?: string, actor_name?: string, target?: string} $context
     *
     * @return array<string, string>|null
     */
    private static function metadata(array $context): ?array
    {
        $metadata = [];

        foreach (['actor_id', 'actor_name', 'target'] as $key) {
            $value = $context[$key] ?? '';

            if (is_string($value) && '' !== $value) {
                $metadata[$key] = $value;
            }
        }

        return [] === $metadata ? null : $metadata;
    }
}
