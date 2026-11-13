<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use Modules\RestAdmin\Infrastructure\Http\HttpTransportInterface;

/**
 * ItemReader — one member of a collection, by id.
 *
 * The id goes through `RequestFactory`, which percent-encodes it as a path
 * segment: an id containing `/` or `?` must address the same member the list
 * showed, not a different endpoint.
 *
 * The answer is returned as the raw object from the envelope — attributes
 * included, JSON:API `attributes` untouched — because `FieldMapper` is the one
 * place that decides how a field name is read. Flattening here would hide the
 * difference between "the API sent null" and "the API did not send it".
 */
final readonly class ItemReader
{
    public function __construct(
        private HttpTransportInterface $transport,
        private Envelope $envelope = new Envelope(),
        private ErrorMapper $errors = new ErrorMapper(),
        private RequestFactory $requests = new RequestFactory(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException     when the service answers non-2xx
     * @throws MappingException when a 2xx body is not a JSON object
     */
    public function read(ServiceConfig $service, ResourceSpec $resource, string|int $id): array
    {
        $response = $this->transport->send($this->requests->item($service, $resource, $id));

        if (!$response->isSuccess()) {
            throw new ApiException($this->errors->map($response));
        }

        return $this->envelope->item($this->decode($response));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);

        if (!is_array($decoded)) {
            throw new MappingException(sprintf(
                'The service answered HTTP %d with a body that is not a JSON object.',
                $response->status,
            ));
        }

        return $decoded;
    }
}
