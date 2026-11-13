<?php

declare(strict_types = 1);

namespace Modules\Users\Domain\Contracts;

/**
 * PasswordResetMailer — the port that owns delivery of a reset link.
 *
 * The token used to be written by `PasswordResetService` itself, unconditionally:
 * one `file_put_contents(storage/logs/app.log)` on every request, in every
 * environment, so a deployment that never received a real reset link still kept
 * a valid plaintext token for a live account on disk (audit finding #1 — read
 * the log, take the account). The service was the wrong owner for that decision:
 * what it knows is the token row (stored hashed) and the neutral answer it
 * returns, which is what keeps the flow non-enumerable. WHERE a link may travel
 * is an environment fact, and this port is where it is asked.
 *
 * **Absence is an answer.** `NullPasswordResetMailer` is an implementation, not
 * a missing one: with no mailer configured the link goes nowhere, the answer
 * stays exactly as neutral as before, and nothing is written to disk. A token
 * that was never delivered is recoverable by an operator; a token in a log file
 * is recoverable by whoever can read the log.
 *
 * Implementations are chosen in ONE place — `PasswordResetMailerFactory`, by
 * `APP_ENV` — so an SMTP or API transport drops in without this interface, the
 * service, the action or the tests learning which environment they run in.
 */
interface PasswordResetMailer
{
    /**
     * Deliver a reset link for `$email`, carrying `$token` verbatim.
     *
     * Implementations must not throw. A failed delivery is not the requester's
     * business: an error for one address and a neutral page for another is
     * precisely the enumeration oracle this flow exists to avoid, so the
     * response is identical either way and a failure belongs in a log.
     *
     * Returning `void` is deliberate — the token does not travel back out of
     * this port, so no caller can decide to record it somewhere of its own.
     */
    public function sendResetLink(string $email, #[\SensitiveParameter] string $token, int $ttlHours): void;
}
