<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

use Modules\RestAdmin\Application\Mapping\Pagination\CursorStrategy;
use Modules\RestAdmin\Application\Mapping\Pagination\LinkHeaderStrategy;
use Modules\RestAdmin\Application\Mapping\Pagination\PageState;
use Modules\RestAdmin\Application\Mapping\Pagination\PageStrategy;
use Modules\RestAdmin\Domain\Model\ResourceSpec;
use Modules\RestAdmin\Domain\Model\ServiceConfig;
use Modules\RestAdmin\Infrastructure\Http\HttpResponse;
use Modules\RestAdmin\Infrastructure\Http\HttpTransportInterface;

/**
 * ListReader — one collection request, from URL to rows and a page state.
 *
 * This is where the promise that the same resource works against a Laravel
 * paginator, a Link-header API and a cursor feed is kept: the resource's
 * `pagination` names the strategy, the `Envelope` finds the rows, and the
 * reader owns the three things every answer shares — the request, the non-2xx
 * mapping and the JSON decode.
 *
 * A 2xx that is not JSON, or JSON with no rows, is a `MappingException`, not an
 * `ApiException`: the service answered, the operator's next step is to look at
 * the service, and the two failures lead to different screens.
 *
 * The strategy is chosen per call from the spec rather than injected once, so a
 * panel can hold resources that paginate differently.
 */
final readonly class ListReader
{
    public function __construct(
        private HttpTransportInterface $transport,
        private Envelope $envelope = new Envelope(),
        private ErrorMapper $errors = new ErrorMapper(),
        private RequestFactory $requests = new RequestFactory(),
    ) {
    }

    /**
     * @throws ApiException     when the service answers non-2xx
     * @throws MappingException when a 2xx body is not a collection this panel reads
     */
    public function read(ServiceConfig $service, ResourceSpec $resource, int $page = 1, ?string $cursor = null): ListPage
    {
        $response = $this->transport->send($this->requests->list($service, $resource, $page, $cursor));

        if (!$response->isSuccess()) {
            throw new ApiException($this->errors->map($response));
        }

        $payload = $this->decode($response);
        $items = $this->envelope->items($payload);

        return new ListPage($items, $this->state($resource, $payload, $response, count($items)), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function state(ResourceSpec $resource, array $payload, HttpResponse $response, int $count): PageState
    {
        return match ($resource->pagination->strategy) {
            'link' => (new LinkHeaderStrategy())->state($payload, $response->headers),
            'cursor' => (new CursorStrategy())->state($payload, $response->headers),
            'none' => new PageState(total: $count, hasNext: false),
            default => (new PageStrategy())->state($payload, $response->headers),
        };
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
