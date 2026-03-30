<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramInboundUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramInboundUpdateService $inbound,
    )
    {
    }

    public function handle(Request $request, string $secret): JsonResponse
    {
        $expected = (string) config('support.telegram.webhook_secret');
        if ($expected === '' || !hash_equals($expected, $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();

        $responsePayload = $this->inbound->handle($payload, true);
        if (is_array($responsePayload)) {
            return response()->json($responsePayload);
        }

        return response()->json(['ok' => true]);
    }
}
