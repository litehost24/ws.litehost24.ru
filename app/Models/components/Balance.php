<?php

namespace App\Models\components;

use App\Models\Payment;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;

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

        return ($paymentAmountSum - $userSubsPriceSum);
    }
}
