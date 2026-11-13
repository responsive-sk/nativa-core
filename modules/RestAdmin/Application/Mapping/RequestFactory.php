<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Infrastructure\Http\HttpRequestSpec;

/**
 * RequestFactory — the URL and headers a resource request is made of.
 *
 * Kept out of the readers because both need it and because it is the part a
 * test can assert without a transport: `?page=2&per_page=25` for a page-shaped
 * collection, the raw cursor for a cursor one, and `{id}` percent-encoded as a
 * path segment so an id containing `/` cannot address a different endpoint.
 *
 * Auth is read from the environment at request time, never stored: a token is
 * `RESTADMIN_<SERVICE>_TOKEN` by the service's own `token_env`, sent under the
 * declared header. An `Authorization` header gets the `Bearer ` scheme; any
 * other header gets the raw value, which is what API-key headers expect.
 * A missing env variable means no header — the service answers 401 and
 * `ErrorMapper` tells the operator which variable to set.
 */
final readonly class RequestFactory
{
    public function __construct(private int $perPage = 25)
    {
    }

    public function list(ServiceConfig $service, ResourceSpec $resource, int $page = 1, ?string $cursor = null): HttpRequestSpec
    {
        $query = [];

        if ('page' === $resource->pagination->strategy) {
            $query['page'] = max(1, $page);
            $query['per_page'] = $this->perPage;
        }

        if ('cursor' === $resource->pagination->strategy && null !== $cursor && '' !== $cursor) {
            $query['cursor'] = $cursor;
        }

        $url = $this->base($service) . $resource->path;

        if ([] !== $query) {
            $url .= '?' . http_build_query($query);
        }

        return new HttpRequestSpec('GET', $url, $this->headers($service));
    }

    public function item(ServiceConfig $service, ResourceSpec $resource, string|int $id): HttpRequestSpec
    {
        $path = str_replace('{id}', rawurlencode((string) $id), $resource->itemPath);

        return new HttpRequestSpec('GET', $this->base($service) . $path, $this->headers($service));
    }

    /**
     * A create is a POST to the collection path, with the form's payload
     * serialised as JSON. The method is the resource's declaration, not a
     * guess: the panel offers the button only when `ActionSet` allows it.
     *
     * @param array<string, mixed> $payload
     */
    public function create(ServiceConfig $service, ResourceSpec $resource, array $payload): HttpRequestSpec
    {
        return HttpRequestSpec::json(
            'POST',
            $this->base($service) . $resource->path,
            $payload,
            $this->headers($service),
        );
    }

    /**
     * An update is a PATCH to the member. PATCH rather than PUT because the
     * panel sends the fields it has, and a PUT would ask an API that treats a
     * missing key as "clear it" to clear the fields the operator never saw.
     *
     * @param array<string, mixed> $payload
     */
    public function update(
        ServiceConfig $service,
        ResourceSpec $resource,
        string|int $id,
        array $payload,
    ): HttpRequestSpec {
        $path = str_replace('{id}', rawurlencode((string) $id), $resource->itemPath);

        return HttpRequestSpec::json(
            'PATCH',
            $this->base($service) . $path,
            $payload,
            $this->headers($service),
        );
    }

    public function delete(ServiceConfig $service, ResourceSpec $resource, string|int $id): HttpRequestSpec
    {
        $path = str_replace('{id}', rawurlencode((string) $id), $resource->itemPath);

        return new HttpRequestSpec('DELETE', $this->base($service) . $path, $this->headers($service));
    }

    /**
     * @return array<string, string>
     */
    public function headers(ServiceConfig $service): array
    {
        $headers = $service->headers;
        $env = $service->tokenEnv();

        if (null === $env || '' === $env) {
            return $headers;
        }

        $token = getenv($env);

        if (!is_string($token) || '' === $token) {
            return $headers;
        }

        $header = $service->authHeader() ?? 'Authorization';
        $headers[$header] = 'Authorization' === $header ? 'Bearer ' . $token : $token;

        return $headers;
    }

    private function base(ServiceConfig $service): string
    {
        return rtrim($service->baseUrl, '/');
    }
}
