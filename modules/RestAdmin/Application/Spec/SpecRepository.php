<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Spec;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\RestAdmin\Domain\Model\ActionSet;
use Modules\RestAdmin\Domain\Model\AuthSpec;
use Modules\RestAdmin\Domain\Model\FieldSpec;
use Modules\RestAdmin\Domain\Model\FieldType;
use Modules\RestAdmin\Domain\Model\PaginationSpec;
use Modules\RestAdmin\Domain\Model\RelationSpec;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Infrastructure\Persistence\Statement;

/**
 * SpecRepository — the specification's rows in `restadmin.db`.
 *
 * The spec is decomposed (services, resources, fields, relations) rather than
 * stored as one blob, because the panel's questions are relational: which
 * resources point at a resource that is gone, which fields render in a list,
 * which relation targets exist. A JSON column would answer them by parsing
 * every document.
 *
 * ## Saving replaces, it does not diff
 *
 * A resource's fields and relations are deleted and re-inserted inside one
 * transaction. Diffing would save a handful of writes on a table that holds
 * tens of rows, and buy a class of bug — a field removed in the form surviving
 * because the diff missed it. The ids are derived from the service id and the
 * name (`rowId()`), so a resource that keeps its name keeps its id across
 * saves, and a renamed one is a new row by definition.
 *
 * ## What this class does NOT do
 *
 * It does not validate. `SpecValidator` is the only door into a
 * `ServiceSpec`, and this repository takes the aggregate — an invalid document
 * cannot reach these inserts. The database's own constraints (unique names,
 * foreign keys) are the second net, not the first.
 */
