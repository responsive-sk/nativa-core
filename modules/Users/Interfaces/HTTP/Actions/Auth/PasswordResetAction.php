<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\PasswordResetService;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Users\Interfaces\HTTP\AuthTemplate;

/**
 * Password reset — GET renders the reset form (token from query string),
 * POST validates the token and updates the password.
 */
#[Route(path: '/reset-password', method: 'GET', name: 'auth.reset')]
#[Route(path: '/reset-password', method: 'POST', name: 'auth.reset.submit', handlerMethod: 'submit')]
final class PasswordResetAction
{
    public function handle(Request $request): Response
    {
        $token = $request->query('token');

        if (!is_string($token) || '' === $token) {
            return Response::redirect('/forgot-password');
        }

        return $this->form($token, []);
    }

    public function submit(Request $request): Response
    {
        $token = $request->body('token');
        $password = $request->body('password');
        $confirmation = $request->body('password_confirmation');

        if (!is_string($token) || '' === $token) {
            return Response::redirect('/forgot-password');
        }

        /** @var PasswordResetService $resets */
        $resets = AuthServices::resets();
        $errors = $resets->reset(
            $token,
            is_string($password) ? $password : '',
            is_string($confirmation) ? $confirmation : '',
        );

        if ([] !== $errors) {
            return $this->form($token, $errors);
        }

        return AuthTemplate::render(
            'Password updated',
            '/login',
            'Sign in',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autofocus' => true],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
            ],
            [],
            null,
            'Your password has been updated. You can now sign in.',
        );
    }

    /**
     * @param list<string> $errors
     */
    private function form(#[\SensitiveParameter] string $token, array $errors): Response
    {
        return AuthTemplate::render(
            'Choose a new password',
            '/reset-password',
            'Update password',
            [
                ['name' => 'token', 'type' => 'hidden', 'value' => $token],
                ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'autofocus' => true],
                ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password'],
            ],
            $errors,
            '<a href="/login">Back to sign in</a>',
        );
    }
}
