<?php

declare(strict_types = 1);

namespace Modules\Core\Domain;

/**
 * SubmitResult — the answer to "did the write happen, and if not, why".
 *
 * The shape this replaces was `array{ok: bool, errors: list<string>}`, and the
 * defect was not the array: it was that the invariant "`ok === true` ⇒ `errors`
 * is empty" lived in the callers' heads. A hand-built array can say `ok => true`
 * next to an error and nothing notices; here the only ways in are `success()`
 * and `failure()`, so the two halves cannot disagree.
 *
 * ## What `context` is, and what it is not
 *
 * A result often carries facts the caller needs to act on the decision: the
 * clean path a folder was created at, how many rows a rename moved, how many
 * seconds until a throttled reader may retry. Those are not a second status —
 * the status is `ok` — and they are not errors. They travel in `context`,
 * typed by the method that built the result, so the caller reads a shape
 * rather than a bag: `@return SubmitResult<array{retryAfter: int}>`.
 *
 * The article this class closes (`storage/blog/boolean-nestaci-a-predsa-staci.md`,
 * part VI) called the array "a Result wearing a coat". This is the type: one
 * `bool` for the question, one `list<string>` for the reason, and a generic
 * context for the numbers and names the caller was going to read anyway.
 *
 * ## It stays on the caller's side of the boundary
 *
 * A `SubmitResult` is never stored in a database, a queue payload or a session.
 * Its `ok` is a decision, not a value — the moment it is persisted it starts
 * lying about a past that can no longer be re-read. What crosses a process
 * boundary is the vocabulary (`Comment::STATUS_*`, `HandlerRegistry`, the
 * `value_type` of a setting), never this object.
 *
 * @template TContext of array<string, mixed>
 */
final readonly class SubmitResult
{
    /**
     * @param list<string> $errors
     * @param TContext     $context
     */
    private function __construct(public bool $ok, public array $errors, public array $context)
    {
    }

    /**
     * The write happened.
     *
     * @template TSuccess of array<string, mixed>
     *
     * @param TSuccess $context
     *
     * @return self<TSuccess>
     */
    public static function success(array $context = []): self
    {
        return new self(true, [], $context);
    }

    /**
     * The write did not happen, for the stated reason. At least one message is
     * required — a refusal with no reason is the failure mode this class exists
     * to make unrepresentable.
     *
     * @template TFailure of array<string, mixed>
     *
     * @param string|list<string> $errors
     * @param TFailure            $context
     *
     * @return self<TFailure>
     */
    public static function failure(string|array $errors, array $context = []): self
    {
        $messages = is_string($errors) ? [$errors] : $errors;

        if ([] === $messages) {
            throw new \InvalidArgumentException(
                'A failed SubmitResult needs a message: a refusal without a reason cannot be acted on.',
            );
        }

        return new self(false, $messages, $context);
    }

    /**
     * The first reason the write was refused, or `''` when it was not.
     *
     * For the callers that render one line — a form field's message — while the
     * full list stays on `errors` for the ones that render all of them.
     */
    public function error(): string
    {
        return $this->errors[0] ?? '';
    }
}
