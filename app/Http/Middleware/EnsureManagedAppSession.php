<?php

namespace App\Http\Middleware;

use App\Services\AppClient\ManagedAppSubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagedAppSession
{
    public function __construct(
        private readonly ManagedAppSubscriptionService $managedAppSubscriptionService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolvePersonalAccessToken($request);
        if (!$token || !$token->can(ManagedAppSubscriptionService::TOKEN_ABILITY)) {
            return response()->json([
                'message' => 'Требуется app-session.',
            ], 401, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $session = $this->managedAppSubscriptionService->findSessionForAccessToken($token);
        if (!$session) {
            return response()->json([
                'message' => 'App-session не найдена или уже отозвана.',
            ], 401, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $this->managedAppSubscriptionService->touchSession($session);

        $request->attributes->set('app.managed_session', $session);
        $request->attributes->set('app.managed_access', $session->subscriptionAccess);
        $request->attributes->set('app.managed_subscription', $session->subscriptionAccess?->userSubscription);

        return $next($request);
    }

    private function resolvePersonalAccessToken(Request $request): ?PersonalAccessToken
    {
        $current = $request->user()?->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            return $current;
        }

        $bearer = trim((string) $request->bearerToken());
        if ($bearer === '') {
            return null;
        }

        return PersonalAccessToken::findToken($bearer);
    }
}
