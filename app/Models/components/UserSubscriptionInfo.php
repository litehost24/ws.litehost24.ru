<?php
namespace App\Models\components;

use App\Services\VpnAgent\SubscriptionWireguardConfigResolver;
use App\Models\UserSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class UserSubscriptionInfo {

    private Collection $subList;
    private int $subscription_id;
    private ?int $user_subscription_id = null;
    private array $wireguardConfigCache = [];

    public function __construct(Collection $subList)
    {
        $this->subList = $subList;
    }

    public function setSubId(int $subscriptionId): static
    {
        $this->subscription_id = $subscriptionId;
        $this->user_subscription_id = null;
        return $this;
    }

    public function setUserSubscriptionId(int $userSubscriptionId): static
    {
        $this->user_subscription_id = $userSubscriptionId;
        $current = $this->subList->firstWhere('id', $userSubscriptionId);
        if ($current) {
            $this->subscription_id = (int) $current->subscription_id;
        }
        return $this;
    }

    public function isWasConnected(): bool
    {
        if ($this->user_subscription_id !== null) {
            return (bool) ($this->currentSub()->id ?? 0);
        }

        return UserSubscription::where('subscription_id', $this->subscription_id)
            ->where('user_id', auth()->id())
            ->exists();
    }

    public function isConnected(): bool
    {
        $current = $this->currentSub();
        // РџРѕРґРїРёСЃРєР° СЃС‡РёС‚Р°РµС‚СЃСЏ Р°РєС‚РёРІРЅРѕР№, РµСЃР»Рё РґР°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ РІ Р±СѓРґСѓС‰РµРј Рё РЅРµ СЏРІР»СЏРµС‚СЃСЏ СЃРїРµС†РёР°Р»СЊРЅРѕР№ РґР°С‚РѕР№ РѕР¶РёРґР°РЅРёСЏ РїР»Р°С‚РµР¶Р°
        return $current->subscription_id == $this->subscription_id &&
               $this->isDateAfterToday($current->end_date) &&
               $current->end_date !== UserSubscription::AWAIT_PAYMENT_DATE;
    }

    public function isProcessing(): bool
    {
        $current = $this->currentSub();
        $action = $current->action;
        $endDate = $current->end_date;

        // РќРµ СЃС‡РёС‚Р°РµРј СЃРѕСЃС‚РѕСЏРЅРёРµ РѕР¶РёРґР°РЅРёСЏ РѕРїР»Р°С‚С‹ РєР°Рє "РІ РѕР±СЂР°Р±РѕС‚РєРµ"
        if ($endDate === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        // РќРµ СЃС‡РёС‚Р°РµРј РёСЃС‚РµРєС€РёРµ РїРѕРґРїРёСЃРєРё СЃ is_processed = false РєР°Рє "РІ РѕР±СЂР°Р±РѕС‚РєРµ"
        if ($current->is_processed == false && $this->isDateOnOrBeforeToday($current->end_date)) {
            return false;
        }

        // РќРµ СЃС‡РёС‚Р°РµРј Р°РєС‚РёРІРЅС‹Рµ РїРѕРґРїРёСЃРєРё РєР°Рє "РІ РѕР±СЂР°Р±РѕС‚РєРµ"
        if ($this->isDateAfterToday($current->end_date)) {
            return false;
        }

        // "Р’ РѕР±СЂР°Р±РѕС‚РєРµ" - СЌС‚Рѕ РєРѕРіРґР° action = 'create' Рё is_processed = false (РЅРѕРІР°СЏ Р·Р°СЏРІРєР°)
        return $action == 'create' && $current->is_processed == false;
    }

    public function isRebillActive(): bool
    {
        $current = $this->currentSub();

        // РќРµ СЃС‡РёС‚Р°РµРј РїРѕРґРїРёСЃРєСѓ СЃ Р°РІС‚РѕРїСЂРѕРґР»РµРЅРёРµРј Р°РєС‚РёРІРЅРѕР№, РµСЃР»Рё РѕРЅР° РѕР¶РёРґР°РµС‚ РѕРїР»Р°С‚С‹
        if ($this->isAwaitingPayment()) {
            return false;
        }

        // РџРѕРґРїРёСЃРєР° СЃ Р°РІС‚РѕРїСЂРѕРґР»РµРЅРёРµРј Р°РєС‚РёРІРЅР°, РµСЃР»Рё is_rebilling = true Рё РґР°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ РІ Р±СѓРґСѓС‰РµРј Рё РЅРµ СЏРІР»СЏРµС‚СЃСЏ СЃРїРµС†РёР°Р»СЊРЅРѕР№ РґР°С‚РѕР№ РѕР¶РёРґР°РЅРёСЏ РїР»Р°С‚РµР¶Р°
        return !!$current->is_rebilling && $this->isDateAfterToday($current->end_date) &&
               $current->end_date !== UserSubscription::AWAIT_PAYMENT_DATE;
    }

    public function isRebillEnabled(): bool
    {
        $current = $this->currentSub();

        // РђРІС‚РѕРїСЂРѕРґР»РµРЅРёРµ РІРєР»СЋС‡РµРЅРѕ, РµСЃР»Рё is_rebilling = true (РЅРµР·Р°РІРёСЃРёРјРѕ РѕС‚ РґР°С‚С‹ РѕРєРѕРЅС‡Р°РЅРёСЏ)
        return !!$current->is_rebilling;
    }

    public function isRebillExpiringSoon(int $days = 7): bool
    {
        $current = $this->currentSub();

        if (!$this->isRebillActive()) {
            return false;
        }

        if ($current->end_date === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        $endDate = Carbon::parse($current->end_date)->startOfDay();
        return $endDate->lte(Carbon::today()->addDays($days)->startOfDay());
    }

    public function isExpiringSoon(int $days = 7): bool
    {
        $current = $this->currentSub();

        if ($current->end_date === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if (!$this->isDateAfterToday($current->end_date)) {
            return false;
        }

        $endDate = Carbon::parse($current->end_date)->startOfDay();
        return $endDate->lte(Carbon::today()->addDays($days)->startOfDay());
    }

    public function isExpired(): bool
    {
        $current = $this->currentSub();
        $endDate = $current->end_date;

        // Р•СЃР»Рё РґР°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ - СЃРїРµС†РёР°Р»СЊРЅР°СЏ РґР°С‚Р° РѕР¶РёРґР°РЅРёСЏ РїР»Р°С‚РµР¶Р°, С‚Рѕ РїРѕРґРїРёСЃРєР° РЅРµ СЃС‡РёС‚Р°РµС‚СЃСЏ РїСЂРѕСЃСЂРѕС‡РµРЅРЅРѕР№
        if ($endDate === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        // Р•СЃР»Рё РїРѕРґРїРёСЃРєР° РѕР¶РёРґР°РµС‚ РѕРїР»Р°С‚С‹ (is_processed = false Рё is_rebilling = true), С‚Рѕ РѕРЅР° РЅРµ СЃС‡РёС‚Р°РµС‚СЃСЏ РїСЂРѕСЃСЂРѕС‡РµРЅРЅРѕР№
        if ($current->is_processed == false && $current->is_rebilling == true && $this->isDateOnOrBeforeToday($endDate)) {
            return false;
        }

        return $this->isDateOnOrBeforeToday($endDate);
    }

    public function getEndDate(): string
    {
        $rawDate = $this->currentSub()->end_date;

        // Р•СЃР»Рё РґР°С‚Р° - СЃРїРµС†РёР°Р»СЊРЅР°СЏ РґР°С‚Р° РѕР¶РёРґР°РЅРёСЏ РїР»Р°С‚РµР¶Р°, РІРѕР·РІСЂР°С‰Р°РµРј СЃРїРµС†РёР°Р»СЊРЅРѕРµ СЃРѕРѕР±С‰РµРЅРёРµ
        if ($rawDate === UserSubscription::AWAIT_PAYMENT_DATE) {
            return 'РѕР¶РёРґР°РЅРёРµ РѕРїР»Р°С‚С‹';
        }

        return (new Carbon($rawDate))->format('d.m.Y');
    }

    public function isAwaitingPayment(): bool
    {
        // РџРѕРґРїРёСЃРєР° РѕР¶РёРґР°РµС‚ РѕРїР»Р°С‚С‹, РµСЃР»Рё is_processed = false, РґР°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ РёСЃС‚РµРєР»Р° Рё Р±С‹Р»Р° Р°РІС‚РѕРїСЂРѕРґР»РµРЅРёРµ
        // РР›Р РґР°С‚Р° РѕРєРѕРЅС‡Р°РЅРёСЏ СЂР°РІРЅР° СЃРїРµС†РёР°Р»СЊРЅРѕР№ РґР°С‚Рµ РѕР¶РёРґР°РЅРёСЏ РїР»Р°С‚РµР¶Р°
        $current = $this->currentSub();
        return $current->is_processed == false &&
               (
                   ($this->isDateOnOrBeforeToday($current->end_date) && $current->is_rebilling == true) ||
                   $current->end_date === UserSubscription::AWAIT_PAYMENT_DATE
               );
    }


    private function isDateAfterToday(?string $date): bool
    {
        if (empty($date) || $date === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        return Carbon::parse($date)->toDateString() > Carbon::today()->toDateString();
    }

    private function isDateOnOrBeforeToday(?string $date): bool
    {
        if (empty($date) || $date === UserSubscription::AWAIT_PAYMENT_DATE) {
            return false;
        }

        return Carbon::parse($date)->toDateString() <= Carbon::today()->toDateString();
    }
    public function getFileUrl(): string
    {
        $path = $this->currentSub()->file_path;
        if (empty($path)) {
            return '';
        }

        // Serve downloads via controller to avoid web-server symlink/static-file issues.
        return route('user-subscription.download', [
            'subscription_id' => $this->subscription_id,
            'user_subscription_id' => (int) ($this->currentSub()->id ?? 0),
        ], false);
    }

    public function getConnectionConfig(): string
    {
        return $this->currentSub()->connection_config ?? '';
    }

    public function getWireguardConfig(): string
    {
        $current = $this->currentSub();
        if (!$current->id || empty($current->file_path)) {
            return '';
        }

        $cacheKey = (int) $current->id;
        if (array_key_exists($cacheKey, $this->wireguardConfigCache)) {
            return $this->wireguardConfigCache[$cacheKey];
        }

        return $this->wireguardConfigCache[$cacheKey] = app(SubscriptionWireguardConfigResolver::class)->resolve($current);
    }

    public function getWireguardQrDataUri(): string
    {
        $config = $this->getWireguardConfig();
        if ($config === '') {
            return '';
        }

        return WireguardQrCode::makeDataUri($config) ?? '';
    }

    public function getNote(): string
    {
        return $this->currentSub()->note ?? '';
    }

    private function currentSub(): UserSubscription
    {
        if ($this->user_subscription_id !== null) {
            foreach ($this->subList as $subscription) {
                if ((int) $subscription->id === $this->user_subscription_id) {
                    return $subscription;
                }
            }
        }

        foreach ($this->subList as $subscription) {
            if ($subscription->subscription_id == $this->subscription_id) {
                return $subscription;
            }
        }

        return new UserSubscription();
    }
}

