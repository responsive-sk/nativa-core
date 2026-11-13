<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Infrastructure\Mail\PasswordResetMailerFactory;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Users\Interfaces\HTTP\AuthTemplate;

/**
 * Password reset request — GET renders the "forgot password" form, POST hands
 * a link to the configured mailer (dev log, or none), subject to the reset
 * limits in `PasswordResetService` (per address and per target).
 *
 * The success page names `storage/logs/app.log` only where the link is really
 * there: pointing a production visitor at a log file that holds nothing (or,
 * worse, advertising that one holds tokens) is how the leak was visible in the
 * first place. The two messages come from the same `writesToLog()` the mailer
 * choice comes from, so the page cannot claim a delivery that did not happen.
 */
#[Route(path: '/forgot-password', method: 'GET', name: 'auth.forgot')]
#[Route(path: '/forgot-password', method: 'POST', name: 'auth.forgot.submit', handlerMethod: 'submit')]
final class PasswordResetRequestAction
{
    public function handle(Request $request): Response
    {
        return AuthTemplate::render(
            'Reset password',
            '/forgot-password',
            'Send reset link',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autofocus' => true],
            ],
            [],
            '<a href="/login">Back to sign in</a>',
        );
    }

    public function submit(Request $request): Response
    {
        $email = $request->body('email');

        // Always generate a token (and hand it to the mailer) regardless of
        // whether the account exists — avoids user enumeration. If no account
        // exists, we still show the success message.
        //
        // The one exception is the rate limit (audit finding #6), and it is
        // keyed on the caller's own inputs: the address they connect from and
        // the address they typed. A 429 therefore answers identically for a
        // registered and an unregistered email, so it adds no enumeration
        // oracle to the flow.
        /** @var PasswordResetService $resets */
        $resets = AuthServices::resets();
        $result = $resets->requestReset(is_string($email) ? $email : '', $request->getClientIp());

        if (!$result->ok) {
            return ThrottledResponse::render(
                $result->context['retryAfter'],
                'Too many password reset requests. Please try again later.',
            );
        }

        return AuthTemplate::render(
            'Check your email',
            '/forgot-password',
            'Send another link',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email ?? ''],
            ],
            [],
            '<a href="/login">Back to sign in</a>',
            self::successMessage(),
        );
    }

    /**
     * The neutral success text, with the dev hint only where it is true.
     *
     * Both variants say the same thing about the account: nothing about whether
     * the address exists, so the page cannot be used to enumerate users. The
     * difference is where a developer looks for the link — and in an environment
     * without the dev transport there is nowhere to look, which the message must
     * not pretend otherwise.
     */
    private static function successMessage(): string
    {
        if (PasswordResetMailerFactory::writesToLog()) {
            return 'If an account exists for that email, a reset link has been written to storage/logs/app.log by the dev mailer.';
        }

        return 'If an account exists for that email, a reset link has been sent.';
    }
}
