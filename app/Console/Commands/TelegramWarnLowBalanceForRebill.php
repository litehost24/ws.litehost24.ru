<?php

namespace App\Console\Commands;

use App\Models\components\Balance;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Payments\MonetaPaymentLinkService;
use App\Services\ReferralPricingService;
use App\Services\Telegram\TelegramApiClient;
use App\Services\VpnPlanCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TelegramWarnLowBalanceForRebill extends Command
{
    protected $signature = 'subscriptions:telegram-warn-low-balance {--days=3 : Warn N days before end_date}';

    protected $description = 'Notify users in Telegram if auto-renewal is enabled but balance is too low for upcoming renewal.';

    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly MonetaPaymentLinkService $payments,
        private readonly ReferralPricingService $pricing,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 3;
        }
        $days = max(1, min(30, $days));

        $tz = 'Europe/Moscow';
        $today = Carbon::now($tz)->startOfDay();
        $until = $today->copy()->addDays($days)->endOfDay();

        // Latest record per (user_id, subscription_id)
        $latestIds = UserSubscription::select(DB::raw('MAX(id)'))
            ->groupBy('user_id', 'subscription_id');

        $subs = UserSubscription::query()
            ->whereIn('id', $latestIds)
            ->where('is_rebilling', true)
            ->whereNotNull('end_date')
            ->where('end_date', '!=', UserSubscription::AWAIT_PAYMENT_DATE)
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $until->toDateString())
            ->with('subscription:id,name,price')
            ->get();

        if ($subs->isEmpty()) {
            return self::SUCCESS;
        }

        $byUser = $subs->groupBy('user_id');
        $userIds = $byUser->keys()->map(fn ($id) => (int) $id)->all();

        $identities = TelegramIdentity::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('telegram_chat_id')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('user_id');

        $balance = new Balance();
        $sent = 0;

        foreach ($byUser as $userId => $userSubs) {
            $userId = (int) $userId;
            $idList = $identities->get($userId);
            $identity = $idList ? $idList->first() : null;
            if (!$identity || (int) $identity->telegram_chat_id === 0) {
                continue;
            }

            // Dedup: max once per day (Moscow time)
            $last = $identity->last_rebill_warned_at;
            if ($last instanceof Carbon) {
                $lastMsk = $last->copy()->timezone($tz);
                if ($lastMsk->isSameDay($today)) {
                    continue;
                }
            }

            $user = User::query()->find($userId);
            if (!$user) {
                continue;
            }

            $balanceCents = (int) $balance->getBalance($userId);

            $risky = [];
            $sumPricesCents = 0;
            foreach ($userSubs as $row) {
                $priceCents = $this->resolveUpcomingPriceCents($row, $user);
                if ($priceCents <= 0) {
                    continue;
                }
                $sumPricesCents += $priceCents;
                if ($balanceCents < $priceCents) {
                    $missingCents = $priceCents - $balanceCents;
                    $risky[] = [
                        'name' => (string) ($row->subscription?->name ?? ('#' . (int) $row->subscription_id)),
                        'end_date' => (string) $row->end_date,
                        'price_rub' => (int) round($priceCents / 100),
                        'missing_rub' => (int) round($missingCents / 100),
                    ];
                }
            }

            if (count($risky) === 0) {
                continue;
            }

            $needCents = max(0, $sumPricesCents - $balanceCents);
            $needRub = (int) ceil($needCents / 100);

            $lines = [];
            $lines[] = "Баланс: " . (int) ($balanceCents / 100) . " ₽";
            $lines[] = "Автопродление включено, но средств не хватает:";
            foreach ($risky as $i => $r) {
                $n = $i + 1;
                $lines[] = "{$n}) {$r['name']}: до {$r['end_date']}, цена {$r['price_rub']} ₽, не хватает {$r['missing_rub']} ₽";
            }
            if ($needRub > 0) {
                $lines[] = "";
                $lines[] = "Рекомендуем пополнить минимум на {$needRub} ₽.";
            }

            if ($needRub >= 10 && $needRub <= 50000) {
                try {
                    $url = $this->payments->makeTopupLink($user, $needRub);
                    $lines[] = "Ссылка на пополнение: {$url}";
                } catch (\Throwable) {
                    // Ignore link errors; user can top up from bot menu.
                }
            }

            $lines[] = "";
            $lines[] = "Можно пополнить через кнопку «Пополнить» в боте.";

            $this->telegram->sendMessage((int) $identity->telegram_chat_id, implode("\n", $lines));
            $identity->forceFill(['last_rebill_warned_at' => Carbon::now()])->save();
            $sent++;
        }

        $this->info("Telegram low-balance warnings sent: {$sent}");
        return self::SUCCESS;
    }

    private function resolveUpcomingPriceCents(UserSubscription $row, User $user): int
    {
        $subscription = $row->subscription;
        if (!$subscription) {
            return (int) ($row->price ?? 0);
        }

        $referrer = $user->referrer;
        $basePrice = (int) $subscription->price;
        if (trim((string) $subscription->name) === 'VPN') {
            $planCode = trim((string) ($row->next_vpn_plan_code ?? ''));
            if ($planCode === '') {
                $planCode = trim((string) ($row->vpn_plan_code ?? ''));
            }

            $basePrice = app(VpnPlanCatalog::class)->resolveBasePriceCents($subscription, $planCode);
        }

        return $this->pricing->getFinalPriceCents($subscription, $referrer, $user, $basePrice);
    }
}
