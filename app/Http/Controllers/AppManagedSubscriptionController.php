<?php

namespace App\Http\Controllers;

use App\Models\AppDeviceSession;
use App\Services\AppClient\ManagedAppSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppManagedSubscriptionController extends Controller
{
    public function show(Request $request, ManagedAppSubscriptionService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'subscription' => $service->subscriptionPayload($this->session($request)),
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function manifest(Request $request, ManagedAppSubscriptionService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'manifest' => $service->manifestPayload($this->session($request)),
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function config(Request $request, ManagedAppSubscriptionService $service): Response
    {
        $config = $service->configForSession($this->session($request));

        return response($config, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="peer-1-amneziawg.conf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function unbind(Request $request, ManagedAppSubscriptionService $service): JsonResponse
    {
        $service->unbindSession($this->session($request));

        return response()->json([
            'ok' => true,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function session(Request $request): AppDeviceSession
    {
        /** @var AppDeviceSession|null $session */
        $session = $request->attributes->get('app.managed_session');
        abort_unless($session, 401);

        return $session;
    }
}
