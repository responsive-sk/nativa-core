<?php

declare(strict_types = 1);

namespace Modules\Database\Exceptions;

/**
 * ReadException — every read replica failed, and the primary was not the
 * target.
 *
 * The alternative was to fall back to the primary, which would hide a broken
 * replica behind a working site. The whole point of configuring replicas is
 * that somebody can see they are down, so the read fails loudly and names the
 * replicas it tried.
 */
final class ReadException extends \RuntimeException
{
    /**
     * @param list<string> $failures
     */
    public static function allReplicasFailed(string $name, array $failures): self
    {
        return new self(sprintf(
            'Every read replica failed for "%s": %s',
            $name,
            [] === $failures ? 'no replica configured' : implode('; ', $failures),
        ));
    }
}
