<?php

namespace App\Services\Telegram;

use App\Models\components\Balance;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use App\Models\components\FullConnectSubscription;
use App\Services\ReferralPricingService;
use App\Services\VpnPlanCatalog;
use App\Services\VpnTopupCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class TelegramSubscriptionService
{
    public function getVpnPurchaseOptions(User $user): array
    {
        $prev = Auth::user();
        Auth::setUser($user);
        try {
            $vpnSub = Subscription::nextAvailableVpnForUser((int) $user->id);
            if (!$vpnSub) {
                return [];
            }

            $catalog = app(VpnPlanCatalog::class);
            $referrer = $user->referrer;

            return $catalog->purchaseOptions($vpnSub, $referrer, $user);
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

    public function scheduleLegacyNextVpnPlan(User $user, int $userSubscriptionId, string $planCode): array
    {
        if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
            return ['ok' => false, 'message' => 'Выбор тарифа недоступен.'];
        }

        $prev = Auth::user();
        Auth::setUser($user);

        try {
            $userSub = UserSubscription::query()
                ->where('id', $userSubscriptionId)
                ->where('user_id', $user->id)
                ->with('subscription:id,name')
                ->first();

            if (!$userSub) {
                return ['ok' => false, 'message' => 'Подписка не найдена.'];
            }

            $subscription = $userSub->subscription;
            if (!$subscription || trim((string) $subscription->name) !== 'VPN') {
                return ['ok' => false, 'message' => 'Выбор следующего тарифа доступен только для VPN-подписки.'];
            }

            if (!$userSub->isLegacyVpnPlan()) {
                return ['ok' => false, 'message' => 'Для нового тарифа выбор на следующий период не требуется.'];
            }

            $catalog = app(VpnPlanCatalog::class);
            $normalizedPlanCode = $catalog->normalizePlanCode($planCode);
            $plan = $catalog->find($normalizedPlanCode);

            if ($plan === null || !$catalog->isPurchasable($normalizedPlanCode)) {
                return ['ok' => false, 'message' => 'Тариф не найден.'];
            }

            $userSub->update([
                'next_vpn_plan_code' => $normalizedPlanCode,
                'is_rebilling' => true,
            ]);

            $message = sprintf(
                'Со следующего периода будет: %s. Текущий тариф продолжит работать до конца оплаченного периода.',
                (string) ($plan['label'] ?? $normalizedPlanCode)
            );

            if ($userSub->vpnPlanNeedsNewConfig($plan)) {
                $message .= ' В дату продления понадобится новая инструкция и новый конфиг. Старая настройка будет работать ещё '
                    . UserSubscription::NEXT_PLAN_CONFIG_GRACE_HOURS
                    . ' часа после продления.';
            }

            return [
                'ok' => true,
                'message' => $message,
                'user_subscription_id' => (int) $userSub->id,
            ];
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

    public function clearLegacyNextVpnPlan(User $user, int $userSubscriptionId): array
    {
        if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
            return ['ok' => false, 'message' => 'Выбор тарифа недоступен.'];
        }

        $prev = Auth::user();
        Auth::setUser($user);

        try {
            $userSub = UserSubscription::query()
                ->where('id', $userSubscriptionId)
                ->where('user_id', $user->id)
                ->with('subscription:id,name')
                ->first();

            if (!$userSub) {
                return ['ok' => false, 'message' => 'Подписка не найдена.'];
            }

            $subscription = $userSub->subscription;
            if (!$subscription || trim((string) $subscription->name) !== 'VPN' || !$userSub->isLegacyVpnPlan()) {
                return ['ok' => false, 'message' => 'Отмена следующего тарифа доступна только для старой VPN-подписки.'];
            }

            $userSub->update([
                'next_vpn_plan_code' => null,
                'is_rebilling' => false,
            ]);

            return [
                'ok' => true,
                'message' => 'Выбор следующего тарифа отменён. Подписка остановится в дату окончания.',
                'user_subscription_id' => (int) $userSub->id,
            ];
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

    public function purchaseTopup(User $user, int $userSubscriptionId, string $topupCode): array
    {
        if (!in_array($user->role, ['user', 'admin', 'partner'], true)) {
            return ['ok' => false, 'message' => 'Покупка пакета трафика недоступна.'];
        }

        if (!Schema::hasTable('user_subscription_topups')) {
            return ['ok' => false, 'message' => 'Покупка пакета трафика временно недоступна.'];
        }

        $prev = Auth::user();
        Auth::setUser($user);

        try {
            $userSub = UserSubscription::query()
                ->where('id', $userSubscriptionId)
                ->where('user_id', $user->id)
                ->with('subscription:id,name')
                ->first();

            if (!$userSub) {
                return ['ok' => false, 'message' => 'Подписка не найдена.'];
            }

            if (!$userSub->isLocallyActive()) {
                return ['ok' => false, 'message' => 'Пакет трафика можно добавить только к активной подписке.'];
            }

            if ($userSub->vpnTrafficLimitBytes() === null) {
                return ['ok' => false, 'message' => 'Для домашнего интернета докупка трафика не требуется.'];
            }

            $package = app(VpnTopupCatalog::class)->find($topupCode);
            if ($package === null) {
                return ['ok' => false, 'message' => 'Пакет трафика не найден.'];
            }

            $expiresOn = trim((string) ($userSub->end_date ?? ''));
            if ($expiresOn === '' || $expiresOn === UserSubscription::AWAIT_PAYMENT_DATE) {
                return ['ok' => false, 'message' => 'Не удалось определить срок действия пакета.'];
            }

            $userId = (int) $user->id;
            $topup = DB::transaction(function () use ($userId, $userSub, $package, $expiresOn) {
                DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first();

                if ((new Balance)->getBalance($userId) < (int) $package['price_cents']) {
                    return null;
                }

                return UserSubscriptionTopup::query()->create([
                    'user_subscription_id' => (int) $userSub->id,
                    'user_id' => $userId,
                    'topup_code' => (string) $package['code'],
                    'name' => (string) $package['label'],
                    'price' => (int) $package['price_cents'],
                    'traffic_bytes' => (int) $package['traffic_bytes'],
                    'expires_on' => Carbon::parse($expiresOn)->toDateString(),
                ]);
            });

            if (!$topup) {
                return ['ok' => false, 'message' => 'Недостаточно средств. Сначала пополните баланс.'];
            }

            return [
                'ok' => true,
                'message' => sprintf(
                    'Пакет %s добавлен до %s. Неиспользованный остаток на следующий период не переносится.',
                    (string) $package['label'],
                    Carbon::parse($expiresOn)->format('d.m.Y')
                ),
                'user_subscription_id' => (int) $userSub->id,
                'balance_rub' => (int) (new Balance)->getBalanceRub($user->id),
            ];
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

    /**
     * @return array{ok: bool, message?: string, balance_rub?: int, end_date?: string, file_url?: string, file_path?: string, user_subscription_id?: int}
     */
    public function buyVpn(User $user, ?string $note = null, ?string $planCode = null): array
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
            $planCode = $catalog->normalizePlanCode($planCode);
            if (!$catalog->isPurchasable($planCode)) {
                return ['ok' => false, 'message' => 'Тариф недоступен для новых подключений.'];
            }
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
