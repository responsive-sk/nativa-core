<?php

declare(strict_types = 1);

namespace Modules\Database\Infrastructure\Persistence;

use Modules\Database\Contracts\ConnectionInterface;

/**
 * ReplicaSelector — which replica serves the next read.
 *
 * Two strategies, both cheap enough to run per query:
 *
 *  - `random` spreads reads evenly without state, which is what a pool of
 *    identical replicas wants;
 *  - `weighted` takes `DB_<NAME>_READ_WEIGHTS` (comma separated, per replica)
 *    for the case where one replica is bigger. Weights are keyed by connection
 *    name, not by index, so a replica dropped during failover does not shift
 *    everybody else's weight.
 *
 * The selector never removes anything: ReadWriteConnection passes the still
 * usable replicas and drops the failed one itself.
 */
final readonly class ReplicaSelector
{
    /**
     * @param array<string, int> $weights connection name => weight
     */
    public function __construct(
        private string $strategy = 'random',
        private array $weights = [],
    ) {
    }

    /**
     * @param list<ConnectionInterface> $replicas
     */
    public function select(array $replicas): ConnectionInterface
    {
        if ([] === $replicas) {
            // Callers check for this before routing; the throw documents the
            // invariant instead of letting `random_int(0, -1)` fail obscurely.
            throw new \LogicException('ReplicaSelector::select() needs at least one replica.');
        }

        if ('weighted' !== $this->strategy) {
            return $replicas[random_int(0, \count($replicas) - 1)];
        }

        $weights = [];
        foreach ($replicas as $replica) {
            $weights[$replica->name()] = max(0, $this->weights[$replica->name()] ?? 1);
        }

        $total = array_sum($weights);
        if ($total <= 0) {
            return $replicas[random_int(0, \count($replicas) - 1)];
        }

        $ticket = random_int(1, $total);
        foreach ($replicas as $replica) {
            $ticket -= $weights[$replica->name()];
            if ($ticket <= 0) {
                return $replica;
            }
        }

        return $replicas[\count($replicas) - 1];
    }
}
