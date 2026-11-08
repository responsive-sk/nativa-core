<?php

declare(strict_types = 1);

namespace Modules\Users\Interfaces\HTTP\Actions\Auth;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Users\Application\Services\AuthService;
use Modules\Users\Interfaces\HTTP\AuthServices;

/**
 * Logout action — invalidates the session and remember-me token.
 */
#[Route(path: '/logout', method: 'POST', name: 'auth.logout')]
final class LogoutAction
{
    public function handle(Request $request): Response
    {
        /** @var AuthService $auth */
        $auth = AuthServices::auth();
        // Read BEFORE logout(): afterwards the session no longer knows who
        // signed out, and an unattributed logout is worth much less.
        $actor = $auth->currentUser()?->email() ?? 'anonymous';
        $auth->logout();

        // Same class_exists guard as LoginAction: no Outbox, no audit event,
        // still a working logout.
        if (class_exists('Modules\\Outbox\\Interfaces\\HTTP\\SecurityEventServices')) {
            \Modules\Outbox\Interfaces\HTTP\SecurityEventServices::authAttempt(
                $actor,
                \Modules\Outbox\Domain\SecurityEvents::OUTCOME_LOGOUT,
                $request->getClientIp(),
            );
        }

        return Response::redirect('/login');
    }
}
