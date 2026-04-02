<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PartnerPriceDefault;
use App\Models\ProjectSetting;
use App\Models\ReferralEarning;
use App\Models\ReferralPriceRule;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

class ReferralPricingService
{
    public const SERVICE_VPN = 'vpn';
    public const DEFAULT_PROJECT_CUT_PCT = 10;

    public function getServiceKey(Subscription $sub): ?string
    {
        return $sub->name === 'VPN' ? self::SERVICE_VPN : null;
    }

    public function getProjectCutPct(): int
    {
        $pct = ProjectSetting::getInt('referral_project_cut_pct', self::DEFAULT_PROJECT_CUT_PCT);
        if ($pct < 0) {
            return 0;
        }
        if ($pct > 100) {
            return 100;
        }
        return $pct;
    }

    public function getMarkupCents(int $referrerId, int $referralId, string $serviceKey): int
    {
        $row = ReferralPriceRule::query()
            ->where('referrer_id', $referrerId)
            ->where('referral_id', $referralId)
            ->where('service_key', $serviceKey)
            ->first();

        if (!$row) {
            $defaultRow = PartnerPriceDefault::query()
                ->where('referrer_id', $referrerId)
                ->where('service_key', $serviceKey)
                ->first();

            if (!$defaultRow) {
                return 0;
            }

            return max(0, (int) $defaultRow->markup_cents);
        }

        return max(0, (int) $row->markup_cents);
    }

    public function lockDefaultMarkupForReferral(User $referrer, User $referral, string $serviceKey): void
    {
        if ($serviceKey !== self::SERVICE_VPN) {
            return;
        }
        if (!in_array($referrer->role, ['partner', 'admin'], true)) {
            return;
        }

        $exists = ReferralPriceRule::query()
            ->where('referrer_id', (int) $referrer->id)
            ->where('referral_id', (int) $referral->id)
            ->where('service_key', $serviceKey)
            ->exists();
        if ($exists) {
            return;
        }

        $defaultRow = PartnerPriceDefault::query()
            ->where('referrer_id', (int) $referrer->id)
            ->where('service_key', $serviceKey)
            ->first();

        $markupCents = $defaultRow ? max(0, (int) $defaultRow->markup_cents) : 0;

        try {
            ReferralPriceRule::query()->create([
                'referrer_id' => (int) $referrer->id,
                'referral_id' => (int) $referral->id,
                'service_key' => $serviceKey,
                'markup_cents' => (int) $markupCents,
            ]);
        } catch (\Throwable) {
            // Ignore race-condition duplicates.
        }
    }

    public function getFinalPriceCents(Subscription $sub, ?User $referrer, User $referral, ?int $baseOverrideCents = null): int
    {
        $base = $baseOverrideCents !== null ? max(0, (int) $baseOverrideCents) : (int) $sub->price;
        $serviceKey = $this->getServiceKey($sub);
        if (!$serviceKey || !$referrer || !in_array($referrer->role, ['partner', 'admin'], true)) {
            return $base;
        }

        $markup = $this->getMarkupCents($referrer->id, $referral->id, $serviceKey);
        return $base + $markup;
    }

    public function applyEarning(UserSubscription $userSub, Subscription $sub, ?User $referrer, User $referral, ?int $baseOverrideCents = null): void
    {
        $serviceKey = $this->getServiceKey($sub);
        if (!$serviceKey || !$referrer || !in_array($referrer->role, ['partner', 'admin'], true)) {
            return;
        }

        $basePriceCents = $baseOverrideCents !== null ? max(0, (int) $baseOverrideCents) : (int) $sub->price;
        $markup = $this->getMarkupCents($referrer->id, $referral->id, $serviceKey);
        if ($markup <= 0) {
            return;
        }

        $projectCutPct = $this->getProjectCutPct();
        $projectCutCents = (int) floor($markup * $projectCutPct / 100);
        $partnerEarnCents = $markup - $projectCutCents;

        if (ReferralEarning::query()->where('user_subscription_id', $userSub->id)->exists()) {
            return;
        }

        DB::transaction(function () use (
            $userSub,
            $sub,
            $referrer,
            $referral,
            $serviceKey,
            $basePriceCents,
            $markup,
            $projectCutPct,
            $projectCutCents,
            $partnerEarnCents
        ) {
            ReferralEarning::create([
                'referrer_id' => (int) $referrer->id,
                'referral_id' => (int) $referral->id,
                'user_subscription_id' => (int) $userSub->id,
                'service_key' => $serviceKey,
                'base_price_cents' => $basePriceCents,
                'markup_cents' => (int) $markup,
                'project_cut_pct' => (int) $projectCutPct,
                'project_cut_cents' => (int) $projectCutCents,
                'partner_earn_cents' => (int) $partnerEarnCents,
            ]);

            if ($partnerEarnCents > 0) {
                Payment::create([
                    'user_id' => (int) $referrer->id,
                    'amount' => (int) $partnerEarnCents,
                    'order_name' => 'Referral bonus from user #' . (int) $referral->id . ' (sub #' . (int) $userSub->id . ')',
                    'type' => 'referral_bonus',
                ]);
            }
        });
    }
}
