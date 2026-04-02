<?php

namespace App\Models\components;

use App\Models\Payment;
use App\Models\UserSubscription;
use App\Models\UserSubscriptionTopup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class Balance
{
    public function getBalanceRub(int $user_id = null): int
    {
        return (int) ($this->getBalance($user_id) / 100);
    }

    public function getBalance(int $user_id = null): int
    {
        if ($user_id === null) {
            $auth = Auth::user();
            if (!$auth) {
                // Outside of a web request (e.g. telegram webhook) we must be explicit.
                return 0;
            }
            $user_id = (int) $auth->id;
        }
        $paymentAmountSum = Payment::where('user_id', $user_id)->sum('amount');
        $userSubsPriceSum = UserSubscription::where('user_id', $user_id)->sum('price');
        $topupPriceSum = Schema::hasTable('user_subscription_topups')
            ? UserSubscriptionTopup::where('user_id', $user_id)->sum('price')
            : 0;

        return ($paymentAmountSum - $userSubsPriceSum - $topupPriceSum);
    }
}
