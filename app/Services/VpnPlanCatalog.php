<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;

class VpnPlanCatalog
{
    public function all(): array
    {
        $plans = config('vpn_plans.plans', []);

        return is_array($plans) ? $plans : [];
    }

    public function defaultPurchasePlanCode(): string
    {
        $default = (string) config('vpn_plans.default_purchase_plan', '');
        if ($default !== '' && $this->find($default) !== null) {
            return $default;
        }

        return array_key_first($this->all()) ?: 'regular_basic';
    }

    public function normalizePlanCode(?string $planCode): string
    {
        $planCode = trim((string) $planCode);

        return $this->find($planCode) !== null
            ? $planCode
            : $this->defaultPurchasePlanCode();
    }

    public function defaultRestrictedPlanCode(): string
    {
        $default = $this->find($this->defaultPurchasePlanCode());
        if ($default !== null && (string) $default['vpn_access_mode'] === Server::VPN_ACCESS_WHITE_IP) {
            return (string) $default['code'];
        }

        foreach (array_keys($this->all()) as $code) {
            $plan = $this->find((string) $code);
            if ($plan !== null && (string) $plan['vpn_access_mode'] === Server::VPN_ACCESS_WHITE_IP) {
                return (string) $plan['code'];
            }
        }

        return $this->defaultPurchasePlanCode();
    }

    public function defaultRegularPlanCode(): string
    {
        foreach (array_keys($this->all()) as $code) {
            $plan = $this->find((string) $code);
            if ($plan !== null && (string) $plan['vpn_access_mode'] === Server::VPN_ACCESS_REGULAR) {
                return (string) $plan['code'];
            }
        }

        return $this->defaultPurchasePlanCode();
    }

    public function find(?string $planCode): ?array
    {
        $planCode = trim((string) $planCode);
        if ($planCode === '') {
            return null;
        }

        $plans = $this->all();
        $plan = $plans[$planCode] ?? null;
        if (!is_array($plan)) {
            return null;
        }

        $mode = Server::normalizeVpnAccessMode((string) ($plan['vpn_access_mode'] ?? Server::VPN_ACCESS_REGULAR));
        $basePriceCents = max(0, (int) ($plan['base_price_cents'] ?? 0));
        $trafficLimitBytes = $plan['traffic_limit_bytes'] ?? null;
        $trafficLimitBytes = $trafficLimitBytes === null ? null : max(0, (int) $trafficLimitBytes);

        return [
            'code' => $planCode,
            'label' => (string) ($plan['label'] ?? $planCode),
            'short_label' => (string) ($plan['short_label'] ?? ($plan['label'] ?? $planCode)),
            'description' => (string) ($plan['description'] ?? ''),
            'purchasable' => (bool) ($plan['purchasable'] ?? true),
            'traffic_label' => trim((string) ($plan['traffic_label'] ?? '')) !== ''
                ? (string) $plan['traffic_label']
                : null,
            'vpn_access_mode' => $mode,
            'base_price_cents' => $basePriceCents,
            'traffic_limit_bytes' => $trafficLimitBytes,
        ];
    }

    public function isPurchasable(?string $planCode): bool
    {
        $plan = $this->find($planCode);

        return $plan !== null && (bool) ($plan['purchasable'] ?? true);
    }

    public function resolveBasePriceCents(Subscription $subscription, ?string $planCode): int
    {
        if (trim((string) $subscription->name) !== 'VPN') {
            return (int) $subscription->price;
        }

        $plan = $this->find($planCode);
        if ($plan === null) {
            return (int) $subscription->price;
        }

        return (int) $plan['base_price_cents'];
    }

    public function purchaseOptions(Subscription $subscription, ?User $referrer, User $referral): array
    {
        if (trim((string) $subscription->name) !== 'VPN') {
            return [];
        }

        $pricing = app(ReferralPricingService::class);
        $result = [];

        foreach ($this->all() as $code => $_plan) {
            $plan = $this->find((string) $code);
            if ($plan === null || !(bool) ($plan['purchasable'] ?? true)) {
                continue;
            }

            $basePriceCents = (int) $plan['base_price_cents'];
            $finalPriceCents = $pricing->getFinalPriceCents($subscription, $referrer, $referral, $basePriceCents);

            $result[] = array_merge($plan, [
                'final_price_cents' => $finalPriceCents,
                'final_price_rub' => (int) ($finalPriceCents / 100),
                'traffic_limit_gb' => $plan['traffic_limit_bytes'] !== null
                    ? round(((int) $plan['traffic_limit_bytes']) / 1073741824, 0)
                    : null,
            ]);
        }

        return $result;
    }

    public function snapshot(?string $planCode): ?array
    {
        $plan = $this->find($planCode);
        if ($plan === null) {
            return null;
        }

        return [
            'vpn_plan_code' => (string) $plan['code'],
            'vpn_plan_name' => (string) $plan['label'],
            'vpn_traffic_limit_bytes' => $plan['traffic_limit_bytes'] !== null
                ? (int) $plan['traffic_limit_bytes']
                : null,
            'vpn_access_mode' => (string) $plan['vpn_access_mode'],
        ];
    }

    public function allowsRestrictedMode(?string $planCode): bool
    {
        $plan = $this->find($planCode);
        if ($plan === null) {
            return true;
        }

        return (string) $plan['vpn_access_mode'] === Server::VPN_ACCESS_WHITE_IP;
    }

    public function displayLabelForUserSubscription(UserSubscription $userSubscription): ?string
    {
        $stored = trim((string) ($userSubscription->vpn_plan_name ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $plan = $this->find((string) ($userSubscription->vpn_plan_code ?? ''));
        if ($plan !== null) {
            return (string) $plan['label'];
        }

        return null;
    }
}
