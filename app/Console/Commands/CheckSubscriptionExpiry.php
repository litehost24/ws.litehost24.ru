<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiryNotification;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\components\Balance;
use App\Services\Telegram\TelegramBotService;
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

        // Группируем подписки по пользователям
        $userSubscriptionsGrouped = [];
        foreach ($userSubscriptions as $userSubscription) {
            // Проверяем баланс пользователя
            $balanceComponent = new Balance();
            $balance = $balanceComponent->getBalance($userSubscription->user_id);

            // Если баланс 0 или меньше, добавляем подписку в список для уведомления
            if ($balance <= 0) {
                if (!isset($userSubscriptionsGrouped[$userSubscription->user_id])) {
                    $userSubscriptionsGrouped[$userSubscription->user_id] = [
                        'user' => $userSubscription->user,
                        'balance' => $balance,
                        'subscriptions' => []
                    ];
                }

                // Вычисляем количество дней до окончания подписки
                $daysUntilExpiry = Carbon::parse($userSubscription->end_date)->diffInDays(Carbon::now(), false);

                $userSubscriptionsGrouped[$userSubscription->user_id]['subscriptions'][] = [
                    'subscription' => $userSubscription->subscription,
                    'days_until_expiry' => abs($daysUntilExpiry),
                    'end_date' => $userSubscription->end_date
                ];
            }
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

        $lines = [
            'Подписка скоро заканчивается.',
        ];
        foreach ($subscriptions as $subInfo) {
            $subName = (string) ($subInfo['subscription']->name ?? 'Подписка');
            $endDate = Carbon::parse($subInfo['end_date'])->format('d.m.Y');
            $daysLeft = (int) ($subInfo['days_until_expiry'] ?? 0);
            $lines[] = "{$subName}: до {$endDate} ({$daysLeft} дн.)";
        }
        $lines[] = 'Пополните баланс, чтобы избежать отключения.';

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
}
