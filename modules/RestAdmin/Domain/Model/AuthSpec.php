<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * AuthSpec — how a service's token reaches the wire.
 *
 * A token is never a value in this model: `tokenEnv` names the environment
 * variable, and the transport reads it at call time (plan D5 — the kernel has
 * no crypto port, so storing the secret would mean storing it in plaintext).
 *
 * `header` is null for a service that needs no token. The validator fills in
 * `Authorization` when a token env is present and no header was chosen, so the
 * common case is one line of configuration rather than two.
 */
final readonly class AuthSpec
{
    public function __construct(
        public ?string $header = null,
        public ?string $tokenEnv = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->tokenEnv && '' !== $this->tokenEnv;
    }

    /**
     * @return array{header: ?string, token_env: ?string}
     */
    public function toArray(): array
    {
        return [
            'header'    => $this->header,
            'token_env' => $this->tokenEnv,
        ];
    }
}
