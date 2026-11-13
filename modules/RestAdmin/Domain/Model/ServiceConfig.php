<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Domain\Model;

/**
 * ServiceConfig — one remote REST service the panel may talk to.
 *
 * The token is NEVER stored here: `auth.tokenEnv` names the environment
 * variable that holds it, so a spec written to `restadmin.db` cannot leak a
 * credential. The nativa kernel has no crypto port yet, which is why the
 * secret side of this contract is an env name rather than a value (plan D5).
 *
 * `baseUrl` is a base, never a full endpoint: resource paths are resolved
 * against it, and the SSRF guard in the transport checks the result.
 */
final readonly class ServiceConfig
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $baseUrl,
        public AuthSpec $auth = new AuthSpec(),
        public int $timeoutSeconds = 10,
        public array $headers = [],
    ) {
    }

    public function authHeader(): ?string
    {
        return $this->auth->header;
    }

    public function tokenEnv(): ?string
    {
        return $this->auth->tokenEnv;
    }

    /**
     * The shape stored in `services` and in the JSON snapshot. `auth` is one
     * object rather than two loose keys, so a header can never be written
     * without the variable that fills it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'base_url'        => $this->baseUrl,
            'auth'            => $this->auth->toArray(),
            'timeout_seconds' => $this->timeoutSeconds,
            'headers'         => $this->headers,
        ];
    }
}
