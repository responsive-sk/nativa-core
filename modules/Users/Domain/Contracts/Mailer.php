<?php

declare(strict_types = 1);

namespace Modules\Users\Domain\Contracts;

/**
 * Mailer — the kernel's port for one plain-text message to one address.
 *
 * The tree already had a mail transport, but it was owned by `Users`: a port
 * whose method was `sendResetLink(string $email, string $token, int $ttl)`, so
 * the ONLY message any deployment could deliver was a password reset. A module
 * that wants to tell the operator "a comment is waiting for you" could not ask
 * for it without importing the account module and its reset-specific contract.
 *
 * This port is the general shape — recipient, subject, body — and the message
 * itself stays the caller's business. Delivery policy is the transport's:
 * `HttpMailer` posts the deployment's JSON contract, `DevLogMailer` appends to
 * the log a developer already reads, and `NullMailer` is a real answer for a
 * deployment with no transport at all. `MailerFactory` is the one place that
 * chooses, by environment, exactly like `PasswordResetMailerFactory` does for
 * the reset link.
 *
 * **Never throws.** A caller is typically a background job or a reader's
 * request; an unreachable SMTP host is a log line, not a 500 and not a failed
 * comment submission. The boolean says whether the transport accepted the
 * message, which is what a job handler reports — it is not a promise of
 * delivery to a human inbox (no synchronous transport can promise that).
 */
interface Mailer
{
    /**
     * Deliver one plain-text message.
     *
     * `$text` is raw text, not HTML: the tree has no template engine for mail
     * and a notification is a paragraph, not a layout. Callers must not put a
     * credential in the body — the dev transport writes it to disk.
     *
     * @return bool true when the transport accepted the message
     */
    public function send(string $to, string $subject, string $text): bool;
}
