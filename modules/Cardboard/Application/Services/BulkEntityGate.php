<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Application\Services;

use Modules\Core\Infrastructure\Http\Response;

/**
 * BulkEntityGate — may this user run this bulk operation on this entity?
 *
 * `/mark/bulk-publish` and `/mark/bulk-delete` are ONE route each, but the
 * POST body names the entity they act on. That made the path-level permission
 * table insufficient on its own: an author holding `content.manage` passed the
 * bulk route's row and then posted `entity=users`, reaching user deletion
 * through a content permission. Verified live before this gate existed.
 *
 * NOTE (single-admin-module variant): the permission map below is a local
 * copy of Users' AdminRoutePolicy::permissionForEntity (permission strings
 * only) so Cardboard keeps zero `use Modules\<Feature>` lines and stays a
 * leaf for the install-closure scanner. The caller passes the session user
 * as object (duck-typed: hasPermission(string): bool, id(): string).
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkEntityGate
{
    /**
     * Refuse the request, or return null when the caller may proceed.
     *
     * Called BEFORE any id is parsed or acted on: a check that runs after the
     * first delete is not a check.
     *
     * @param object|null $user session user (duck-typed, no Users import)
     */
    public static function refuse(string $entity, string $action, ?object $user): ?Response
    {
        $required = self::permissionForEntity($entity);

        if (null === $required) {
            // An unrecognised entity is not "probably fine" — a bulk form for a
            // new entity type must be classified here before it can be run.
            self::log($action, $entity, 'unknown-entity', self::userId($user));

            return self::forbidden($entity, 'no permission policy covers this entity');
        }

        if (null !== $user && self::hasPermission($user, $required)) {
            return null;
        }

        self::log($action, $entity, $required, self::userId($user));

        return self::forbidden($entity, sprintf('the %s permission is required', $required));
    }

    private static function permissionForEntity(string $entity): ?string
    {
        return match ($entity) {
            'articles', 'pages' => 'content.manage',
            'users' => 'users.manage',
            'roles' => 'roles.manage',
            default => null,
        };
    }

    private static function hasPermission(object $user, string $required): bool
    {
        return method_exists($user, 'hasPermission') && true === $user->hasPermission($required);
    }

    private static function userId(?object $user): ?string
    {
        if (null === $user || !method_exists($user, 'id')) {
            return null;
        }

        $id = $user->id();

        return is_string($id) ? $id : null;
    }

    private static function log(string $action, string $entity, string $required, ?string $userId): void
    {
        error_log(sprintf(
            '[bulk-authz] refused %s entity=%s required=%s user=%s',
            $action,
            $entity,
            $required,
            $userId ?? 'anonymous',
        ));
    }

    private static function forbidden(string $entity, string $because): Response
    {
        $safe = htmlspecialchars($entity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(sprintf(
            'Forbidden — bulk operation on "%s" is not permitted: %s.',
            $safe,
            $because,
        ), 403);
    }
}
