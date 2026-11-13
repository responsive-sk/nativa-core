<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Infrastructure\Http;

/**
 * FakeTransport — answers from a queue, records what was asked.
 *
 * The module's tests must not call the internet: a suite that depends on a
 * live API is a suite that fails when the API is down, and a fixture that
 * describes the API's current answer is the only way to assert what the panel
 * renders from it (plan D4, F6).
 *
 * Queueing a `Throwable` replays a failure — `new FakeTransport($exception)` —
 * so a caller's error path is exercised without inventing a broken socket.
 * An empty queue is a test mistake, not a transport failure, and says so.
 */
final class FakeTransport implements HttpTransportInterface
{
    /** @var list<HttpRequestSpec> */
    private array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queued;

    public function __construct(HttpResponse|\Throwable ...$queued)
    {
        $this->queued = array_values($queued);
    }

    public function send(HttpRequestSpec $request): HttpResponse
    {
        $this->requests[] = $request;

        if ([] === $this->queued) {
            throw new \LogicException(
                'FakeTransport has no answer queued for this request — the test forgot to add one.',
            );
        }

        $next = array_shift($this->queued);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<HttpRequestSpec>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): HttpRequestSpec
    {
        $last = end($this->requests);

        if (false === $last) {
            throw new \LogicException('FakeTransport has not been asked anything yet.');
        }

        return $last;
    }

    /**
     * What is still queued — a test asserting "exactly one call happened"
     * checks this is empty rather than counting.
     *
     * @return list<HttpResponse|\Throwable>
     */
    public function remaining(): array
    {
        return $this->queued;
    }
}
