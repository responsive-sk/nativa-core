<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Application\Mapping;

use Modules\RestAdmin\Infrastructure\Http\HttpResponse;

/**
 * ErrorMapper — an HTTP failure into a kind, a sentence and field messages.
 *
 * The panel is an operator surface, not a proxy: a remote HTML error page or a
 * stack trace in a JSON body must never reach the page. So a message is chosen
 * by status first (the operator's next action is "check the token", not "read
 * this HTML"), and an upstream `message` is used only where it adds something —
 * a 409 conflict, a 422 field list, a 429 retry delay.
 *
 * 422 is the one status with structure worth preserving. Three shapes are
 * recognised, because the examples this module was designed against use them:
 *
 * - Laravel: `{"errors": {"title": ["required"]}}` and Django's flat
 *   `{"title": ["required"]}` (no `errors` key);
 * - a list of objects: `{"errors": [{"field": "title", "message": "required"}]}`
 *   (the shop example);
 * - a list of strings, which has no field to attach to and lands on `_general`.
 *
 * A 422 with none of those still gets a message: a form that says nothing is a
 * form the operator submits twice.
 */
final readonly class ErrorMapper
{
    public function map(HttpResponse $response): ApiError
    {
        $payload = $this->decode($response->body);
        $message = $this->message($response, $payload);
        $fieldErrors = 422 === $response->status ? $this->fieldErrors($payload) : [];

        if ([] === $fieldErrors && 422 === $response->status) {
            $fieldErrors['_general'] = [$message];
        }

        return new ApiError(
            status: $response->status,
            kind: $this->kind($response->status),
            message: $message,
            fieldErrors: $fieldErrors,
        );
    }

    private function kind(int $status): string
    {
        return match (true) {
            401 === $status => 'auth',
            403 === $status => 'forbidden',
            404 === $status => 'not_found',
            409 === $status => 'conflict',
            422 === $status => 'validation',
            429 === $status => 'rate_limit',
            $status >= 500 => 'server',
            default => 'client',
        };
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function message(HttpResponse $response, ?array $payload): string
    {
        $upstream = $this->upstreamMessage($payload);

        return match (true) {
            401 === $response->status => 'The service rejected the token. Check the env variable named in this service\'s auth settings.',
            403 === $response->status => 'The service accepted the token but refused this action.',
            404 === $response->status => 'The item or collection no longer exists upstream.',
            429 === $response->status => $this->rateLimitMessage($response),
            $response->status >= 500 => sprintf(
                'The service failed (HTTP %d). Its body is not shown because it may contain internals.',
                $response->status,
            ),
            null !== $upstream => $upstream,
            422 === $response->status => 'The service rejected these fields.',
            default => sprintf('The service answered HTTP %d.', $response->status),
        };
    }

    private function rateLimitMessage(HttpResponse $response): string
    {
        $retryAfter = $response->header('Retry-After');

        return is_string($retryAfter) && '' !== trim($retryAfter)
            ? sprintf('The service is rate limiting this panel; retry after %s seconds.', trim($retryAfter))
            : 'The service is rate limiting this panel; retry shortly.';
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function upstreamMessage(?array $payload): ?string
    {
        if (null === $payload) {
            return null;
        }

        foreach (['message', 'detail', 'error_description', 'error'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                return $this->excerpt($value);
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function fieldErrors(?array $payload): array
    {
        if (null === $payload) {
            return [];
        }

        $raw = $payload['errors'] ?? $payload;

        if (!is_array($raw)) {
            return [];
        }

        $errors = [];

        if (array_is_list($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry)) {
                    $errors['_general'][] = $this->excerpt($entry);

                    continue;
                }

                if (!is_array($entry)) {
                    continue;
                }

                $field = $entry['field'] ?? $entry['attr'] ?? $entry['source'] ?? '_general';
                $message = $entry['message'] ?? $entry['detail'] ?? $entry['msg'] ?? null;
                $field = is_string($field) && '' !== $field ? $field : '_general';

                if (is_string($message) && '' !== trim($message)) {
                    $errors[$field][] = $this->excerpt($message);
                }
            }

            return $errors;
        }

        foreach ($raw as $field => $messages) {
            // The flat Django shape is the whole body, so the body's own
            // prose keys are not fields: a 422 that only says "Validation
            // Error" has no field to attach it to.
            if (!is_string($field) || in_array($field, ['message', 'detail', 'error', 'error_description', 'status'], true)) {
                continue;
            }

            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                if (is_string($message) && '' !== trim($message)) {
                    $errors[$field][] = $this->excerpt($message);
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $body): ?array
    {
        if ('' === trim($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function excerpt(string $message): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return strlen($singleLine) > 300 ? substr($singleLine, 0, 300) . '…' : $singleLine;
    }
}
