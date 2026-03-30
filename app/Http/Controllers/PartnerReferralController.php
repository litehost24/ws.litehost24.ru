<?php

namespace App\Http\Controllers;

use App\Models\ReferralPriceRule;
use App\Models\Subscription;
use App\Models\User;
use App\Models\PartnerPriceDefault;
use App\Services\ReferralPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PartnerReferralController extends Controller
{
    public function index(): View
    {
        $partner = Auth::user();
        if (!$partner || !in_array($partner->role, ['partner', 'admin'], true)) {
            abort(403);
        }

        $referrals = User::query()
            ->where('ref_user_id', (int) $partner->id)
            ->orderBy('id', 'desc')
            ->get(['id', 'name', 'email', 'created_at']);

        $rules = ReferralPriceRule::query()
            ->where('referrer_id', (int) $partner->id)
            ->where('service_key', ReferralPricingService::SERVICE_VPN)
            ->get()
            ->keyBy('referral_id');

        $default = PartnerPriceDefault::query()
            ->where('referrer_id', (int) $partner->id)
            ->where('service_key', ReferralPricingService::SERVICE_VPN)
            ->first();

        $vpnPrices = Subscription::query()
            ->where('name', 'VPN')
            ->pluck('price');

        $baseMin = $vpnPrices->isNotEmpty() ? (int) $vpnPrices->min() : null;
        $baseMax = $vpnPrices->isNotEmpty() ? (int) $vpnPrices->max() : null;

        return view('partner.referrals', [
            'referrals' => $referrals,
            'rules' => $rules,
            'defaultMarkupCents' => (int) ($default->markup_cents ?? 0),
            'baseMin' => $baseMin,
            'baseMax' => $baseMax,
        ]);
    }

    public function updateDefault(Request $request): RedirectResponse
    {
        $partner = Auth::user();
        if (!$partner || !in_array($partner->role, ['partner', 'admin'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'markup_rub' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $markupCents = (int) $data['markup_rub'] * 100;

        PartnerPriceDefault::query()->updateOrCreate(
            [
                'referrer_id' => (int) $partner->id,
                'service_key' => ReferralPricingService::SERVICE_VPN,
            ],
            [
                'markup_cents' => $markupCents,
            ]
        );

        return redirect()->back()->with('status', 'default_saved');
    }

    public function updatePricing(Request $request, User $referral): RedirectResponse
    {
        $partner = Auth::user();
        if (!$partner || !in_array($partner->role, ['partner', 'admin'], true)) {
            abort(403);
        }

        if ((int) $referral->ref_user_id !== (int) $partner->id) {
            abort(403);
        }

        $data = $request->validate([
            'markup_rub' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $markupCents = (int) $data['markup_rub'] * 100;

        ReferralPriceRule::query()->updateOrCreate(
            [
                'referrer_id' => (int) $partner->id,
                'referral_id' => (int) $referral->id,
                'service_key' => ReferralPricingService::SERVICE_VPN,
            ],
            [
                'markup_cents' => $markupCents,
            ]
        );

        return redirect()->back()->with('status', 'saved');
    }
}
