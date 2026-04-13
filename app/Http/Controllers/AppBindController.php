<?php

namespace App\Http\Controllers;

use App\Services\AppClient\ManagedAppSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppBindController extends Controller
{
    public function store(Request $request, ManagedAppSubscriptionService $service): JsonResponse
    {
        $data = $request->validate([
            'invite_token' => ['required', 'string', 'max:255'],
            'device_uuid' => ['required', 'string', 'max:128'],
            'platform' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $service->bind((string) $data['invite_token'], $data);

        return response()->json($result, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
