<?php

namespace App\Http\Controllers;

use App\Models\components\Balance;
use App\Models\components\UserSubscriptionInfo;
use App\Models\Payment;
use App\Models\Publication;
use App\Models\ProjectSetting;
use App\Models\Subscription;
use App\Models\TelegramIdentity;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VpnPeerTrafficDaily;
use App\Services\ReferralPricingService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MyController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    public function main(): View
    {
        $balance = (new Balance)->getBalanceRub();
        $subListForInfo = collect();

        $nextVpnPriceRub = null;
        if (in_array(Auth::user()->role, ['user', 'admin', 'partner'], true)) {
            $userId = (int) Auth::id();
            $subListForInfo = UserSubscription::getCabinetList($userId);
            UserSubscription::attachTrafficTotals($subListForInfo);

            $subs = $subListForInfo
                ->map(fn ($item) => $item->subscription)
                ->filter()
                ->values();
            $vpnSub = Subscription::nextAvailableVpnForUser($userId);
            if ($vpnSub) {
                $pricing = app(ReferralPricingService::class);
                $referral = Auth::user();
                $referrer = $referral?->referrer;
                $finalPrice = $pricing->getFinalPriceCents($vpnSub, $referrer, $referral);
                $nextVpnPriceRub = (int) ($finalPrice / 100);
            }
        } else {
            $subs = Subscription::where('is_hidden', 0)->get();
            $subListForInfo = UserSubscription::getCabinetList((int) Auth::id());
            UserSubscription::attachTrafficTotals($subListForInfo);
        }
        $userSubInfo = new UserSubscriptionInfo($subListForInfo);
        $latestBySub = UserSubscription::query()
            ->where('user_id', (int) Auth::id())
            ->select(DB::raw('MAX(id)'))
            ->groupBy('subscription_id');

        $hasActiveSubscription = UserSubscription::query()
            ->whereIn('id', $latestBySub)
            ->where('is_processed', true)
            ->whereDate('end_date', '>', Carbon::today()->toDateString())
            ->where('end_date', '!=', UserSubscription::AWAIT_PAYMENT_DATE)
            ->exists();

        $activePublications = collect();
        $cabinetPublicationsEnabled = ProjectSetting::getInt('cabinet_publications_enabled', 1) === 1;
        if ($cabinetPublicationsEnabled && $hasActiveSubscription && Schema::hasTable('publications')) {
            $activePublications = Publication::query()
                ->where('audience', 'active')
                ->whereIn('status', ['published', 'sent', 'partial'])
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'subject', 'body', 'created_at']);
        }

        $currentPublication = $activePublications->first();
        $previousPublications = $activePublications->slice(1)->values();
        $hasTelegram = TelegramIdentity::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('telegram_chat_id')
            ->exists();

        return view('payment.show', [
            'balance' => $balance,
            'subs' => $subs,
            'subListForInfo' => $subListForInfo,
            'subInfo' => $userSubInfo,
            'currentPublication' => $currentPublication,
            'previousPublications' => $previousPublications,
            'nextVpnPriceRub' => $nextVpnPriceRub,
            'hasTelegram' => $hasTelegram,
        ]);
    }

    public function operations(): View
    {
        $userId = Auth::id();
        $paymentsHistory = Payment::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get(['id', 'amount', 'order_name', 'created_at']);

        $chargesHistory = UserSubscription::where('user_id', $userId)
            ->with('subscription:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get([
                'id',
                'subscription_id',
                'price',
                'action',
                'is_processed',
                'is_rebilling',
                'end_date',
                'created_at',
            ]);

        $totalPayments = (int) Payment::where('user_id', $userId)->sum('amount');
        $totalCharges = (int) UserSubscription::where('user_id', $userId)->sum('price');
        $balanceCents = $totalPayments - $totalCharges;

        return view('payment.operations', [
            'paymentsHistory' => $paymentsHistory,
            'chargesHistory' => $chargesHistory,
            'operationsSummary' => [
                'total_payments' => $totalPayments,
                'total_charges' => $totalCharges,
                'balance' => $balanceCents,
            ],
        ]);
    }

    public function referrals(): View
    {
        $user = Auth::user();
        if (!$user || !$user->isUser()) {
            abort(403);
        }

        $referrals = User::query()
            ->where('ref_user_id', (int) $user->id)
            ->orderBy('id', 'desc')
            ->get(['id', 'name', 'email', 'created_at']);

        $referralIds = $referrals->pluck('id')->all();
        $activeCounts = [];
        $trafficByUser = collect();
        $balanceByUser = [];

        if (!empty($referralIds)) {
            $today = Carbon::today()->toDateString();

            $connectedSubs = UserSubscription::query()
                ->whereIn('user_id', $referralIds)
                ->where(function ($query) use ($today) {
                    $query->whereDate('end_date', '>', $today)
                        ->orWhere(function ($q) use ($today) {
                            $q->whereDate('end_date', '<=', $today)
                                ->where('is_processed', false)
                                ->where('is_rebilling', true);
                        })
                        ->orWhere('end_date', UserSubscription::AWAIT_PAYMENT_DATE);
                })
                ->orderBy('id', 'desc')
                ->get(['id', 'user_id', 'subscription_id', 'end_date', 'is_processed', 'is_rebilling']);

            $seen = [];
            foreach ($connectedSubs as $sub) {
                $key = $sub->user_id . '_' . $sub->subscription_id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $activeCounts[$sub->user_id] = ($activeCounts[$sub->user_id] ?? 0) + 1;
            }

            $trafficByUser = VpnPeerTrafficDaily::query()
                ->select('user_id', DB::raw('SUM(total_bytes_delta) as total_bytes'))
                ->whereIn('user_id', $referralIds)
                ->groupBy('user_id')
                ->pluck('total_bytes', 'user_id');

            $paymentsByUser = Payment::query()
                ->select('user_id', DB::raw('SUM(amount) as total_amount'))
                ->whereIn('user_id', $referralIds)
                ->groupBy('user_id')
                ->pluck('total_amount', 'user_id');

            $chargesByUser = UserSubscription::query()
                ->select('user_id', DB::raw('SUM(price) as total_price'))
                ->whereIn('user_id', $referralIds)
                ->groupBy('user_id')
                ->pluck('total_price', 'user_id');

            foreach ($referralIds as $referralId) {
                $payments = (int) ($paymentsByUser[$referralId] ?? 0);
                $charges = (int) ($chargesByUser[$referralId] ?? 0);
                $balanceByUser[$referralId] = $payments - $charges;
            }
        }

        return view('my.referrals', [
            'referrals' => $referrals,
            'activeCounts' => $activeCounts,
            'trafficByUser' => $trafficByUser,
            'balanceByUser' => $balanceByUser,
        ]);
    }
}
