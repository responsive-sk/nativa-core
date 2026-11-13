<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * HttpTransportInterface — the seam every remote REST call goes through.
 *
 * The panel never calls curl directly and never knows whether an answer came
 * from a socket or from a fixture: `FakeTransport` implements this contract
 * too, so a resource can be exercised end to end without a network (plan D4).
 *
 * Implementations MUST run `SsrfGuard` before opening a connection. That is
 * stated in the contract rather than left to the implementation, because a
 * second transport added later is exactly where the guard would be forgotten.
 */
interface HttpTransportInterface
{
    /**
     * Send one request and return the answer, whatever its status.
     *
     * A 4xx or 5xx is an answer, not an exception: the mapping layer turns it
     * into field errors or an operator message. Only a request that could not
     * be made at all (blocked target, transport failure, body over the limit)
     * throws.
     *
     * @throws HttpTransportException when the request cannot be completed
     */
    public function send(HttpRequestSpec $request): HttpResponse;
}
