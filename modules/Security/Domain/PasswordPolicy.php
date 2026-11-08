<?php

declare(strict_types = 1);

namespace Modules\Security\Domain;

/**
 * PasswordPolicy — the ONE place that says how long a password must be.
 *
 * The number used to be three literals — `min:8` in `AuthService::register()`,
 * `min:8` again in `PasswordResetService::reset()`, and a third `min:8` in the
 * admin user form (`Cardboard\…\UsersAction`) — plus the copy stated in prose in
 * `lekalo/modules/users/policies.yaml`. Four copies of one policy is four
 * chances for the admin form to keep accepting what registration refuses, and
 * the audit that found this had to state the number itself to describe it.
 *
 * 12 is the OWASP ASVS 2.1.1 baseline (the number this repo's own security
 * checklist calls for). Length is the ONLY rule owned here, on purpose: NIST SP
 * 800-63B and this tree's dislike of decorative controls agree that a length
 * floor is what a policy can honestly demand, while character-class rules buy
 * lockouts and a support burden without moving the needle.
 *
 * ## Why the Security module and not Users
 *
 * Both consumers already depend on Security: `Users`' closure is
 * `Cardboard, Locales, Outbox, Security, Validation`, and `Cardboard`'s is
 * `Outbox, Security, Settings, Validation` — Users is NOT in Cardboard's, even
 * though Cardboard reaches Users classes through inline FQCNs and
 * `CardboardServices::need()`. A policy both sides can reach without adding an
 * edge belongs where the edge already is; putting it in Users would have meant
 * either a new visible coupling or one more invisible one.
 *
 * `@internal`: the rule fragment and the message are for the three call sites,
 * not a public API.
 */
final class PasswordPolicy
{
    /** Minimum password length in characters (OWASP ASVS 2.1.1). */
    public const int MIN_LENGTH = 12;

    /**
     * The validation fragment a call site composes into its own rule, so the
     * number is typed once: `'required|' . PasswordPolicy::minRule() . '|confirmed'`.
     */
    public static function minRule(): string
    {
        return 'min:' . self::MIN_LENGTH;
    }

    /**
     * The message for a password under the minimum.
     *
     * One string serves both the `required` and the `min` key, exactly as the
     * legacy literals did: an empty password reports the minimum via `required`,
     * which is the behaviour every existing assertion pins.
     */
    public static function tooShortMessage(): string
    {
        return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
    }
}
