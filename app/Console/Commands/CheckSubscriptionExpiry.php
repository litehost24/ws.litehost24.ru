<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiryNotification;
use App\Models\Server;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\components\Balance;
use App\Services\ReferralPricingService;
use App\Services\Telegram\TelegramBotService;
use App\Services\VpnPlanCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckSubscriptionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-expiry';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка окончания подписок и уведомление пользователей о необходимости пополнения баланса';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Запуск проверки окончания подписок...');

        // Получаем все активные подписки, которые заканчиваются в течение недели
        $weekFromNow = Carbon::now()->addWeek();
        $today = Carbon::now();

        $userSubscriptions = UserSubscription::where('end_date', '<=', $weekFromNow)
            ->where('end_date', '>', $today)
            ->with(['user', 'subscription'])
            ->get();

        $balanceComponent = new Balance();

        // Группируем подписки по пользователям
        $userSubscriptionsGrouped = [];
        foreach ($userSubscriptions as $userSubscription) {
            $balance = $balanceComponent->getBalance($userSubscription->user_id);
            $notice = $this->buildExpiryNotice($userSubscription, (int) $balance);
            if ($notice === null) {
                continue;
            }

            if (!isset($userSubscriptionsGrouped[$userSubscription->user_id])) {
                $userSubscriptionsGrouped[$userSubscription->user_id] = [
                    'user' => $userSubscription->user,
                    'balance' => $balance,
                    'subscriptions' => []
                ];
            }

            $userSubscriptionsGrouped[$userSubscription->user_id]['subscriptions'][] = $notice;
        }

        $notificationsSent = 0;

        // Отправляем по одному письму каждому пользователю с несколькими подписками
        foreach ($userSubscriptionsGrouped as $userData) {
            try {
                Mail::to($userData['user']->email)->send(new SubscriptionExpiryNotification(
                    $userData['user'],
                    $userData['balance'],
                    $userData['subscriptions']
                ));

                $notificationsSent++;

                $subscriptionNames = collect($userData['subscriptions'])->pluck('subscription.name')->implode(', ');
                $this->info("Отправлено уведомление пользователю {$userData['user']->name} (ID: {$userData['user']->id}) о подписках: {$subscriptionNames}");
            } catch (\Exception $e) {
                $this->error("Ошибка при отправке уведомления пользователю {$userData['user']->name} (ID: {$userData['user']->id}): " . $e->getMessage());
            }

            $this->notifyTelegramIfNeeded($userData['user'], $userData['subscriptions']);
        }

        $this->info("Проверка окончания подписок завершена. Отправлено уведомлений: {$notificationsSent}");
    }

    /**
     * @param array<int, array{subscription: mixed, days_until_expiry: int, end_date: mixed}> $subscriptions
     */
    private function notifyTelegramIfNeeded(User $user, array $subscriptions): void
    {
        $identity = TelegramIdentity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('telegram_chat_id')
            ->first();
        if (!$identity || !$identity->telegram_chat_id) {
            return;
        }

        $lastNotified = $user->last_expiry_telegram_notified_at;
        if ($lastNotified instanceof Carbon && $lastNotified->isSameDay(Carbon::now())) {
            return;
        }

        $hasLowBalance = collect($subscriptions)->contains(function (array $subInfo): bool {
            return in_array((string) ($subInfo['kind'] ?? ''), ['low_balance', 'legacy_next_plan_low_balance'], true);
        });
        $hasLegacySelection = collect($subscriptions)->contains(function (array $subInfo): bool {
            return (string) ($subInfo['kind'] ?? '') === 'legacy_choose_plan';
        });

        $lines = ['Подписка скоро заканчивается.'];
        foreach ($subscriptions as $subInfo) {
            $lines[] = $this->formatTelegramExpiryLine($subInfo);

            if (!empty($subInfo['needs_new_config'])) {
                $lines[] = 'После продления понадобится новая инструкция и новый конфиг.';
            }
        }

        if ($hasLowBalance) {
            $lines[] = 'Пополните баланс, чтобы избежать отключения.';
        }
        if ($hasLegacySelection) {
            $lines[] = 'Откройте личный кабинет и выберите новый тариф.';
        }

        try {
            app(TelegramBotService::class)->sendSystemMessage((int) $identity->telegram_chat_id, implode("\n", $lines));
            $user->forceFill([
                'last_expiry_telegram_notified_at' => Carbon::now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Telegram expiry notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildExpiryNotice(UserSubscription $userSubscription, int $balanceCents): ?array
    {
        $subscription = $userSubscription->subscription;
        if (!$subscription) {
            return null;
        }

        $daysUntilExpiry = abs(Carbon::parse($userSubscription->end_date)->diffInDays(Carbon::now(), false));
        $notice = [
            'subscription' => $subscription,
            'days_until_expiry' => $daysUntilExpiry,
            'end_date' => $userSubscription->end_date,
            'next_plan_label' => $userSubscription->nextVpnPlanLabel(),
            'needs_new_config' => $this->needsNewConfigForNextPlan($userSubscription),
        ];

        $isVpn = trim((string) $subscription->name) === 'VPN';
        $isLegacy = $isVpn && $userSubscription->isLegacyVpnPlan();
        $hasNextPlan = trim((string) ($userSubscription->next_vpn_plan_code ?? '')) !== '';

        if ($isLegacy && !$hasNextPlan) {
            return $notice + ['kind' => 'legacy_choose_plan'];
        }

        $upcomingPriceCents = $this->resolveUpcomingPriceCents($userSubscription);
        if ($isLegacy && $hasNextPlan) {
            if ($upcomingPriceCents > $balanceCents) {
                return $notice + [
                    'kind' => 'legacy_next_plan_low_balance',
                    'price_rub' => (int) round($upcomingPriceCents / 100),
                    'missing_rub' => (int) ceil(($upcomingPriceCents - $balanceCents) / 100),
                ];
            }

            return $notice + ['kind' => 'legacy_next_plan_ready'];
        }

        if ($userSubscription->is_rebilling && $upcomingPriceCents > $balanceCents) {
            return $notice + [
                'kind' => 'low_balance',
                'price_rub' => (int) round($upcomingPriceCents / 100),
                'missing_rub' => (int) ceil(($upcomingPriceCents - $balanceCents) / 100),
            ];
        }

        return null;
    }

    private function resolveUpcomingPriceCents(UserSubscription $row): int
    {
        $subscription = $row->subscription;
        if (!$subscription) {
            return (int) ($row->price ?? 0);
        }

        $basePrice = (int) $subscription->price;
        if (trim((string) $subscription->name) === 'VPN') {
            $planCode = trim((string) ($row->next_vpn_plan_code ?? ''));
            if ($planCode === '') {
                $planCode = trim((string) ($row->vpn_plan_code ?? ''));
            }

            $basePrice = app(VpnPlanCatalog::class)->resolveBasePriceCents($subscription, $planCode);
        }

        $user = $row->user ?: User::query()->find((int) $row->user_id);
        if (!$user) {
            return $basePrice;
        }

        return app(ReferralPricingService::class)->getFinalPriceCents($subscription, $user->referrer, $user, $basePrice);
    }

    private function needsNewConfigForNextPlan(UserSubscription $row): bool
    {
        $nextPlan = $row->nextVpnPlan();
        if ($nextPlan === null) {
            return false;
        }

        $currentMode = $row->resolveVpnAccessMode();
        $nextMode = Server::normalizeVpnAccessMode((string) ($nextPlan['vpn_access_mode'] ?? ''));

        return $currentMode !== null && $currentMode !== $nextMode;
    }

    /**
     * @param array<string, mixed> $subInfo
     */
    private function formatTelegramExpiryLine(array $subInfo): string
    {
        $subName = (string) ($subInfo['subscription']->name ?? 'Подписка');
        $endDate = Carbon::parse((string) $subInfo['end_date'])->format('d.m.Y');
        $daysLeft = (int) ($subInfo['days_until_expiry'] ?? 0);

        return match ((string) ($subInfo['kind'] ?? '')) {
            'legacy_choose_plan' => "{$subName}: до {$endDate} ({$daysLeft} дн.). Старый тариф больше не продлевается автоматически, выберите новый тариф.",
            'legacy_next_plan_ready' => "{$subName}: до {$endDate} ({$daysLeft} дн.). Затем — " . (string) ($subInfo['next_plan_label'] ?? 'новый тариф') . '.',
            'legacy_next_plan_low_balance' => "{$subName}: до {$endDate} ({$daysLeft} дн.). Затем — " . (string) ($subInfo['next_plan_label'] ?? 'новый тариф') . ". Цена {$subInfo['price_rub']} ₽, не хватает {$subInfo['missing_rub']} ₽.",
            'low_balance' => "{$subName}: до {$endDate} ({$daysLeft} дн.). Цена {$subInfo['price_rub']} ₽, не хватает {$subInfo['missing_rub']} ₽.",
            default => "{$subName}: до {$endDate} ({$daysLeft} дн.).",
        };
    }
}