final readonly class SpecRepository
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * @throws \PDOException when the store refuses the write (a duplicate service name, for one)
     */
    public function save(ServiceSpec $spec): void
    {
        $this->connection->transaction(function (ConnectionInterface $db) use ($spec): void {
            $this->upsertService($db, $spec->service);
            $this->deleteChildren($db, $spec->service->id);

            foreach ($spec->resources as $resource) {
                $this->insertResource($db, $spec->service->id, $resource);
            }
        });
    }

    public function find(string $serviceId): ?ServiceSpec
    {
        $statement = Statement::prepare($this->connection, 'SELECT * FROM services WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $serviceId]);
        $row = $statement->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * The panel's service list: four columns, no specification parsing.
     *
     * @return list<array{id: string, name: string, base_url: string, updated_at: string}>
     */
    public function listServices(): array
    {
        $rows = Statement::query(
            $this->connection,
            'SELECT id, name, base_url, updated_at FROM services ORDER BY name ASC',
        )->fetchAll();

        $services = [];

        foreach ($rows as $row) {
            $services[] = [
                'id'         => (string) ($row['id'] ?? ''),
                'name'       => (string) ($row['name'] ?? ''),
                'base_url'   => (string) ($row['base_url'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $services;
    }

    /**
     * @return list<ServiceSpec>
     */
    public function all(): array
    {
        $specs = [];

        foreach ($this->listServices() as $service) {
            $spec = $this->find($service['id']);

            if (null !== $spec) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * Whether the service existed before the delete — the panel says "removed"
     * or "already gone" from this, not from a silent no-op.
     */
    public function delete(string $serviceId): bool
    {
        $statement = Statement::prepare($this->connection, 'SELECT 1 FROM services WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $serviceId]);

        if (false === $statement->fetch()) {
            return false;
        }

        $this->connection->transaction(function (ConnectionInterface $db) use ($serviceId): void {
            $this->deleteChildren($db, $serviceId);
            Statement::prepare($db, 'DELETE FROM services WHERE id = :id')->execute([':id' => $serviceId]);
        });

        return true;
    }

    private function upsertService(ConnectionInterface $db, ServiceConfig $service): void
    {
        Statement::prepare($db, <<<'SQL'
            INSERT INTO services (id, name, base_url, auth_header, token_env, timeout_seconds, headers)
            VALUES (:id, :name, :base_url, :auth_header, :token_env, :timeout_seconds, :headers)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                base_url = excluded.base_url,
                auth_header = excluded.auth_header,
                token_env = excluded.token_env,
                timeout_seconds = excluded.timeout_seconds,
                headers = excluded.headers,
                updated_at = CURRENT_TIMESTAMP
            SQL)->execute([
                ':id'              => $service->id,
                ':name'            => $service->name,
                ':base_url'        => $service->baseUrl,
                ':auth_header'     => $service->authHeader(),
                ':token_env'       => $service->tokenEnv(),
                ':timeout_seconds' => $service->timeoutSeconds,
                ':headers'         => json_encode($service->headers, JSON_THROW_ON_ERROR),
            ]);
    }

    private function deleteChildren(ConnectionInterface $db, string $serviceId): void
    {
        // Children first: SQLite does not enforce the declared foreign keys
        // unless `PRAGMA foreign_keys` is on, and a repository must not depend
        // on a pragma somebody else's connection set.
        Statement::prepare($db, 'DELETE FROM fields WHERE resource_id IN (SELECT id FROM resources WHERE service_id = :sid)')
            ->execute([':sid' => $serviceId]);
        Statement::prepare($db, 'DELETE FROM relations WHERE resource_id IN (SELECT id FROM resources WHERE service_id = :sid)')
            ->execute([':sid' => $serviceId]);
        Statement::prepare($db, 'DELETE FROM resources WHERE service_id = :sid')
            ->execute([':sid' => $serviceId]);
    }

    private function insertResource(ConnectionInterface $db, string $serviceId, ResourceSpec $resource): void
    {
        $resourceId = $this->rowId($serviceId, 'resource', $resource->name);

        Statement::prepare($db, <<<'SQL'
            INSERT INTO resources (id, service_id, name, path, item_path, id_field, actions, pagination)
            VALUES (:id, :service_id, :name, :path, :item_path, :id_field, :actions, :pagination)
            SQL)->execute([
                ':id'         => $resourceId,
                ':service_id' => $serviceId,
                ':name'       => $resource->name,
                ':path'       => $resource->path,
                ':item_path'  => $resource->itemPath,
                ':id_field'   => $resource->idField,
                ':actions'    => json_encode($resource->actions->toArray(), JSON_THROW_ON_ERROR),
                ':pagination' => $resource->pagination->strategy,
            ]);

        foreach ($resource->fields as $index => $field) {
            $this->insertField($db, $resourceId, $field, $index);
        }

        foreach ($resource->relations as $index => $relation) {
            $this->insertRelation($db, $resourceId, $relation, $index);
        }
    }

    private function insertField(ConnectionInterface $db, string $resourceId, FieldSpec $field, int $index): void
    {
        Statement::prepare($db, <<<'SQL'
            INSERT INTO fields (id, resource_id, name, label, type, is_required, is_read_only, in_list, sort_order)
            VALUES (:id, :resource_id, :name, :label, :type, :is_required, :is_read_only, :in_list, :sort_order)
            SQL)->execute([
                ':id'           => $this->rowId($resourceId, 'field', $field->name),
                ':resource_id'  => $resourceId,
                ':name'         => $field->name,
                ':label'        => $field->label,
                ':type'         => $field->type->value,
                ':is_required'  => $field->required ? 1 : 0,
                ':is_read_only' => $field->readOnly ? 1 : 0,
                ':in_list'      => $field->inList ? 1 : 0,
                ':sort_order'   => $index,
            ]);
    }

    private function insertRelation(ConnectionInterface $db, string $resourceId, RelationSpec $relation, int $index): void
    {
        Statement::prepare($db, <<<'SQL'
            INSERT INTO relations (id, resource_id, name, kind, target_resource, foreign_key, sort_order)
            VALUES (:id, :resource_id, :name, :kind, :target_resource, :foreign_key, :sort_order)
            SQL)->execute([
                ':id'              => $this->rowId($resourceId, 'relation', $relation->name),
                ':resource_id'     => $resourceId,
                ':name'            => $relation->name,
                ':kind'            => $relation->kind,
                ':target_resource' => $relation->target,
                ':foreign_key'     => $relation->foreignKey,
                ':sort_order'      => $index,
            ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ServiceSpec
    {
        $serviceId = (string) ($row['id'] ?? '');

        $service = new ServiceConfig(
            id: $serviceId,
            name: (string) ($row['name'] ?? ''),
            baseUrl: (string) ($row['base_url'] ?? ''),
            auth: new AuthSpec(
                self::nullable($row['auth_header'] ?? null),
                self::nullable($row['token_env'] ?? null),
            ),
            timeoutSeconds: (int) ($row['timeout_seconds'] ?? 10),
            headers: self::headersOf($row['headers'] ?? '{}'),
        );

        $resources = [];
        $resourceStatement = Statement::prepare(
            $this->connection,
            'SELECT * FROM resources WHERE service_id = :sid ORDER BY rowid ASC',
        );
        $resourceStatement->execute([':sid' => $serviceId]);

        foreach ($resourceStatement->fetchAll() as $resourceRow) {
            $resources[] = $this->hydrateResource($resourceRow);
        }

        return new ServiceSpec($service, $resources);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateResource(array $row): ResourceSpec
    {
        $resourceId = (string) ($row['id'] ?? '');
        $actions = json_decode((string) ($row['actions'] ?? '[]'), true);

        $fields = [];
        $fieldStatement = Statement::prepare(
            $this->connection,
            'SELECT * FROM fields WHERE resource_id = :rid ORDER BY sort_order ASC, rowid ASC',
        );
        $fieldStatement->execute([':rid' => $resourceId]);

        foreach ($fieldStatement->fetchAll() as $fieldRow) {
            $fields[] = new FieldSpec(
                name: (string) ($fieldRow['name'] ?? ''),
                label: (string) ($fieldRow['label'] ?? ''),
                type: FieldType::from((string) ($fieldRow['type'] ?? 'text')),
                required: 1 === (int) ($fieldRow['is_required'] ?? 0),
                readOnly: 1 === (int) ($fieldRow['is_read_only'] ?? 0),
                inList: 1 === (int) ($fieldRow['in_list'] ?? 1),
            );
        }

        $relations = [];
        $relationStatement = Statement::prepare(
            $this->connection,
            'SELECT * FROM relations WHERE resource_id = :rid ORDER BY sort_order ASC, rowid ASC',
        );
        $relationStatement->execute([':rid' => $resourceId]);

        foreach ($relationStatement->fetchAll() as $relationRow) {
            $relations[] = new RelationSpec(
                name: (string) ($relationRow['name'] ?? ''),
                kind: (string) ($relationRow['kind'] ?? ''),
                target: (string) ($relationRow['target_resource'] ?? ''),
                foreignKey: self::nullable($relationRow['foreign_key'] ?? null),
            );
        }

        return new ResourceSpec(
            serviceId: (string) ($row['service_id'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            path: (string) ($row['path'] ?? ''),
            itemPath: (string) ($row['item_path'] ?? ''),
            idField: (string) ($row['id_field'] ?? 'id'),
            fields: $fields,
            actions: new ActionSet(is_array($actions) ? array_values($actions) : []),
            pagination: is_string($row['pagination'] ?? null)
                ? new PaginationSpec($row['pagination'])
                : new PaginationSpec(),
            relations: $relations,
        );
    }

    /**
     * Stable row ids: the same service + kind + name keeps the same id across
     * saves, so a resource's fields are replaced under a row that did not
     * change identity. 32 hex characters fit the column.
     */
    private function rowId(string ...$parts): string
    {
        return substr(hash('sha256', implode("\0", $parts)), 0, 32);
    }

    private static function nullable(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $text = (string) $value;

        return '' === $text ? null : $text;
    }

    /**
     * @return array<string, string>
     */
    private static function headersOf(mixed $headers): array
    {
        $decoded = is_string($headers) ? json_decode($headers, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $normalised = [];

        foreach ($decoded as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalised[$name] = $value;
            }
        }

        return $normalised;
    }
}
