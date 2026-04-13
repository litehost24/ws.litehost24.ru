<?php

namespace App\Http\Controllers;

use App\Models\UserSubscription;
use App\Services\AppClient\ManagedAppSubscriptionService;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class AppSubscriptionInviteController extends Controller
{
    public function store(
        UserSubscription $userSubscription,
        ManagedAppSubscriptionService $service,
        QrCodeService $qrCodeService,
    ): JsonResponse
    {
        $this->assertActorCanManageSubscription($userSubscription);

        try {
            $issued = $service->issueInvite($userSubscription, Auth::user());
            $invite = $issued['invite'];
            $rawToken = $issued['raw_token'];
            $openUrl = $service->inviteOpenUrl($rawToken);

            return response()->json([
                'ok' => true,
                'invite' => [
                    'token' => $rawToken,
                    'code' => $rawToken,
                    'open_url' => $openUrl,
                    'qr_data_uri' => $qrCodeService->makeDataUri($openUrl, 280),
                    'expires_at' => $invite->expires_at?->toIso8601String(),
                ],
            ], 201, [], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            Log::error('App invite issue failed', [
                'user_subscription_id' => (int) $userSubscription->id,
                'user_id' => (int) Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось подготовить код привязки. Попробуйте ещё раз через минуту.',
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    public function revoke(UserSubscription $userSubscription, ManagedAppSubscriptionService $service): JsonResponse
    {
        $this->assertActorCanManageSubscription($userSubscription);

        $revoked = $service->revokeOutstandingInvites($userSubscription);

        return response()->json([
            'ok' => true,
            'revoked' => $revoked,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function assertActorCanManageSubscription(UserSubscription $userSubscription): void
    {
        $user = Auth::user();
        abort_unless($user, 401);

        if (!in_array((string) $user->role, ['user', 'admin', 'partner'], true)) {
            abort(403);
        }

        if ((int) $userSubscription->user_id !== (int) $user->id) {
            abort(404);
        }
    }
}
