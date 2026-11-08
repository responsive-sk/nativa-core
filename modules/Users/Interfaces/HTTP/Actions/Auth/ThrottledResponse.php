<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Response;

/**
 * ThrottledResponse — the one 429 the auth forms answer with.
 *
 * Two POSTs in this module can be rate-limited (registration, finding #4, and
 * reset requests, finding #6). Two refusals written separately is two shapes
 * that can drift, and the details here are the kind that matter:
 *
 *  - **No `Location` header.** A refusal that also redirects has its body
 *    discarded by `Response::send()`, so the client lands somewhere else and
 *    never reads why it was refused. Same shape as the sibling public form
 *    (`QuestionSubmitAction::refusal()`).
 *  - **`Retry-After` always.** The tree's own rate-limit test argues the point:
 *    "a refusal without a retry time is a dead end for a client that speaks
 *    HTTP". The value comes from `RateLimiter::getRetryAfter()`, so it is the
 *    real remaining window rather than a hardcoded guess.
 *
 * The message is per endpoint and never mentions an account: these limits are
 * keyed on what the caller supplied, so the refusal cannot be used to learn
 * whether an address is registered.
 */
final class ThrottledResponse
{
    public static function render(int $retryAfter, string $message): Response
    {
        $response = Response::html(
            '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>Too many requests</title></head>'
            . '<body><h1>Too many requests</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>',
            429,
        );
        $response->setHeader('Retry-After', (string) $retryAfter);

        return $response;
    }
}
