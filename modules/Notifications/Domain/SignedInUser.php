<?php

declare(strict_types = 1);

namespace Modules\Notifications\Domain;

/**
 * SignedInUser — whose inbox a request may see.
 *
 * One owner for the question, because the inbox asks it twice (the sidebar
 * badge on every `/mark` page, and the inbox page itself) and two readings of
 * "who is this" are two different answers waiting to happen.
 *
 * The answer is `$_SESSION['auth']['user_id']` — the exact key the module that
 * owns sessions writes (`AuthService`) and the kernel's own shell reads
 * (`FrontendLayout::sessionHasAuth()`, `SettingsShellContextProvider`). The auth
 * PORT is not asked: its `identity()` wants a `Request`, and the badge renders
 * from a layout that never had one.
 *
 * This class starts no session and checks no cookie. Reading is the only side
 * effect a badge may have: on a page reached without a session `$_SESSION` is
 * empty and the answer is "nobody", which for `/mark` the auth middleware has
 * already refused long before a layout renders. Starting one here (as the shell
 * provider has to, because it also filters public pages) would hand a session to
 * a reader who never logged in.
 */
final class SignedInUser
{
    public static function id(): ?string
    {
        $id = $_SESSION['auth']['user_id'] ?? null;

        return is_string($id) && '' !== $id ? $id : null;
    }
}
