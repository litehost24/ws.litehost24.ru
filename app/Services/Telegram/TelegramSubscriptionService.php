<?php

namespace App\Services\Telegram;

use App\Models\components\Balance;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\components\FullConnectSubscription;
use App\Services\ReferralPricingService;
use App\Services\VpnPlanCatalog;
use Illuminate\Support\Facades\Auth;

class TelegramSubscriptionService
{
    public function getNextVpnPriceRub(User $user): ?int
    {
        $prev = Auth::user();
        Auth::setUser($user);
        try {
            $vpnSub = Subscription::nextAvailableVpnForUser((int) $user->id);
            if (!$vpnSub) {
                return null;
            }

            $catalog = app(VpnPlanCatalog::class);
            $planCode = $catalog->defaultPurchasePlanCode();
            $pricing = app(ReferralPricingService::class);
            $referrer = $user->referrer;
            $basePrice = $catalog->resolveBasePriceCents($vpnSub, $planCode);
            $finalPrice = $pricing->getFinalPriceCents($vpnSub, $referrer, $user, $basePrice);
            return (int) ($finalPrice / 100);
        } finally {
            if ($prev) {
                Auth::setUser($prev);
            } else {
                // In stateless contexts (webhook/tests) there may be no authenticated user to restore.
                try {
                    Auth::logout();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }

    /**
     * @return array{ok: bool, message?: string, balance_rub?: int, end_date?: string, file_url?: string, file_path?: string, user_subscription_id?: int}
     */
    public function buyVpn(User $user, ?string $note = null): array
    {
        if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
            return ['ok' => false, 'message' => 'Покупка доступна только по реферальной ссылке клиента.'];
        }

        $prev = Auth::user();
        Auth::setUser($user);
        try {
            $sub = Subscription::nextAvailableVpnForUser((int) $user->id);
            if (!$sub) {
                return ['ok' => false, 'message' => 'VPN-подписки не найдены.'];
            }

            $catalog = app(VpnPlanCatalog::class);
            $planCode = $catalog->defaultPurchasePlanCode();
            $pricing = app(ReferralPricingService::class);
            $referrer = $user->referrer;
            $basePrice = $catalog->resolveBasePriceCents($sub, $planCode);
            $finalPrice = $pricing->getFinalPriceCents($sub, $referrer, $user, $basePrice);

            $balanceCents = (new Balance)->getBalance($user->id);
            if ($balanceCents < (int) $finalPrice) {
                return ['ok' => false, 'message' => 'Недостаточно средств. Сначала пополните баланс.'];
            }

            (new FullConnectSubscription($sub, $note, Server::VPN_ACCESS_WHITE_IP, $planCode))->create();

            $row = UserSubscription::query()
                ->where('user_id', $user->id)
                ->where('subscription_id', $sub->id)
                ->orderBy('id', 'desc')
                ->first();

            $fileUrl = null;
            $filePath = (string) ($row?->file_path ?? '');
            if ($filePath !== '') {
                $siteUrl = rtrim((string) config('app.url'), '/');
                $fileUrl = $siteUrl . '/storage/' . ltrim($filePath, '/');
            }

            return [
                'ok' => true,
                'balance_rub' => (int) (new Balance)->getBalanceRub($user->id),
                'end_date' => $row?->end_date ? (string) $row->end_date : null,
                'file_url' => $fileUrl,
                'file_path' => $filePath !== '' ? $filePath : null,
                'user_subscription_id' => $row?->id ? (int) $row->id : null,
            ];
        } catch (\Throwable $e) {
            \Log::error('Telegram buyVpn failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Ошибка при покупке. Попробуйте позже.'];
        } finally {
            if ($prev) {
                Auth::setUser($prev);
            } else {
                try {
                    Auth::logout();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
