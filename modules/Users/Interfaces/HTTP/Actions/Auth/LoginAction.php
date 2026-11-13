<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Domain\AdminRoutePolicy;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Interfaces\HTTP\AuthServices;
use Modules\Users\Interfaces\HTTP\AuthTemplate;

/**
 * Login action — GET renders the form, POST authenticates.
 */
#[Route(path: '/login', method: 'GET', name: 'auth.login')]
#[Route(path: '/login', method: 'POST', name: 'auth.login.submit', handlerMethod: 'submit')]
final class LoginAction
{
    public function handle(Request $request): Response
    {
        $returnTo = AuthTemplate::returnTo($request);

        return AuthTemplate::render(
            'Sign in',
            '/login',
            'Sign in',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'autofocus' => true],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
                ['name' => 'remember', 'label' => 'Remember me', 'type' => 'checkbox', 'required' => false],
            ],
            [],
            'No account yet? <a href="/register">Create one</a>',
            returnTo: $returnTo,
        );
    }

    public function submit(Request $request): Response
    {
        $email = $request->body('email');
        $password = $request->body('password');
        $remember = in_array($request->body('remember'), ['on', '1', 'true', true], true);

        /** @var AuthService $auth */
        $auth = AuthServices::auth();
        $normalized = is_string($email) ? $email : '';
        $result = $auth->login(
            $normalized,
            is_string($password) ? $password : '',
            $remember,
            $request->getClientIp(),
        );

        // Recorded here rather than inside AuthService: the authentication
        // service must not know that a security-event pipeline exists, or the
        // Users module takes a dependency on the Outbox it has no business
        // having. The HTTP layer is where the two may meet — guarded by
        // class_exists (not a use-statement) so the meeting leaves no edge
        // in the closure: without the Outbox the login still works, only the
        // audit event is dropped.
        if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
            \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::authAttempt(
                $normalized,
                null === $result['user']
                    ? \Modules\Outbox\Domain\SecurityEvents::OUTCOME_FAILURE
                    : \Modules\Outbox\Domain\SecurityEvents::OUTCOME_SUCCESS,
                $request->getClientIp(),
            );
        }

        if (null === $result['user']) {
            return AuthTemplate::render(
                'Sign in',
                '/login',
                'Sign in',
                [
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $email ?? ''],
                    ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
                    ['name' => 'remember', 'label' => 'Remember me', 'type' => 'checkbox', 'required' => false],
                ],
                $result['errors'],
                returnTo: AuthTemplate::returnTo($request),
            );
        }

        $auth->establishSession($result['user'], $request->getClientIp());

        return Response::redirect(AuthTemplate::returnTo($request) ?? AdminRoutePolicy::prefix());
    }
}
